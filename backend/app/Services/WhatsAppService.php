<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp over Termii's WhatsApp channel.
 *
 * Same correction as SmsService, and this one was the worse of the two: the key
 * was read as `config('services.whatsapp.api_key', env('WHATSAPP_API_KEY', 'mock_key'))`
 * against a `services.whatsapp` block that did not exist. So on any deployment
 * that had run `config:cache` the expression evaluated to null, and assigning
 * null to the typed `string $apiKey` property fatalled the request outright.
 * On every other deployment it evaluated to `mock_key` and returned a
 * fabricated success.
 *
 * WhatsApp carries the fee reminders and result notifications parents actually
 * read, so "reported as delivered, never sent" is the expensive failure here.
 */
class WhatsAppService
{
    private const ENDPOINT = 'https://api.ng.termii.com/api/sms/send';

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * @return array{status:string, message_id?:string, message?:string}
     */
    public function sendMessage(string $recipientPhone, string $message): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            Log::warning('WhatsApp not sent: no WHATSAPP_API_KEY is configured for this deployment.');

            return [
                'status' => 'error',
                'message' => 'WhatsApp is not configured for this deployment.',
            ];
        }

        // Snippet only. The message body carries the child's name, scores and
        // fee balance, none of which belongs in a logfile — see doc §12.
        Log::info('WhatsApp dispatch', [
            'provider' => $this->provider(),
            'recipient' => $recipientPhone,
            'length' => strlen($message),
        ]);

        try {
            $response = Http::timeout(15)
                ->retry(2, 500, throw: false)
                ->post(self::ENDPOINT, [
                    'to' => $recipientPhone,
                    'from' => $this->senderId(),
                    'sms' => $message,
                    'type' => 'plain',
                    'channel' => 'whatsapp',
                    'api_key' => $apiKey,
                ]);

            if ($response->failed()) {
                Log::error('WhatsApp gateway rejected the message', [
                    'status' => $response->status(),
                    'recipient' => $recipientPhone,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'WhatsApp gateway returned ' . $response->status() . '.',
                ];
            }

            $payload = $response->json();

            return is_array($payload) ? $payload + ['status' => 'success'] : ['status' => 'success'];
        } catch (\Throwable $e) {
            Log::error('WhatsApp API error: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Send automated fee receipt notification on payment complete
     */
    public function sendPaymentReceiptNotification(string $recipientPhone, string $parentName, string $amount, string $invoiceRef): array
    {
        $msg = "Dear {$parentName}, payment of ₦{$amount} for Invoice #{$invoiceRef} has been received. Download receipt: " . url("/verify-result/{$invoiceRef}");
        return $this->sendMessage($recipientPhone, $msg);
    }

    /**
     * Send Latest Student Result Summary via WhatsApp
     */
    public function sendResultNotification(string $recipientPhone, string $parentName, string $studentName, string $term, string $totalScore, string $average, string $verificationToken): array
    {
        $verifyUrl = url("/api/v1/verify-result/{$verificationToken}");
        $msg = "Dear {$parentName},\nResults for {$studentName} ({$term}) are ready!\nTotal Score: {$totalScore}\nAverage: {$average}%\nVerify & view full report card: {$verifyUrl}";
        return $this->sendMessage($recipientPhone, $msg);
    }

    /**
     * Send Student Attendance Update via WhatsApp
     */
    public function sendAttendanceNotification(string $recipientPhone, string $parentName, string $studentName, string $date, string $status): array
    {
        $msg = "Dear {$parentName},\nAttendance Update: {$studentName} was marked '{$status}' on {$date}.";
        return $this->sendMessage($recipientPhone, $msg);
    }

    /**
     * Send Fee Balance / Reminder via WhatsApp
     */
    public function sendFeeBalanceNotification(string $recipientPhone, string $parentName, string $studentName, string $totalAmount, string $amountPaid, string $balance, string $dueDate): array
    {
        $msg = "Dear {$parentName},\nFee Balance Notice for {$studentName}:\nTotal Fees: ₦{$totalAmount}\nAmount Paid: ₦{$amountPaid}\nOutstanding Balance: ₦{$balance}\nDue Date: {$dueDate}. Thank you.";
        return $this->sendMessage($recipientPhone, $msg);
    }

    /**
     * Send Homework / Assignment Update via WhatsApp
     */
    public function sendHomeworkNotification(string $recipientPhone, string $parentName, string $studentName, string $subject, string $title, string $dueDate): array
    {
        $msg = "Dear {$parentName},\nNew Homework assigned for {$studentName}:\nSubject: {$subject}\nTask: {$title}\nSubmission Due Date: {$dueDate}.";
        return $this->sendMessage($recipientPhone, $msg);
    }

    /**
     * Send Class Timetable Schedule via WhatsApp
     */
    public function sendTimetableNotification(string $recipientPhone, string $parentName, string $studentName, string $className, string $summary): array
    {
        $msg = "Dear {$parentName},\nClass Timetable update for {$studentName} ({$className}):\n{$summary}";
        return $this->sendMessage($recipientPhone, $msg);
    }

    private function apiKey(): ?string
    {
        $key = config('services.whatsapp.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function provider(): string
    {
        return (string) config('services.whatsapp.provider', 'termii');
    }

    private function senderId(): string
    {
        return (string) config('services.whatsapp.sender_id', 'SchoolPilot');
    }
}
