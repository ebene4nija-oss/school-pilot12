<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected string $provider;
    protected string $apiKey;

    public function __construct()
    {
        $this->provider = config('services.sms.provider', env('SMS_PROVIDER', 'termii'));
        $this->apiKey = config('services.sms.api_key', env('SMS_API_KEY', 'mock_sms_key'));
    }

    /**
     * Send High-Volume SMS Notification via Termii / KudiSMS
     */
    public function sendSms(string $to, string $message): array
    {
        if ($this->apiKey === 'mock_sms_key') {
            Log::info("Mock SMS sent to {$to}: {$message}");
            return ['status' => 'success', 'message_id' => 'mock_msg_' . uniqid()];
        }

        try {
            $response = Http::post('https://api.ng.termii.com/api/sms/send', [
                'to' => $to,
                'from' => 'SchoolPilot',
                'sms' => $message,
                'type' => 'plain',
                'channel' => 'generic',
                'api_key' => $this->apiKey,
            ]);

            return $response->json() ?? ['status' => 'failed'];
        } catch (\Exception $e) {
            Log::error("SMS Gateway dispatch failure: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
