<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a payment becomes money on an invoice.
 *
 * The bursary's manual entry, the reconciliation of a bank transfer and the
 * gateway webhook each used to do this themselves: read the invoice, add,
 * save. Two of them at once — a gateway retrying its webhook, a bursar
 * double-tapping on a slow connection — could both read the same balance and
 * credit the same money twice. Here the payment and its invoice are locked
 * for the length of the change, and a payment that is already successful is
 * never credited again.
 */
class PaymentSettlement
{
    /** Taken at the bursary and recorded by hand. Everything else is a gateway's. */
    public const MANUAL_GATEWAYS = ['cash', 'bank_transfer'];

    /** Rounding slack when comparing Naira amounts stored as decimals. */
    private const KOBO = 0.005;

    /**
     * Mark a payment successful and credit its invoice, once.
     *
     * @return bool true if this call settled it; false if it was already
     *              successful (a retried webhook, a second tap)
     */
    public function settle(Payment $payment): bool
    {
        $settled = DB::transaction(function () use ($payment) {
            $locked = Payment::allTenants()->whereKey($payment->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status === 'successful') {
                return false;
            }

            $invoice = $locked->invoice_id
                ? Invoice::withoutGlobalScopes()->whereKey($locked->invoice_id)->lockForUpdate()->first()
                : null;

            $locked->update(['status' => 'successful', 'paid_at' => now()]);

            if ($invoice) {
                $invoice->amount_paid = round((float) $invoice->amount_paid + (float) $locked->amount, 2);
                $invoice->status = (float) $invoice->amount_paid + self::KOBO >= (float) $invoice->total_amount ? 'paid' : 'partial';
                $invoice->save();
            }

            return true;
        });

        $payment->refresh();

        return $settled;
    }

    /** What is still owed on an invoice, never below zero. */
    public static function balance(Invoice $invoice): float
    {
        return max(0.0, round((float) $invoice->total_amount - (float) $invoice->amount_paid, 2));
    }

    public static function exceedsBalance(Invoice $invoice, float $amount): bool
    {
        return $amount > self::balance($invoice) + self::KOBO;
    }

    /**
     * WhatsApp the receipt to the student's guardians of record — primary
     * guardian first — never to a placeholder number and never to whoever
     * happened to record the payment. A guardian with no phone on file is
     * simply skipped; the receipt is still in the parent app.
     *
     * @return array<int, array<string, mixed>> one delivery result per guardian messaged
     */
    public function sendReceipt(Payment $payment): array
    {
        $invoice = $payment->invoice()->withoutGlobalScopes()->first();
        $student = $invoice?->student()->withoutGlobalScopes()->first();

        if (! $student) {
            return [];
        }

        $guardians = $student->guardians()
            ->withoutGlobalScopes()
            ->with('user.userProfile')
            ->orderByDesc('student_guardian.is_primary')
            ->get();

        $results = [];
        $sent = [];

        foreach ($guardians as $guardian) {
            /** @var Guardian $guardian */
            $phone = $guardian->user?->userProfile?->phone;

            if (! is_string($phone) || trim($phone) === '' || in_array($phone, $sent, true)) {
                continue;
            }

            $sent[] = $phone;

            try {
                $results[] = app(WhatsAppService::class)->sendPaymentReceiptNotification(
                    $phone,
                    (string) ($guardian->user?->name ?? 'Parent'),
                    number_format((float) $payment->amount, 2),
                    (string) $payment->reference,
                );
            } catch (\Throwable $e) {
                // A receipt that cannot be sent never undoes a payment.
                Log::warning('Payment receipt could not be sent.', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }
}
