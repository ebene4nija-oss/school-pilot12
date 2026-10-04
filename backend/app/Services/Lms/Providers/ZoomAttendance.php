<?php

namespace App\Services\Lms\Providers;

use App\Models\LiveClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Participants of a Zoom meeting, from the school's own Zoom account through
 * a Server-to-Server OAuth app (account_id, client_id, client_secret) with
 * the report:read scope. A recurring meeting id has many sittings, so the
 * sitting that started nearest the class's scheduled time is used.
 */
class ZoomAttendance
{
    private const API = 'https://api.zoom.us/v2';

    /** @return array<int, array{key: string, name: string, email: ?string, joined_at: Carbon, left_at: Carbon}> */
    public function sessions(LiveClass $lc, array $creds): array
    {
        $meetingId = preg_replace('/\D/', '', (string) ($lc->meeting_id ?: $this->idFromUrl((string) $lc->meeting_url)));
        if (! $meetingId) {
            throw new RuntimeException('This class has no Zoom meeting ID.');
        }
        $token = $this->token($creds);

        $instances = Http::withToken($token)->get(self::API."/past_meetings/{$meetingId}/instances");
        if ($instances->status() === 404 || ! $instances->successful()) {
            throw new ProviderReportUnavailable('Zoom has no record of this meeting yet.');
        }
        $uuid = collect($instances->json('meetings') ?? [])
            ->sortBy(fn ($m) => abs(Carbon::parse($m['start_time'])->diffInSeconds($lc->scheduled_for, false)))
            ->first()['uuid'] ?? null;
        if (! $uuid) {
            throw new ProviderReportUnavailable('Zoom has no sitting of this meeting yet.');
        }
        // Zoom asks for UUIDs that start with "/" or contain "//" to be encoded twice.
        $ref = str_starts_with($uuid, '/') || str_contains($uuid, '//') ? urlencode(urlencode($uuid)) : urlencode($uuid);

        $out = [];
        $next = null;
        do {
            $res = Http::withToken($token)->get(self::API."/report/meetings/{$ref}/participants", array_filter(['page_size' => 300, 'next_page_token' => $next]));
            if ($res->status() === 404) {
                throw new ProviderReportUnavailable('Zoom has not published the participant report yet.');
            }
            if (! $res->successful()) {
                throw new RuntimeException('Zoom refused the participant report: '.($res->json('message') ?? $res->status()));
            }
            foreach ($res->json('participants') ?? [] as $p) {
                if (empty($p['join_time']) || empty($p['leave_time'])) {
                    continue;
                }
                $out[] = [
                    'key' => (string) ($p['user_id'] ?? $p['id'] ?? $p['name']),
                    'name' => (string) ($p['name'] ?? ''),
                    'email' => ($p['user_email'] ?? '') ?: null,
                    'joined_at' => Carbon::parse($p['join_time']),
                    'left_at' => Carbon::parse($p['leave_time']),
                ];
            }
            $next = $res->json('next_page_token') ?: null;
        } while ($next);

        return $out;
    }

    public function token(array $creds): string
    {
        $res = Http::asForm()->withBasicAuth((string) ($creds['client_id'] ?? ''), (string) ($creds['client_secret'] ?? ''))
            ->post('https://zoom.us/oauth/token', ['grant_type' => 'account_credentials', 'account_id' => $creds['account_id'] ?? '']);
        if (! $res->successful() || ! $res->json('access_token')) {
            throw new RuntimeException('Zoom did not accept these credentials ('.($res->json('reason') ?? $res->json('error') ?? $res->status()).').');
        }

        return (string) $res->json('access_token');
    }

    private function idFromUrl(string $url): ?string
    {
        return preg_match('#/j/(\d+)#', $url, $m) ? $m[1] : null;
    }
}
