<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use App\Models\ResultPinSale;
use App\Models\SchoolPaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every school's own merchant account (gap G8).
 *
 * Three jobs, all of which used to be missing or done in the client:
 *
 *  1. Hold and hand out a school's own gateway credentials, so a parent's fees
 *     settle into that school's Paystack or Flutterwave account rather than one
 *     platform-wide account shared by every tenant.
 *  2. Open checkout **server-side** and return a hosted `authorization_url`. No
 *     key of any kind — not even the public one — reaches the handset, so a
 *     decompiled APK yields nothing, and the amount charged is the amount the
 *     server computed rather than whatever the client passed in.
 *  3. Decide which secret a webhook must verify against, before verifying it.
 *
 * The platform's own config keys are still used, and only used, for money that
 * genuinely flows to SchoolPilot: a school buying result-checker PIN stock.
 */
class PaymentGatewayService
{
    private const PAYSTACK_INITIALIZE = 'https://api.paystack.co/transaction/initialize';
    private const FLUTTERWAVE_INITIALIZE = 'https://api.flutterwave.com/v3/payments';

    /**
     * A reference the server owns (gap G9).
     *
     * Static because ResultPinService mints the same shape for PIN batches, and
     * two implementations of "what a SchoolPilot payment reference looks like"
     * is exactly the sort of thing that drifts and then fails to match at
     * settlement time.
     */
    public static function reference(string $prefix): string
    {
        return $prefix . '_' . now()->format('YmdHis') . '_' . strtoupper(Str::random(8));
    }

    /**
     * A school's credentials for one gateway, or null if it has not connected it.
     *
     * `allTenants()` because the webhook path calls this having just worked out
     * the school from a reference — there is no authenticated user and no
     * subdomain to scope by at that point.
     */
    public function credentialsFor(int $schoolId, string $gateway): ?SchoolPaymentGateway
    {
        return SchoolPaymentGateway::allTenants()
            ->where('school_id', $schoolId)
            ->where('gateway', $gateway)
            ->first();
    }

    /** The same, but only when it can actually take money. */
    public function usableCredentialsFor(int $schoolId, string $gateway): ?SchoolPaymentGateway
    {
        $credentials = $this->credentialsFor($schoolId, $gateway);

        return $credentials && $credentials->isUsable() ? $credentials : null;
    }

    /**
     * Whichever gateway this school can actually take money through.
     *
     * Callers name a gateway only when the payer has been given a choice. They
     * usually have not: a parent tapping Pay has no idea which merchant account
     * their school holds, and the one endpoint that could tell them —
     * `GET /finance/gateways` — is school_admin only, and rightly so. So the
     * server picks, in the order gateways are declared, and the client sends no
     * `gateway` at all.
     */
    public function defaultCredentialsFor(int $schoolId): ?SchoolPaymentGateway
    {
        foreach (SchoolPaymentGateway::GATEWAYS as $gateway) {
            $credentials = $this->usableCredentialsFor($schoolId, $gateway);

            if ($credentials) {
                return $credentials;
            }
        }

        return null;
    }

    /**
     * Resolve an optional caller-supplied gateway to usable credentials.
     *
     * Returns null when the school cannot take an online payment at all, which
     * every caller turns into a 409 telling the payer what to do instead.
     */
    public function resolveCredentials(int $schoolId, ?string $gateway): ?SchoolPaymentGateway
    {
        return $gateway === null || $gateway === ''
            ? $this->defaultCredentialsFor($schoolId)
            : $this->usableCredentialsFor($schoolId, $gateway);
    }

    /**
     * Open a hosted checkout on the school's own account.
     *
     * @param  array{reference:string,amount:float,email:string,name?:string,callback_url?:string,title?:string,metadata?:array}  $order
     * @return array{authorization_url:string,gateway:string}
     *
     * @throws PaymentGatewayException
     */
    public function initializeCheckout(SchoolPaymentGateway $credentials, array $order): array
    {
        return $credentials->gateway === 'flutterwave'
            ? $this->initializeFlutterwave($credentials, $order)
            : $this->initializePaystack($credentials, $order);
    }

    private function initializePaystack(SchoolPaymentGateway $credentials, array $order): array
    {
        /*
         * Kobo, as an integer. Paystack rejects a float and — worse — a value
         * still in naira is accepted and charges the parent one hundredth of
         * the fee, which reconciles as a mystery underpayment weeks later.
         */
        $response = $this->send($credentials, self::PAYSTACK_INITIALIZE, [
            'email' => $order['email'],
            'amount' => (int) round(((float) $order['amount']) * 100),
            'currency' => 'NGN',
            'reference' => $order['reference'],
            'callback_url' => $order['callback_url'] ?? null,
            'metadata' => $order['metadata'] ?? [],
        ]);

        $url = $response->json('data.authorization_url');

        if ($response->json('status') !== true || ! is_string($url) || $url === '') {
            $this->fail('paystack', $order['reference'], $response->json('message'), $response->status());
        }

        return ['authorization_url' => $url, 'gateway' => 'paystack'];
    }

    private function initializeFlutterwave(SchoolPaymentGateway $credentials, array $order): array
    {
        $response = $this->send($credentials, self::FLUTTERWAVE_INITIALIZE, [
            'tx_ref' => $order['reference'],
            'amount' => (string) $order['amount'],
            'currency' => 'NGN',
            'redirect_url' => $order['callback_url'] ?? null,
            'customer' => [
                'email' => $order['email'],
                'name' => $order['name'] ?? null,
            ],
            'customizations' => [
                'title' => $order['title'] ?? 'School payment',
            ],
            'meta' => $order['metadata'] ?? [],
        ]);

        $url = $response->json('data.link');

        if ($response->json('status') !== 'success' || ! is_string($url) || $url === '') {
            $this->fail('flutterwave', $order['reference'], $response->json('message'), $response->status());
        }

        return ['authorization_url' => $url, 'gateway' => 'flutterwave'];
    }

    /**
     * One place that ever puts a school's secret on the wire.
     *
     * A connection failure is caught rather than allowed to surface: the
     * exception message from Guzzle carries the full request, and this request
     * carries an Authorization header holding a live merchant secret. That must
     * not reach a log file or an error tracker.
     */
    private function send(SchoolPaymentGateway $credentials, string $url, array $body)
    {
        try {
            return Http::withToken($credentials->secret_key)
                ->timeout(20)
                ->acceptJson()
                ->asJson()
                ->post($url, array_filter($body, fn ($value) => $value !== null));
        } catch (\Throwable $e) {
            Log::error('Payment gateway unreachable.', [
                'gateway' => $credentials->gateway,
                'school_id' => $credentials->school_id,
                'reference' => $body['reference'] ?? $body['tx_ref'] ?? null,
            ]);

            throw new PaymentGatewayException(
                'Could not reach the payment gateway. Please try again in a moment.'
            );
        }
    }

    private function fail(string $gateway, string $reference, $message, int $status): never
    {
        Log::warning('Payment gateway refused a checkout.', [
            'gateway' => $gateway,
            'reference' => $reference,
            'http_status' => $status,
            'gateway_message' => is_string($message) ? $message : null,
        ]);

        throw new PaymentGatewayException(
            is_string($message) && $message !== ''
                ? "The school's payment gateway rejected this transaction: {$message}"
                : 'The payment gateway could not start this transaction.'
        );
    }

    // ------------------------------------------------------------- Webhooks

    /**
     * Which school's key must sign this callback.
     *
     * The chicken-and-egg of per-school merchant accounts: a gateway callback
     * carries a reference and nothing else, so the school has to be discovered
     * before the signature can be checked, from a payload that is by definition
     * not yet trusted.
     *
     * That is safe because the payload is used for **key selection only**. The
     * reference names the row we are about to settle, so the key we look up is
     * that row's school's key — pick the wrong one and verification simply
     * fails. Crucially it is resolved from the reference and not from the
     * request host: a school admin who can reach their own subdomain must not
     * be able to have their own secret accepted as the signature on another
     * school's payment.
     *
     * A null return means "no school owns this reference" — an unknown
     * reference, or a school buying PIN stock from SchoolPilot, which really
     * does settle on the platform account.
     */
    public function schoolIdForReference(?string $reference): ?int
    {
        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $schoolId = Payment::allTenants()->where('reference', $reference)->value('school_id');

        if ($schoolId) {
            return (int) $schoolId;
        }

        $schoolId = ResultPinSale::withoutGlobalScopes()->where('reference', $reference)->value('school_id');

        return $schoolId ? (int) $schoolId : null;
    }

    /**
     * The secret this callback must verify against.
     *
     * Falls back to the platform key when the school has not connected its own.
     * That is what keeps every school deployed before this change working
     * unchanged, and it is what settles PIN stock bought from SchoolPilot.
     */
    public function webhookSigningSecret(string $gateway, ?int $schoolId): ?string
    {
        if ($schoolId !== null) {
            $credentials = $this->credentialsFor($schoolId, $gateway);
            $secret = $credentials?->signingSecret();

            if (is_string($secret) && trim($secret) !== '') {
                return $secret;
            }
        }

        return $this->platformSigningSecret($gateway);
    }

    private function platformSigningSecret(string $gateway): ?string
    {
        $secret = $gateway === 'flutterwave'
            ? config('services.flutterwave.secret_hash')
            : config('services.paystack.secret');

        return is_string($secret) && trim($secret) !== '' ? $secret : null;
    }

    /**
     * The reference a callback is about, read before the signature is checked.
     *
     * Used only to choose a key (see schoolIdForReference). The handler reads
     * the authoritative reference again, from the same payload, after the
     * signature has verified.
     */
    public function referenceFromPayload(string $gateway, array $payload): ?string
    {
        $candidate = $gateway === 'flutterwave'
            ? ($payload['data']['tx_ref'] ?? $payload['txRef'] ?? null)
            : ($payload['data']['reference'] ?? null);

        return is_string($candidate) ? $candidate : null;
    }
}
