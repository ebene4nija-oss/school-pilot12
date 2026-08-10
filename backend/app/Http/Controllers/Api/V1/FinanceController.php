<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FinanceController extends Controller
{
    /*
     * Fee-structure CRUD moved to FeeStructureController — this class was only
     * ever able to create one, with unscoped `exists:terms,id` validation that
     * let a school hang a fee off another tenant's term.
     */

    /**
     * Open a hosted checkout on the school's own merchant account (gap G8).
     *
     * The client is handed a URL and nothing else. It never sees a key, never
     * decides an amount, and never invents a reference — all three used to be
     * the client's job by omission, which is why none of them could be done
     * safely and why no shipped client ever managed to take a payment at all.
     *
     * Nothing here marks anything paid. The pending row is settled only by
     * `handleWebhook` below, against the signature of the school's own account.
     */
    public function initializePayment(Request $request, PaymentGatewayService $gateways)
    {
        $validator = Validator::make($request->all(), [
            'invoice_id' => 'required|exists:invoices,id',
            'gateway' => 'required|in:paystack,flutterwave',
            // Optional: part-payment. Absent means "settle the balance", which
            // is what a parent tapping Pay on a statement means.
            'amount' => 'nullable|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $invoice = Invoice::where('school_id', $schoolId)->with('student')->findOrFail($request->invoice_id);

        /*
         * Whose bill this is. School membership is not authorization: without
         * this any parent in the school could open a checkout against any
         * child's invoice and read its balance back in the response.
         */
        if ($invoice->student) {
            $this->authorize('view', $invoice->student);
        }

        $balance = $invoice->balance();

        if ($balance <= 0) {
            return response()->json(['message' => 'This invoice is already settled.'], 409);
        }

        $amount = $request->filled('amount') ? (float) $request->input('amount') : $balance;

        if ($amount > $balance) {
            return response()->json([
                'errors' => ['amount' => ["Only ₦" . number_format($balance, 2) . ' is outstanding on this invoice.']],
            ], 422);
        }

        $credentials = $gateways->usableCredentialsFor((int) $schoolId, $request->gateway);

        if (! $credentials) {
            return response()->json([
                'message' => 'This school has not connected its ' . $request->gateway . ' account yet, so online payment is not available. Please pay at the school or by bank transfer.',
            ], 409);
        }

        $reference = PaymentGatewayService::reference('SPFP');

        /*
         * The gateway is called before the row is written. A pending payment
         * that no checkout was ever opened for is a row the bursar has to
         * explain, and there is nothing to reconcile it against.
         */
        try {
            $checkout = $gateways->initializeCheckout($credentials, [
                'reference' => $reference,
                'amount' => $amount,
                'email' => $user->email,
                'name' => $user->name,
                'title' => 'School fees',
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'student_id' => $invoice->student_id,
                    'school_id' => $schoolId,
                ],
            ]);
        } catch (PaymentGatewayException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $payment = Payment::create([
            'school_id' => $schoolId,
            'invoice_id' => $invoice->id,
            'reference' => $reference,
            'amount' => $amount,
            'gateway' => $request->gateway,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Checkout ready. The payment is confirmed only once the gateway calls back.',
            'authorization_url' => $checkout['authorization_url'],
            'payment' => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'amount' => $payment->amount,
                'currency' => 'NGN',
                'gateway' => $payment->gateway,
                'status' => $payment->status,
            ],
        ], 201);
    }

    public function recordPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:1',
            'gateway' => 'required|in:paystack,flutterwave,bank_transfer,cash',
            /*
             * No longer required, and no longer the client's to choose (gap G9).
             *
             * A client minting its own reference meant a retry over a dropped
             * connection arrived with a fresh one and created a second pending
             * row against the same money. The only caller with a reference
             * worth keeping is an admin transcribing a real-world one — a bank
             * teller's slip number — so that is the only case still honoured.
             */
            'reference' => 'nullable|string|max:100|unique:payments,reference',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $invoice = Invoice::where('school_id', $schoolId)->findOrFail($request->invoice_id);

        // Direct manual payment (cash/bank transfer by admin) vs gateway payment verification
        $isManual = in_array($request->gateway, ['cash', 'bank_transfer']);
        $isAdmin = $user->userProfile && in_array($user->userProfile->role, ['super_admin', 'school_admin']);

        // Non-admins cannot self-mark online payments as successful without gateway verification
        $status = ($isManual && $isAdmin) ? 'successful' : 'pending';

        $reference = ($isManual && $isAdmin && $request->filled('reference'))
            ? $request->reference
            : PaymentGatewayService::reference($isManual ? 'SPMP' : 'SPFP');

        $payment = Payment::create([
            'school_id' => $schoolId,
            'invoice_id' => $invoice->id,
            'reference' => $reference,
            'amount' => $request->amount,
            'gateway' => $request->gateway,
            'status' => $status,
            'paid_at' => $status === 'successful' ? now() : null,
        ]);

        if ($status === 'successful') {
            $invoice->amount_paid += $request->amount;
            if ($invoice->amount_paid >= $invoice->total_amount) {
                $invoice->status = 'paid';
            } else {
                $invoice->status = 'partial';
            }
            $invoice->save();
        }

        // Dispatch automated WhatsApp notification receipt to parent if successful
        $notificationResult = null;
        if ($status === 'successful') {
            $whatsAppService = app(\App\Services\WhatsAppService::class);
            $notificationResult = $whatsAppService->sendPaymentReceiptNotification(
                $request->get('phone', '+2348000000000'),
                $user->name,
                number_format($request->amount, 2),
                $payment->reference
            );
        }

        return response()->json([
            'message' => $status === 'successful' ? 'Payment recorded successfully' : 'Payment initiated. Awaiting gateway webhook confirmation.',
            'payment' => $payment,
            'invoice' => $invoice,
            'whatsapp_notification' => $notificationResult,
        ]);
    }

    public function handleWebhook(Request $request, $gateway, PaymentGatewayService $gateways)
    {
        $payload = $request->all();
        $reference = null;
        $amountPaid = 0;

        /*
         * Which school's key must have signed this (gap G8).
         *
         * Now that each school holds its own merchant account, "the" gateway
         * secret no longer exists — the right one depends on whose money this
         * is. The reference is read out of the still-unverified payload for
         * this purpose only: it names the row about to be settled, so it
         * selects that row's school's key. A payload naming someone else's
         * reference simply gets checked against someone else's key and fails.
         *
         * Deliberately not taken from the request host. A school admin can
         * reach their own subdomain, and resolving by host would let them have
         * their own secret accepted as the signature on another school's
         * payment.
         *
         * Null means no school owns the reference — an unknown one, or a school
         * buying PIN stock from SchoolPilot, which really does settle on the
         * platform account. The platform key is also the fallback for schools
         * that have not connected an account of their own, so every deployment
         * predating this keeps working unchanged.
         */
        $payingSchoolId = $gateways->schoolIdForReference(
            $gateways->referenceFromPayload($gateway, $payload)
        );

        $secret = $gateways->webhookSigningSecret($gateway, $payingSchoolId);

        // 1. Paystack Webhook Cryptographic Verification (HMAC-SHA512)
        if ($gateway === 'paystack') {
            $paystackHeader = $request->header('x-paystack-signature');

            if (!is_string($secret) || trim($secret) === '') {
                Log::error('Paystack webhook rejected: no secret key configured for this payment.', [
                    'school_id' => $payingSchoolId,
                ]);

                return response()->json(['message' => 'Payment gateway is not configured.'], 503);
            }

            if (!$paystackHeader || !hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $paystackHeader)) {
                return response()->json(['message' => 'Invalid Paystack webhook signature'], 400);
            }

            if (($payload['event'] ?? '') === 'charge.success') {
                $reference = $payload['data']['reference'] ?? null;
                $amountPaid = ($payload['data']['amount'] ?? 0) / 100; // Paystack is in kobo
            }
        }

        // 2. Flutterwave Webhook Secret Hash Verification
        if ($gateway === 'flutterwave') {
            $flutterwaveHeader = $request->header('verif-hash');

            // The school's own dashboard hash where it has connected an
            // account, the platform's where it has not.
            $secretHash = $secret;

            if (!is_string($secretHash) || trim($secretHash) === '') {
                Log::error('Flutterwave webhook rejected: no secret hash configured for this payment.', [
                    'school_id' => $payingSchoolId,
                ]);

                return response()->json(['message' => 'Payment gateway is not configured.'], 503);
            }

            if (!$flutterwaveHeader || !hash_equals($secretHash, $flutterwaveHeader)) {
                return response()->json(['message' => 'Invalid Flutterwave webhook signature'], 400);
            }

            if (($payload['status'] ?? '') === 'successful' || ($payload['data']['status'] ?? '') === 'successful') {
                $reference = $payload['data']['tx_ref'] ?? $payload['txRef'] ?? null;
                $amountPaid = $payload['data']['amount'] ?? $payload['amount'] ?? 0;
            }
        }

        // 3. Process Trusted Payment & Invoice Update
        if ($reference) {
            /*
             * Deliberately cross-tenant, and now says so.
             *
             * A gateway callback carries only its own reference — the school is
             * what we are trying to discover, so this lookup cannot be scoped.
             * It used to work by accident, because the webhook is
             * unauthenticated and the old scope simply did not apply without a
             * logged-in user. `allTenants()` makes the boundary crossing
             * visible at the call site instead of implied by its absence.
             */
            $payment = Payment::allTenants()->where('reference', $reference)->first();

            if ($payment && $payment->status !== 'successful') {
                $payment->update([
                    'status' => 'successful',
                    'paid_at' => now(),
                ]);

                $invoice = $payment->invoice;
                if ($invoice) {
                    $invoice->amount_paid += $payment->amount;
                    if ($invoice->amount_paid >= $invoice->total_amount) {
                        $invoice->status = 'paid';
                    } else {
                        $invoice->status = 'partial';
                    }
                    $invoice->save();
                }
            }

            /*
             * Result-checker PINs settle on this same verified webhook.
             *
             * Only reached when the reference is not a fee payment, so the
             * invoice path above is untouched. Both branches are idempotent:
             * gateways retry deliveries, and a second delivery must not mint a
             * second batch of PINs or allocate a second PIN to one sale.
             */
            if (! $payment) {
                app(\App\Services\ResultPinCheckoutService::class)
                    ->settle($reference, (float) $amountPaid);
            }
        }

        return response()->json(['message' => 'Webhook received, verified, and payment record updated.']);
    }

    public function downloadInvoicePdf(Request $request, $id)
    {
        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $invoice = Invoice::where('school_id', $schoolId)->with(['student.user'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'document_type' => 'invoice_pdf',
            'invoice_number' => 'INV-' . str_pad($invoice->id, 6, '0', STR_PAD_LEFT),
            'total_amount' => $invoice->total_amount,
            'amount_paid' => $invoice->amount_paid,
            'download_url' => url("/api/v1/finance/invoices/{$invoice->id}/pdf"),
        ]);
    }

    public function downloadPaymentReceipt(Request $request, $id)
    {
        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $payment = Payment::where('school_id', $schoolId)->with(['invoice.student.user'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'document_type' => 'payment_receipt_pdf',
            'receipt_number' => 'REC-' . str_pad($payment->id, 6, '0', STR_PAD_LEFT),
            'amount' => $payment->amount,
            'gateway' => $payment->gateway,
            'reference' => $payment->reference,
            'paid_at' => $payment->paid_at,
            'download_url' => url("/api/v1/finance/payments/{$payment->id}/receipt"),
        ]);
    }

    /**
     * Bank Transfer Manual & Automated Reconciliation Endpoint
     */
    public function reconcileBankTransfer(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        $validator = Validator::make($request->all(), [
            'payment_id' => 'required_without:reference',
            'reference'  => 'required_without:payment_id',
            'action'     => 'required|in:approve,reject',
            'notes'      => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = Payment::where('school_id', $schoolId);
        if ($request->payment_id) {
            $query->where('id', $request->payment_id);
        } else {
            $query->where('reference', $request->reference);
        }

        $payment = $query->first();

        if (!$payment) {
            return response()->json(['message' => 'Bank transfer payment record not found.'], 404);
        }

        if ($request->action === 'approve') {
            if ($payment->status !== 'successful') {
                $payment->status = 'successful';
                $payment->paid_at = now();
                $payment->save();

                $invoice = $payment->invoice;
                if ($invoice) {
                    $invoice->amount_paid += $payment->amount;
                    if ($invoice->amount_paid >= $invoice->total_amount) {
                        $invoice->status = 'paid';
                    } else {
                        $invoice->status = 'partial';
                    }
                    $invoice->save();
                }

                // Send WhatsApp notification receipt to parent upon reconciliation approval
                $whatsAppService = app(\App\Services\WhatsAppService::class);
                $parentUser = $invoice ? ($invoice->student ? $invoice->student->user : null) : null;
                $phone = $parentUser ? ($parentUser->userProfile->phone ?? '+2348000000000') : '+2348000000000';
                $parentName = $parentUser ? $parentUser->name : 'Parent';

                $whatsAppService->sendPaymentReceiptNotification(
                    $phone,
                    $parentName,
                    number_format($payment->amount, 2),
                    $payment->reference
                );
            }

            return response()->json([
                'message' => 'Bank transfer reconciled and approved successfully.',
                'payment' => $payment->fresh(['invoice']),
            ]);
        } else {
            $payment->status = 'failed';
            $payment->save();

            return response()->json([
                'message' => 'Bank transfer payment rejected.',
                'payment' => $payment,
            ]);
        }
    }
}
