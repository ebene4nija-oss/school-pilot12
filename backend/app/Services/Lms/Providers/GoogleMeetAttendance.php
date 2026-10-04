<?php

namespace App\Services\Lms\Providers;

use App\Models\LiveClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Participants of a Google Meet call, from the school's Google Workspace
 * through the Meet REST API. The school gives a service account with
 * domain-wide delegation for the meetings.space.readonly scope, and the
 * Workspace user it acts as (usually the IT admin). The conference record
 * nearest the class's scheduled time, for the class's meeting code, is used.
 */
class GoogleMeetAttendance
{
    private const API = 'https://meet.googleapis.com/v2';
    private const SCOPE = 'https://www.googleapis.com/auth/meetings.space.readonly';

    /** @return array<int, array{key: string, name: string, email: ?string, joined_at: Carbon, left_at: Carbon}> */
    public function sessions(LiveClass $lc, array $creds): array
    {
        $code = $this->meetingCode((string) $lc->meeting_url) ?? $this->meetingCode((string) $lc->meeting_id);
        if (! $code) {
            throw new RuntimeException('This class has no Google Meet link (meet.google.com/abc-defg-hij).');
        }
        $token = $this->token($creds);

        $records = Http::withToken($token)->get(self::API.'/conferenceRecords', ['filter' => "space.meeting_code=\"{$code}\""]);
        if (! $records->successful()) {
            throw new RuntimeException('Google refused the meeting lookup: '.($records->json('error.message') ?? $records->status()));
        }
        $record = collect($records->json('conferenceRecords') ?? [])
            ->sortBy(fn ($r) => abs(Carbon::parse($r['startTime'])->diffInSeconds($lc->scheduled_for, false)))
            ->first();
        if (! $record) {
            throw new ProviderReportUnavailable('Google has no record of this call yet.');
        }
        if (empty($record['endTime'])) {
            throw new ProviderReportUnavailable('The call has not ended yet.');
        }

        $out = [];
        foreach ($this->pages($token, self::API."/{$record['name']}/participants", 'participants') as $p) {
            $who = $p['signedinUser'] ?? $p['anonymousUser'] ?? $p['phoneUser'] ?? [];
            foreach ($this->pages($token, self::API."/{$p['name']}/participantSessions", 'participantSessions') as $s) {
                if (empty($s['startTime']) || empty($s['endTime'])) {
                    continue;
                }
                $out[] = [
                    'key' => (string) ($who['user'] ?? $p['name']),
                    'name' => (string) ($who['displayName'] ?? ''),
                    'email' => null, // the Meet API gives a user id, not an email
                    'joined_at' => Carbon::parse($s['startTime']),
                    'left_at' => Carbon::parse($s['endTime']),
                ];
            }
        }

        return $out;
    }

    /** An access token from a signed service-account assertion (RFC 7523). */
    public function token(array $creds): string
    {
        $key = json_decode((string) ($creds['service_account_json'] ?? ''), true);
        if (! is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            throw new RuntimeException('The service account JSON key is not valid.');
        }
        $now = time();
        $segments = [
            $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->b64(json_encode(['iss' => $key['client_email'], 'sub' => $creds['impersonate'] ?? null, 'scope' => self::SCOPE,
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600])),
        ];
        if (! openssl_sign(implode('.', $segments), $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The service account key could not sign a request.');
        }
        $segments[] = $this->b64($signature);

        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => implode('.', $segments)]);
        if (! $res->successful() || ! $res->json('access_token')) {
            throw new RuntimeException('Google did not accept the service account ('.($res->json('error_description') ?? $res->json('error') ?? $res->status()).').');
        }

        return (string) $res->json('access_token');
    }

    private function pages(string $token, string $url, string $field): iterable
    {
        $next = null;
        do {
            $res = Http::withToken($token)->get($url, array_filter(['pageSize' => 250, 'pageToken' => $next]));
            if (! $res->successful()) {
                throw new RuntimeException('Google refused the participant list: '.($res->json('error.message') ?? $res->status()));
            }
            yield from $res->json($field) ?? [];
            $next = $res->json('nextPageToken') ?: null;
        } while ($next);
    }

    private function meetingCode(string $text): ?string
    {
        return preg_match('/\b([a-z]{3}-[a-z]{4}-[a-z]{3})\b/', strtolower($text), $m) ? $m[1] : null;
    }

    private function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
