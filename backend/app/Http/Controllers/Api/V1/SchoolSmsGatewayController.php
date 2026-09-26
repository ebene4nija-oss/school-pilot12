<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SchoolSmsGateway;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A school's own SMS account.
 *
 * Messages go out from the school's own phone and SIM (or its own bulk SMS
 * account), so the account belongs to the school: the platform cannot hold one
 * key that sends from forty schools' handsets. School admin only.
 *
 * The API key is write-only. The screen sees that one is set and its last four
 * characters; no response ever carries it.
 */
class SchoolSmsGatewayController extends Controller
{
    public function show()
    {
        $gateway = SchoolSmsGateway::first();

        return response()->json(['data' => $gateway ? $this->present($gateway) : null]);
    }

    /**
     * Create or replace the school's SMS account.
     *
     * `api_key` may be left out when updating other fields, so changing the
     * sending phone does not mean re-typing the key.
     */
    public function update(Request $request)
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $existing = SchoolSmsGateway::first();

        $validated = $request->validate([
            'provider' => ['required', Rule::in(SchoolSmsGateway::PROVIDERS)],
            // A phone gateway is reached through its own server; Termii's
            // address is fixed.
            'server_url' => [
                Rule::requiredIf($request->input('provider') === SchoolSmsGateway::PHONE_GATEWAY),
                'nullable', 'url', 'max:255',
                // The key travels in the request body, so it must not cross the
                // network in the clear.
                'starts_with:https://',
            ],
            'api_key' => [$existing ? 'nullable' : 'required', 'string', 'max:500'],
            // "1" for a device, "1|0" for a device and SIM slot.
            'device' => ['nullable', 'string', 'max:32', 'regex:/^\d+(\|\d+)?$/'],
            'sender_id' => ['nullable', 'string', 'max:11'],
            'is_active' => 'nullable|boolean',
        ], [
            'server_url.starts_with' => 'The gateway address must start with https:// — the key is sent with every message.',
            'device.regex' => 'Use a device number such as 1, or a device and SIM slot such as 1|0.',
        ]);

        $attributes = [
            'school_id' => $schoolId,
            'provider' => $validated['provider'],
            'server_url' => $validated['provider'] === SchoolSmsGateway::PHONE_GATEWAY
                ? rtrim($validated['server_url'], '/')
                : null,
            'device' => $validated['device'] ?? null,
            'sender_id' => $validated['sender_id'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ];

        if (filled($validated['api_key'] ?? null)) {
            $attributes['api_key'] = trim($validated['api_key']);
        }

        $gateway = $existing
            ? tap($existing)->update($attributes)
            : SchoolSmsGateway::create($attributes);

        $this->audit($request, 'school.sms_gateway.saved', $gateway, [
            'provider' => $gateway->provider,
            'key_changed' => filled($validated['api_key'] ?? null),
        ]);

        return response()->json([
            'message' => 'SMS account saved. Send a test message to check it.',
            'data' => $this->present($gateway->fresh()),
        ]);
    }

    public function destroy(Request $request)
    {
        $gateway = SchoolSmsGateway::first();

        if (! $gateway) {
            return response()->json(['message' => 'No SMS account is set up.'], 404);
        }

        $gateway->delete();
        $this->audit($request, 'school.sms_gateway.removed', $gateway, []);

        return response()->json(['message' => 'SMS account removed.']);
    }

    /**
     * Send one real message through the saved account.
     *
     * The only way to know a phone gateway works is to have it send: the key
     * can be valid while the handset is off, out of airtime or offline.
     */
    public function test(Request $request, SmsService $sms)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?\d[\d\s-]{6,}$/'],
        ]);

        $gateway = SchoolSmsGateway::first();

        if (! $gateway) {
            return response()->json(['message' => 'Save an SMS account first.'], 404);
        }

        $result = $sms->sendWith(
            $gateway,
            $validated['phone'],
            'SchoolPilot test message: your SMS account is working.'
        );

        $ok = ($result['status'] ?? null) === 'success';

        $gateway->update([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'passed' : 'failed',
            'last_test_message' => mb_substr((string) ($ok
                ? ($result['gateway_status'] ?? 'Accepted by the gateway')
                : ($result['message'] ?? 'The gateway refused the message.')), 0, 255),
        ]);

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'Test message accepted. It should arrive within a minute if the sending phone is online.'
                : ($result['message'] ?? 'The gateway refused the message.'),
            'data' => $this->present($gateway->fresh()),
        ], $ok ? 200 : 422);
    }

    /** Credits left, where the gateway reports them. */
    public function balance(SmsService $sms)
    {
        $gateway = SchoolSmsGateway::first();

        if (! $gateway) {
            return response()->json(['message' => 'No SMS account is set up.'], 404);
        }

        $result = $sms->balance($gateway);

        return response()->json($result, ($result['status'] ?? null) === 'success' ? 200 : 422);
    }

    private function present(SchoolSmsGateway $gateway): array
    {
        return [
            'provider' => $gateway->provider,
            'server_url' => $gateway->server_url,
            'device' => $gateway->device,
            'sender_id' => $gateway->sender_id,
            'is_active' => $gateway->is_active,
            'key_hint' => $gateway->keyHint(),
            'last_tested_at' => $gateway->last_tested_at?->toIso8601String(),
            'last_test_status' => $gateway->last_test_status,
            'last_test_message' => $gateway->last_test_message,
            'updated_at' => $gateway->updated_at?->toIso8601String(),
        ];
    }

    private function audit(Request $request, string $action, SchoolSmsGateway $gateway, array $values): void
    {
        AuditLog::create([
            'school_id' => $gateway->school_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => SchoolSmsGateway::class,
            'auditable_id' => $gateway->id,
            'new_values' => $values,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
