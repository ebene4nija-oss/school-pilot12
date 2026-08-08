<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FinanceController extends Controller
{
    public function storeFeeStructure(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'class_id' => 'nullable|exists:classes,id',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $fee = FeeStructure::create([
            'school_id' => $schoolId,
            'term_id' => $request->term_id,
            'class_id' => $request->class_id,
            'title' => $request->title,
            'amount' => $request->amount,
            'is_mandatory' => $request->get('is_mandatory', true),
        ]);

        return response()->json([
            'message' => 'Fee structure created successfully',
            'fee_structure' => $fee,
        ], 201);
    }

    public function recordPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:1',
            'gateway' => 'required|in:paystack,flutterwave,bank_transfer,cash',
            'reference' => 'required|string|unique:payments,reference',
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

        $payment = Payment::create([
            'school_id' => $schoolId,
            'invoice_id' => $invoice->id,
            'reference' => $request->reference,
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

    public function handleWebhook(Request $request, $gateway)
    {
        $payload = $request->all();
        $reference = null;
        $amountPaid = 0;

        // 1. Paystack Webhook Cryptographic Verification (HMAC-SHA512)
        if ($gateway === 'paystack') {
            $paystackHeader = $request->header('x-paystack-signature');

            // Read through config() — env() returns null once config is cached,
            // and the previous fallback value was committed to this repo, which
            // meant a cached-config deploy verified against a public string.
            $secret = config('services.paystack.secret');

            if (!is_string($secret) || trim($secret) === '') {
                Log::error('Paystack webhook rejected: PAYSTACK_SECRET_KEY is not configured.');

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
            $secretHash = config('services.flutterwave.secret_hash');

            if (!is_string($secretHash) || trim($secretHash) === '') {
                Log::error('Flutterwave webhook rejected: FLUTTERWAVE_SECRET_HASH is not configured.');

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
