<?php

namespace App\Services\Lms;

use App\Exceptions\DomainRefusal;
use App\Models\LiveClass;
use App\Models\LiveClassParticipant;
use App\Models\VideoProviderConnection;
use App\Services\Lms\Providers\GoogleMeetAttendance;
use App\Services\Lms\Providers\ProviderReportUnavailable;
use App\Services\Lms\Providers\ZoomAttendance;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Virtual-class attendance from the provider's own participant report
 * (TASK 14 §3.3). Each person in the call is matched to a student in the
 * class — by account email, by the "(admission number)" tag SchoolPilot puts
 * on the Zoom join name, by a remembered alias, or by a unique full name —
 * and their time in the call, within the lesson, replaces the time measured
 * on the lesson page. Anyone not matched waits for the teacher to say who
 * it was. Teacher corrections still win over both.
 */
class ProviderAttendanceService
{
    public const PROVIDERS = ['zoom', 'google_meet'];

    /** How many times a missing report is retried (every 15 minutes) before giving up. */
    public const MAX_ATTEMPTS = 12;

    public function __construct(private LmsService $lms, private ZoomAttendance $zoom, private GoogleMeetAttendance $meet)
    {
    }

    public function connection(LiveClass $lc): ?VideoProviderConnection
    {
        return VideoProviderConnection::withoutGlobalScopes()->where('school_id', $lc->school_id)->where('provider', $lc->provider)->first();
    }

    /** @return array{matched: int, unmatched: int, students: int} */
    public function sync(LiveClass $lc): array
    {
        if (! in_array($lc->provider, self::PROVIDERS, true)) {
            throw new DomainRefusal('Attendance can be read from Zoom or Google Meet only.');
        }
        $conn = $this->connection($lc);
        if (! $conn) {
            throw new DomainRefusal('The school has not connected its '.($lc->provider === 'zoom' ? 'Zoom' : 'Google Workspace').' account. An admin can do this in Learning settings.');
        }

        $lc->increment('provider_sync_attempts');
        try {
            $sessions = $lc->provider === 'zoom' ? $this->zoom->sessions($lc, $conn->credentials) : $this->meet->sessions($lc, $conn->credentials);
        } catch (ProviderReportUnavailable $e) {
            $lc->update(['provider_sync_status' => $lc->provider_sync_attempts >= self::MAX_ATTEMPTS ? 'no_report' : 'waiting', 'provider_sync_error' => $e->getMessage()]);
            throw new DomainRefusal($e->getMessage().' SchoolPilot will try again.');
        } catch (Throwable $e) {
            $lc->update(['provider_sync_status' => 'failed', 'provider_sync_error' => Str::limit($e->getMessage(), 490)]);
            $conn->update(['status' => 'failing', 'last_error' => Str::limit($e->getMessage(), 490)]);
            throw new DomainRefusal('Could not read attendance from the provider: '.$e->getMessage());
        }

        $result = DB::transaction(function () use ($lc, $sessions) {
            $roster = $this->roster($lc);
            $teacher = DB::table('users')->where('id', $lc->teacher_id)->first(['name', 'email']);
            $aliases = DB::table('video_participant_aliases')->where('school_id', $lc->school_id)->where('class_id', $lc->class_id)->pluck('student_id', 'alias');

            DB::table('live_class_provider_sessions')->where('live_class_id', $lc->id)->delete();
            $unmatched = [];
            foreach ($sessions as $s) {
                if ($teacher && ($this->norm($s['name']) === $this->norm($teacher->name) || ($s['email'] && strcasecmp($s['email'], (string) $teacher->email) === 0))) {
                    continue; // the teacher's own time is not a student's attendance
                }
                [$studentId, $how] = $this->match($s, $roster, $aliases);
                if (! $studentId) {
                    $unmatched[$s['key']] = true;
                }
                DB::table('live_class_provider_sessions')->insert([
                    'school_id' => $lc->school_id, 'live_class_id' => $lc->id, 'participant_key' => Str::limit($s['key'], 180, ''),
                    'student_id' => $studentId, 'display_name' => $studentId ? null : Str::limit($s['name'] ?: 'Unknown', 180, ''), 'match' => $how,
                    'joined_at' => $s['joined_at'], 'left_at' => $s['left_at'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $students = $this->aggregate($lc);

            return ['matched' => $students, 'unmatched' => count($unmatched), 'students' => $roster->count()];
        });

        $lc->update(['provider_sync_status' => 'synced', 'provider_synced_at' => now(), 'provider_sync_error' => null]);
        $conn->update(['status' => 'connected', 'last_error' => null, 'last_synced_at' => now()]);

        return $result;
    }

    /** The teacher says who an unmatched participant was; optionally remembered for this class. */
    public function assign(LiveClass $lc, string $participantKey, int $studentId, bool $remember): void
    {
        if (! $this->roster($lc)->contains('id', $studentId)) {
            throw new DomainRefusal('That student is not in this class.');
        }
        $name = DB::table('live_class_provider_sessions')->where('live_class_id', $lc->id)->where('participant_key', $participantKey)->value('display_name');
        if ($name === null) {
            throw new DomainRefusal('That participant is not waiting to be matched.');
        }

        DB::table('live_class_provider_sessions')->where('live_class_id', $lc->id)->where('participant_key', $participantKey)
            ->update(['student_id' => $studentId, 'display_name' => null, 'match' => 'teacher', 'updated_at' => now()]);
        if ($remember && $this->norm($name) !== '') {
            DB::table('video_participant_aliases')->updateOrInsert(
                ['school_id' => $lc->school_id, 'class_id' => $lc->class_id, 'alias' => $this->norm($name)],
                ['student_id' => $studentId, 'updated_at' => now(), 'created_at' => now()],
            );
        }
        $this->aggregate($lc);
    }

    /** Participants nobody could match, one row per person. */
    public function unmatched(LiveClass $lc): Collection
    {
        return DB::table('live_class_provider_sessions')->where('live_class_id', $lc->id)->whereNull('student_id')
            ->selectRaw('participant_key, MIN(display_name) as name, MIN(joined_at) as joined_at, MAX(left_at) as left_at')
            ->groupBy('participant_key')->get();
    }

    /** Classes due a sync: finished, on a connected provider, not yet read. */
    public function due(): Collection
    {
        $connected = VideoProviderConnection::withoutGlobalScopes()->get(['school_id', 'provider'])->map(fn ($c) => $c->school_id.'|'.$c->provider)->all();

        return LiveClass::withoutGlobalScopes()->whereIn('provider', self::PROVIDERS)->where('status', '!=', 'cancelled')
            ->where('scheduled_for', '>', now()->subDays(2))->where('scheduled_for', '<', now())
            ->where(fn ($q) => $q->whereNull('provider_sync_status')->orWhere('provider_sync_status', 'waiting'))
            ->where('provider_sync_attempts', '<', self::MAX_ATTEMPTS)
            ->get()
            ->filter(fn ($lc) => in_array($lc->school_id.'|'.$lc->provider, $connected, true)
                && $lc->scheduled_for->copy()->addMinutes(($lc->duration_minutes ?: 40) + 10)->isPast())
            ->values();
    }

    /** Merge each student's stretches in the call, within the lesson, into time and first join. */
    private function aggregate(LiveClass $lc): int
    {
        $start = $lc->scheduled_for->copy();
        $end = $start->copy()->addMinutes($lc->duration_minutes ?: 40);
        $byStudent = DB::table('live_class_provider_sessions')->where('live_class_id', $lc->id)->whereNotNull('student_id')->get()->groupBy('student_id');

        // Everyone who opened the lesson page but was not in the call now reads as absent.
        LiveClassParticipant::where('live_class_id', $lc->id)->update(['provider_seconds' => 0, 'provider_first_joined_at' => null]);

        foreach ($byStudent as $studentId => $rows) {
            $spans = $rows->map(fn ($r) => [max(Carbon::parse($r->joined_at)->getTimestamp(), $start->getTimestamp()), min(Carbon::parse($r->left_at)->getTimestamp(), $end->getTimestamp())])
                ->filter(fn ($s) => $s[1] > $s[0])->sortBy(0)->values();
            $seconds = 0;
            $cur = null;
            foreach ($spans as $s) {
                if ($cur && $s[0] <= $cur[1]) {
                    $cur[1] = max($cur[1], $s[1]); // overlapping devices or rejoins are not counted twice
                } else {
                    $seconds += $cur ? $cur[1] - $cur[0] : 0;
                    $cur = $s;
                }
            }
            $seconds += $cur ? $cur[1] - $cur[0] : 0;

            LiveClassParticipant::updateOrCreate(
                ['live_class_id' => $lc->id, 'student_id' => (int) $studentId],
                ['school_id' => $lc->school_id, 'provider_seconds' => $seconds, 'provider_first_joined_at' => Carbon::parse($rows->min('joined_at'))],
            );
        }

        return $byStudent->count();
    }

    /** @return array{0: ?int, 1: ?string} */
    private function match(array $s, Collection $roster, Collection $aliases): array
    {
        if ($s['email'] && ($hit = $roster->first(fn ($st) => $st->email && strcasecmp($st->email, $s['email']) === 0))) {
            return [$hit->id, 'email'];
        }
        if (preg_match('/\(([^()]+)\)\s*$/', $s['name'], $m) && ($hit = $roster->first(fn ($st) => $st->admission_number && strcasecmp($st->admission_number, trim($m[1])) === 0))) {
            return [$hit->id, 'tag'];
        }
        $name = $this->norm($s['name']);
        if (isset($aliases[$name]) && $roster->contains('id', (int) $aliases[$name])) {
            return [(int) $aliases[$name], 'alias'];
        }
        $same = $roster->filter(fn ($st) => $this->norm($st->name) === $name);
        if ($name !== '' && $same->count() === 1) {
            return [$same->first()->id, 'name'];
        }

        return [null, null];
    }

    private function roster(LiveClass $lc): Collection
    {
        $students = $this->lms->studentsOf($lc->school_id, $lc->class_id, $lc->arm_id);
        $users = DB::table('users')->whereIn('id', $students->pluck('user_id')->filter())->get(['id', 'name', 'email'])->keyBy('id');

        return $students->map(fn ($s) => (object) ['id' => (int) $s->id, 'admission_number' => $s->admission_number,
            'name' => $users[$s->user_id]->name ?? '', 'email' => $users[$s->user_id]->email ?? null]);
    }

    /** "  ADA  obi " and "Ada Obi" are the same name. */
    private function norm(?string $name): string
    {
        $name = preg_replace('/\s*\([^()]*\)\s*$/', '', (string) $name);

        return Str::lower(trim(preg_replace('/\s+/', ' ', Str::ascii((string) $name))));
    }
}
