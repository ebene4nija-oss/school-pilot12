<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SchoolPaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * A school connecting its own merchant account (gap G8).
 *
 * Nothing in the product let a school supply gateway credentials, so every
 * tenant's fees settled into one platform account. These three routes are the
 * missing half of that: the school pastes the keys from its own Paystack or
 * Flutterwave dashboard, and PaymentGatewayService uses them for that school's
 * checkouts and for verifying that school's callbacks.
 *
 * The keys are write-only across this API. `show` returns a mask built from the
 * last four characters and never the key itself — an admin needs to recognise
 * which key is loaded, not to read it back, and a settings screen that renders
 * live merchant secrets is one screenshot away from being the leak.
 */
class PaymentGatewayController extends Controller
{
    /**
     * How each gateway names its keys, and what a valid one looks like.
     *
     * Worth validating rather than storing whatever arrives: the single most
     * common way this is misconfigured is pasting the public key into the
     * secret field, which produces a gateway that authenticates fine at save
     * time and then fails at the first real payment.
     */
    private const KEY_RULES = [
        'paystack' => [
            'secret' => '/^sk_(test|live)_[A-Za-z0-9]{10,}$/',
            'public' => '/^pk_(test|live)_[A-Za-z0-9]{10,}$/',
            'secret_hint' => 'A Paystack secret key looks like sk_test_… or sk_live_….',
            'public_hint' => 'A Paystack public key looks like pk_test_… or pk_live_….',
        ],
        'flutterwave' => [
            'secret' => '/^FLWSECK[_-].{10,}$/',
            'public' => '/^FLWPUBK[_-].{10,}$/',
            'secret_hint' => 'A Flutterwave secret key looks like FLWSECK_TEST-… or FLWSECK-….',
            'public_hint' => 'A Flutterwave public key looks like FLWPUBK_TEST-… or FLWPUBK-….',
        ],
    ];

    /** What is connected, for the finance settings screen. */
    public function index(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $configured = SchoolPaymentGateway::where('school_id', $schoolId)
            ->get()
            ->keyBy('gateway');

        $gateways = collect(SchoolPaymentGateway::GATEWAYS)->map(function (string $gateway) use ($configured, $request) {
            $row = $configured->get($gateway);

            return [
                'gateway' => $gateway,
                'connected' => (bool) $row,
                'is_active' => $row ? $row->is_active : false,
                'usable' => $row ? $row->isUsable() : false,
                'mode' => $row?->mode,
                'secret_key_hint' => $row && $row->secret_last4 ? '••••' . $row->secret_last4 : null,
                'has_public_key' => $row ? filled($row->public_key) : false,
                // Flutterwave will not sign a callback we can trust until the
                // school has also given us the hash from its dashboard.
                'webhook_secret_required' => $gateway === 'flutterwave',
                'has_webhook_secret' => $row ? filled($row->webhook_secret) : false,
                'updated_at' => $row?->updated_at,

                /*
                 * The URL the school pastes into its own gateway dashboard.
                 * Built from the current request root so it carries the school's
                 * subdomain — an admin copying this from their own console gets
                 * the address that reaches their tenant.
                 */
                'webhook_url' => url("/api/v1/webhooks/{$gateway}"),
            ];
        });

        return response()->json([
            'data' => $gateways,
            'currency' => 'NGN',
        ]);
    }

    /** Connect or re-key one gateway. */
    public function update(Request $request, string $gateway)
    {
        if (! in_array($gateway, SchoolPaymentGateway::GATEWAYS, true)) {
            return response()->json(['message' => 'Unknown payment gateway.'], 404);
        }

        $rules = self::KEY_RULES[$gateway];

        $validator = Validator::make($request->all(), [
            'secret_key' => ['required', 'string', 'max:255', "regex:{$rules['secret']}"],
            'public_key' => ['nullable', 'string', 'max:255', "regex:{$rules['public']}"],
            // Paystack signs with the secret key. Flutterwave compares a static
            // hash the merchant sets themselves, so we cannot verify a single
            // callback from them without it.
            'webhook_secret' => [$gateway === 'flutterwave' ? 'required' : 'nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'secret_key.regex' => $rules['secret_hint'],
            'public_key.regex' => $rules['public_hint'],
            'webhook_secret.required' => 'Flutterwave needs the secret hash from your dashboard so we can verify payment callbacks.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->schoolId($request);
        $secret = $request->input('secret_key');

        $existing = SchoolPaymentGateway::where('school_id', $schoolId)
            ->where('gateway', $gateway)
            ->first();

        $row = SchoolPaymentGateway::updateOrCreate(
            ['school_id' => $schoolId, 'gateway' => $gateway],
            [
                'secret_key' => $secret,
                'public_key' => $request->input('public_key'),
                'webhook_secret' => $request->input('webhook_secret'),
                'secret_last4' => substr($secret, -4),
                'mode' => $this->modeOf($gateway, $secret),
                'is_active' => $request->boolean('is_active', true),
                'updated_by' => $request->user()->id,
            ]
        );

        /*
         * Audited without values on either side. Which account a school's fees
         * land in is exactly the change you want a trail for, and the values
         * are exactly what must never be written to a log table.
         */
        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => $existing ? 'finance.gateway_rekeyed' : 'finance.gateway_connected',
            'auditable_type' => SchoolPaymentGateway::class,
            'auditable_id' => $row->id,
            'new_values' => ['gateway' => $gateway, 'mode' => $row->mode, 'secret_last4' => $row->secret_last4],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => "Your school's {$gateway} account is connected. Paste the webhook URL below into your {$gateway} dashboard so payments confirm automatically.",
            'gateway' => $gateway,
            'mode' => $row->mode,
            'secret_key_hint' => '••••' . $row->secret_last4,
            'webhook_url' => url("/api/v1/webhooks/{$gateway}"),
        ]);
    }

    /**
     * Disconnect.
     *
     * The row is deleted rather than blanked so nothing keeps a dead secret
     * around. Payments already taken are untouched — they live on `payments`,
     * and a callback for one still settles against the platform fallback.
     */
    public function destroy(Request $request, string $gateway)
    {
        if (! in_array($gateway, SchoolPaymentGateway::GATEWAYS, true)) {
            return response()->json(['message' => 'Unknown payment gateway.'], 404);
        }

        $schoolId = $this->schoolId($request);

        $row = SchoolPaymentGateway::where('school_id', $schoolId)
            ->where('gateway', $gateway)
            ->first();

        if (! $row) {
            return response()->json(['message' => 'That gateway is not connected.'], 404);
        }

        $row->delete();

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'finance.gateway_disconnected',
            'auditable_type' => SchoolPaymentGateway::class,
            'auditable_id' => $row->id,
            'new_values' => ['gateway' => $gateway],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => "Your school's {$gateway} account has been disconnected. Online payment through it will stop immediately."]);
    }

    /**
     * Live or test, read off the key rather than asked for.
     *
     * A school that pastes test keys into production should see that on the
     * settings screen, not find out when the first parent's payment never
     * arrives.
     *
     * Matched on each gateway's documented prefix rather than by looking for
     * "test" anywhere in the string — the random tail of a live key can contain
     * it, and reporting a live account as a sandbox is the more dangerous of
     * the two ways to be wrong.
     */
    private function modeOf(string $gateway, string $secret): string
    {
        $isTest = $gateway === 'flutterwave'
            ? str_starts_with(strtoupper($secret), 'FLWSECK_TEST')
            : str_starts_with($secret, 'sk_test_');

        return $isTest ? 'test' : 'live';
    }

    private function schoolId(Request $request): ?int
    {
        $profile = $request->user()->userProfile;

        return $profile ? (int) $profile->school_id : null;
    }
}
