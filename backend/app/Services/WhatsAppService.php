<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected string $provider;
    protected string $apiKey;
    protected string $senderId;

    public function __construct()
    {
        $this->provider = config('services.whatsapp.provider', 'termii');
        $this->apiKey = config('services.whatsapp.api_key', env('WHATSAPP_API_KEY', 'mock_key'));
        $this->senderId = config('services.whatsapp.sender_id', env('WHATSAPP_SENDER_ID', 'SchoolPilot'));
    }

    /**
     * Send automated WhatsApp notification message to a recipient phone number
     */
    public function sendMessage(string $recipientPhone, string $message): array
    {
        Log::info("WhatsApp Notification Queued", [
            'provider' => $this->provider,
            'recipient' => $recipientPhone,
            'message_snippet' => substr($message, 0, 50) . '...'
        ]);

        // In production/testing, dispatch to provider API or fallback clean mock response
        if (env('APP_ENV') === 'testing' || $this->apiKey === 'mock_key') {
            return [
                'status' => 'success',
                'provider' => $this->provider,
                'message_id' => 'WA_MOCK_' . uniqid(),
                'recipient' => $recipientPhone,
                'sent_at' => now()->toIso8601String(),
            ];
        }

        try {
            $response = Http::post("https://api.ng.termii.com/api/sms/send", [
                'to' => $recipientPhone,
                'from' => $this->senderId,
                'sms' => $message,
                'type' => 'plain',
                'channel' => 'whatsapp',
                'api_key' => $this->apiKey,
            ]);

            return $response->json() ?? ['status' => 'success'];
        } catch (\Exception $e) {
            Log::error("WhatsApp API Error: " . $e->getMessage());
            return ['status' => 'failed', 'error' => $e->getMessage()];
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
}
