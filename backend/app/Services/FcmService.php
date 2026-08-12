<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging, HTTP v1 (§7.13).
 *
 * This replaces a call to `https://fcm.googleapis.com/fcm/send` authenticated
 * with a `key=AAAA…` server key. That is the **legacy** FCM API. Google turned
 * it off in June 2024; it now answers 404 for everybody. Push in this codebase
 * was not "unreliable", it was incapable of delivering a single message, and no
 * amount of client work could have fixed it — which is why the mobile plan
 * carried it as blocking gap G7.
 *
 * HTTP v1 differs in three ways that matter to callers:
 *
 *  1. Auth is a short-lived OAuth2 bearer token minted from a service account,
 *     not a static key. Minted here and cached, because a whole-school
 *     broadcast is ~900 sends and must not be ~900 token exchanges.
 *  2. Every value in the `data` map must be a **string**. A stray integer is a
 *     400 for the whole message, so payloads are coerced on the way out.
 *  3. Dead tokens come back as a typed `errorCode`, not as a word to be found
 *     somewhere in the response body.
 */
class FcmService
{
    /** The message reached FCM. Delivery to the handset is still FCM's problem. */
    public const SENT = 'sent';

    /** This token will never work again. The caller should delete it. */
    public const INVALID_TOKEN = 'invalid_token';

    /** Transient or our fault. Keep the token, let the job retry. */
    public const FAILED = 'failed';

    private const TOKEN_CACHE_KEY = 'fcm:v1:access_token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const OAUTH_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /** @var array<string,mixed>|null Lazily resolved service-account JSON. */
    private ?array $credentials = null;

    private bool $credentialsResolved = false;

    /**
     * Whether this deployment can send at all.
     *
     * Checked before a broadcast fans out, so an unconfigured school gets one
     * honest "push is not configured" per recipient instead of 900 attempts
     * against an endpoint we already know we cannot authenticate to.
     */
    public function isConfigured(): bool
    {
        return $this->projectId() !== null && $this->credentials() !== null;
    }

    /**
     * Send one notification to one device token.
     *
     * One device per call rather than a multicast batch: the caller needs to
     * know *which* token died in order to delete it, and `sendEach` style
     * batching in v1 still costs one HTTP request per token anyway.
     *
     * @param  array<string,mixed>  $data  Deep-link payload; coerced to strings.
     * @return array{status:string, reason:?string}
     */
    public function sendToToken(string $deviceToken, string $title, string $body, array $data = []): array
    {
        if (! $this->isConfigured()) {
            return $this->result(self::FAILED, 'Push is not configured for this deployment.');
        }

        $accessToken = $this->accessToken();

        if ($accessToken === null) {
            return $this->result(self::FAILED, 'Could not authenticate against Firebase.');
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                /*
                 * Retry the failures that are worth retrying and none of the
                 * others. A 404 UNREGISTERED is a settled fact about a dead
                 * handset; retrying it twice per send, across a 900-family
                 * broadcast, is 1,800 pointless round trips.
                 */
                ->retry(3, 300, function (\Throwable $e) {
                    $status = $e instanceof RequestException ? $e->response->status() : null;

                    return $status === null || $status === 429 || $status >= 500;
                }, throw: false)
                ->post($this->sendEndpoint(), [
                    'message' => $this->message($deviceToken, $title, $body, $data),
                ]);
        } catch (\Throwable $e) {
            Log::warning('FCM send failed: ' . $e->getMessage());

            return $this->result(self::FAILED, 'Could not reach Firebase.');
        }

        if ($response->successful()) {
            return $this->result(self::SENT);
        }

        return $this->classify($response->status(), $response->json() ?? []);
    }

    /**
     * The v1 message envelope.
     *
     * `android.notification.channel_id` has to match a channel the Flutter app
     * actually created, or Android 8+ silently drops the notification — it does
     * not error, it just never appears. That contract is documented for the app
     * side in docs/mobile-app.md §B10.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function message(string $deviceToken, string $title, string $body, array $data): array
    {
        return [
            'token' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $this->stringifyData($data),
            'android' => [
                // Fee deadlines and result releases are time-bound; they should
                // wake a dozing handset rather than wait for the next sync.
                'priority' => 'high',
                'notification' => [
                    'channel_id' => config('services.fcm.android_channel_id'),
                    'sound' => 'default',
                ],
            ],
            'apns' => [
                'headers' => ['apns-priority' => '10'],
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        // Lets the app refresh its inbox on receipt rather than
                        // only when the parent taps the banner.
                        'content-available' => 1,
                    ],
                ],
            ],
        ];
    }

    /**
     * FCM v1 rejects a data map containing anything but strings.
     *
     * Callers pass ids as integers and flags as booleans without thinking about
     * it — reasonably — so coerce here rather than making every call site
     * remember. Nested structures are JSON-encoded; the client decodes them.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,string>
     */
    private function stringifyData(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $out[(string) $key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => json_encode($value) ?: '',
            };
        }

        return $out;
    }

    /**
     * Turn an FCM error response into a decision about the token.
     *
     * The distinction is destructive, so it is drawn narrowly: only errors that
     * are unambiguously *about this token* delete it. A plain 400 does not —
     * `INVALID_ARGUMENT` is also what a malformed payload of our own returns,
     * and treating that as a dead token would wipe every device on the platform
     * the first time we shipped a bad payload.
     *
     * @param  array<string,mixed>  $payload
     * @return array{status:string, reason:?string}
     */
    private function classify(int $status, array $payload): array
    {
        $errorCode = null;

        foreach ($payload['error']['details'] ?? [] as $detail) {
            if (isset($detail['errorCode'])) {
                $errorCode = $detail['errorCode'];
                break;
            }
        }

        $message = $payload['error']['message'] ?? 'FCM rejected the message.';

        // The handset uninstalled, cleared data, or the token expired.
        if ($errorCode === 'UNREGISTERED' || $status === 404) {
            return $this->result(self::INVALID_TOKEN, 'Device is no longer registered.');
        }

        // A token minted against a different Firebase project. Never recoverable.
        if ($errorCode === 'SENDER_ID_MISMATCH') {
            return $this->result(self::INVALID_TOKEN, 'Token belongs to another Firebase project.');
        }

        // A 400 counts against the token only when FCM names the token field.
        if ($status === 400 && $this->violatesTokenField($payload)) {
            return $this->result(self::INVALID_TOKEN, 'Device token is malformed.');
        }

        if ($status === 401 || $status === 403) {
            // The cached bearer token is stale or the service account lost its
            // grant. Drop it so the next send mints a fresh one instead of
            // replaying a credential FCM has already refused.
            Cache::forget(self::TOKEN_CACHE_KEY);

            return $this->result(self::FAILED, 'Firebase rejected our credentials.');
        }

        Log::warning("FCM send rejected ({$status}): {$message}");

        return $this->result(self::FAILED, $message);
    }

    /** @param array<string,mixed> $payload */
    private function violatesTokenField(array $payload): bool
    {
        foreach ($payload['error']['details'] ?? [] as $detail) {
            foreach ($detail['fieldViolations'] ?? [] as $violation) {
                if (str_ends_with((string) ($violation['field'] ?? ''), 'token')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A cached OAuth2 access token for the service account.
     *
     * Google issues these for an hour. Cached a little short of that so a
     * broadcast that starts at minute 59 does not begin failing halfway
     * through, and never cached on failure — a missed mint must be retried on
     * the next send, not remembered as "no token" for the hour.
     */
    private function accessToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $minted = $this->mintAccessToken();

        if ($minted === null) {
            return null;
        }

        Cache::put(
            self::TOKEN_CACHE_KEY,
            $minted['token'],
            now()->addSeconds(max(60, $minted['expires_in'] - 300))
        );

        return $minted['token'];
    }

    /**
     * Exchange a signed JWT assertion for an access token.
     *
     * Done by hand rather than with google/auth: the whole flow is one signed
     * assertion and one POST, and the library pulls in guzzle-configured
     * transport and credential discovery this project does not otherwise use.
     *
     * @return array{token:string, expires_in:int}|null
     */
    private function mintAccessToken(): ?array
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            return null;
        }

        $assertion = $this->signedAssertion($credentials);

        if ($assertion === null) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->retry(2, 300, throw: false)
                ->post(self::OAUTH_ENDPOINT, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
        } catch (\Throwable $e) {
            Log::error('Could not reach Google token endpoint: ' . $e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::error('Firebase token exchange failed: ' . $response->body());

            return null;
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            Log::error('Firebase token exchange returned no access token.');

            return null;
        }

        return [
            'token' => $token,
            'expires_in' => (int) ($response->json('expires_in') ?? 3600),
        ];
    }

    /**
     * RS256-sign the JWT bearer assertion with the service account key.
     *
     * @param  array<string,mixed>  $credentials
     */
    private function signedAssertion(array $credentials): ?string
    {
        $now = time();

        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']) ?: '');
        $claims = $this->base64Url(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $credentials['token_uri'] ?? self::OAUTH_ENDPOINT,
            'iat' => $now,
            // Google caps assertion lifetime at an hour.
            'exp' => $now + 3600,
        ]) ?: '');

        $signingInput = "{$header}.{$claims}";
        $signature = '';

        // Hosts that only offer single-line env vars store the PEM with literal
        // "\n" sequences. Normalise, or openssl silently refuses the key.
        $privateKey = str_replace('\n', "\n", (string) $credentials['private_key']);

        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            Log::error('Could not sign the Firebase assertion — check FCM_CREDENTIALS private_key.');

            return null;
        }

        return $signingInput . '.' . $this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * The service-account JSON, from a file path or from the JSON itself.
     *
     * Both are supported because the deployment targets differ: a VPS mounts
     * the file, while Render/Railway-style hosts only hand the app environment
     * variables and cannot mount a secret file at all.
     *
     * @return array<string,mixed>|null
     */
    private function credentials(): ?array
    {
        if ($this->credentialsResolved) {
            return $this->credentials;
        }

        $this->credentialsResolved = true;
        $configured = config('services.fcm.credentials');

        if (! is_string($configured) || trim($configured) === '') {
            return $this->credentials = null;
        }

        $configured = trim($configured);

        if (! str_starts_with($configured, '{')) {
            $path = str_starts_with($configured, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured)
                ? $configured
                : base_path($configured);

            if (! is_readable($path)) {
                Log::error("FCM credentials file is missing or unreadable: {$path}");

                return $this->credentials = null;
            }

            $configured = (string) file_get_contents($path);
        }

        $decoded = json_decode($configured, true);

        if (! is_array($decoded)) {
            Log::error('FCM credentials are not valid JSON.');

            return $this->credentials = null;
        }

        foreach (['client_email', 'private_key'] as $required) {
            if (empty($decoded[$required])) {
                Log::error("FCM credentials are missing \"{$required}\".");

                return $this->credentials = null;
            }
        }

        return $this->credentials = $decoded;
    }

    /**
     * The Firebase project to send as.
     *
     * Falls back to the `project_id` inside the service account, which is the
     * same value in every correct configuration — one fewer env var to get
     * subtly wrong.
     */
    private function projectId(): ?string
    {
        $configured = config('services.fcm.project_id');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $fromCredentials = $this->credentials()['project_id'] ?? null;

        return is_string($fromCredentials) && $fromCredentials !== '' ? $fromCredentials : null;
    }

    private function sendEndpoint(): string
    {
        return "https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send";
    }

    /** @return array{status:string, reason:?string} */
    private function result(string $status, ?string $reason = null): array
    {
        return ['status' => $status, 'reason' => $reason];
    }
}
