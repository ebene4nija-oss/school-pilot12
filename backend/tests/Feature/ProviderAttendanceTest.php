<?php

namespace Tests\Feature;

use App\Models\LiveClass;
use App\Models\LiveClassParticipant;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\VideoProviderConnection;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Virtual-class attendance read from Zoom and Google Meet (TASK 14 §3.3). */
class ProviderAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $teacher;
    private SchoolClass $jss1;
    private Student $ada;
    private Student $tunde;
    private Student $chioma;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->admin = $this->user('Admin', 'school_admin');
        $this->teacher = $this->user('Mr Chidi', 'teacher');
        $this->ada = $this->student('Ada Obi', 'HT/001', 'ada@pupils.hilltop.test');
        $this->tunde = $this->student('Tunde Bello', 'HT/002');
        $this->chioma = $this->student('Chioma Eze', 'HT/003');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, string $role, ?string $email = null): User
    {
        $u = User::create(['name' => $name, 'email' => $email ?? str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function student(string $name, string $no, ?string $email = null): Student
    {
        return Student::create(['school_id' => $this->school->id, 'user_id' => $this->user($name, 'student', $email)->id, 'class_id' => $this->jss1->id, 'admission_number' => $no, 'status' => 'active']);
    }

    private function liveClass(string $provider, string $url, ?string $meetingId = null): LiveClass
    {
        return LiveClass::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'subject_id' => Subject::create(['school_id' => $this->school->id, 'name' => 'Maths '.uniqid(), 'code' => 'M'.rand(10, 99)])->id,
            'teacher_id' => $this->teacher->id, 'title' => 'Fractions live', 'provider' => $provider, 'meeting_url' => $url, 'meeting_id' => $meetingId,
            'scheduled_for' => '2026-10-07 10:00:00', 'duration_minutes' => 40, 'status' => 'scheduled']);
    }

    private function connectZoom(): void
    {
        Http::fake(['zoom.us/oauth/token' => Http::response(['access_token' => 'tok'])]);
        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/live-classes/providers/zoom", ['account_id' => 'acc', 'client_id' => 'cid', 'client_secret' => 'secret'])->assertOk();
    }

    private function fakeZoomReport(array $participants): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok']),
            'api.zoom.us/v2/past_meetings/*' => Http::response(['meetings' => [['uuid' => 'last-week==', 'start_time' => '2026-09-30T09:00:00Z'], ['uuid' => 'today==', 'start_time' => '2026-10-07T09:58:00Z']]]),
            'api.zoom.us/v2/report/meetings/today%3D%3D/participants*' => Http::response(['participants' => $participants, 'next_page_token' => '']),
            'api.zoom.us/*' => Http::response([], 404),
        ]);
    }

    private function zp(string $name, string $join, string $leave, ?string $email = null, ?string $id = null): array
    {
        return ['id' => $id ?? md5($name), 'user_id' => $id ?? md5($name), 'name' => $name, 'user_email' => $email ?? '', 'join_time' => "2026-10-07T{$join}:00Z", 'leave_time' => "2026-10-07T{$leave}:00Z"];
    }

    public function test_credentials_are_checked_encrypted_and_never_returned(): void
    {
        Http::fake(['zoom.us/oauth/token' => Http::sequence()->push(['reason' => 'Invalid client_id or client_secret'], 401)->push(['access_token' => 'tok'])]);
        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/live-classes/providers/zoom", ['account_id' => 'a', 'client_id' => 'bad', 'client_secret' => 'bad'])
            ->assertStatus(422)->assertJsonPath('message', 'Zoom did not accept these credentials (Invalid client_id or client_secret).');

        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/live-classes/providers/zoom", ['account_id' => 'acc', 'client_id' => 'cid', 'client_secret' => 'secret'])->assertOk();
        $this->assertStringNotContainsString('secret', (string) DB::table('video_provider_connections')->value('credentials'));
        $this->assertSame('secret', VideoProviderConnection::first()->credentials['client_secret']);
        $res = $this->actingAs($this->admin, 'sanctum')->getJson("{$this->host}/live-classes/providers")->assertOk();
        $this->assertStringNotContainsString('secret', $res->getContent());
        $this->actingAs($this->teacher, 'sanctum')->getJson("{$this->host}/live-classes/providers")->assertForbidden();
    }

    public function test_zoom_report_replaces_page_time_and_matches_by_email_tag_and_name(): void
    {
        $this->connectZoom();
        $lc = $this->liveClass('zoom', 'https://zoom.us/j/81234567890?pwd=x', '812 3456 7890');
        // Tunde had the lesson page open the whole time but never got into the call.
        LiveClassParticipant::create(['school_id' => $this->school->id, 'live_class_id' => $lc->id, 'student_id' => $this->tunde->id, 'first_joined_at' => '2026-10-07 09:59:00', 'seconds_connected' => 2400, 'joins' => 1]);

        $this->fakeZoomReport([
            $this->zp('Mr Chidi', '09:58', '10:41'),                                   // the teacher: ignored
            $this->zp('Ada', '09:59', '10:20', 'ADA@pupils.hilltop.test', 'u1'),       // email
            $this->zp('Ada', '10:18', '10:40', 'ada@pupils.hilltop.test', 'u1'),       // rejoined: overlap not double-counted
            $this->zp('Chioma Eze (HT/003)', '10:12', '10:40'),                        // join-name tag, late
            $this->zp("Mummy's iPad", '10:00', '10:40'),                               // nobody knows yet
        ]);

        $res = $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/live-classes/{$lc->id}/provider-attendance")->assertOk();
        $this->assertSame([2, 1], [$res->json('matched'), $res->json('unmatched')]);

        $sheet = $this->actingAs($this->teacher, 'sanctum')->getJson("{$this->host}/live-classes/{$lc->id}/attendance")->assertOk();
        $this->assertSame('provider', $sheet->json('source'));
        $rows = collect($sheet->json('students'))->keyBy('admission_number');
        $this->assertSame('present', $rows['HT/001']['status']);
        $this->assertEquals(40, $rows['HT/001']['minutes_in_call'], 'Counted from 10:00, the start of the lesson.');
        $this->assertSame('late', $rows['HT/003']['status']);
        $this->assertSame('absent', $rows['HT/002']['status'], 'Page open, not in the call.');
        $this->assertSame("Mummy's iPad", $sheet->json('unmatched.0.name'));
        $this->assertSame(0, DB::table('live_class_provider_sessions')->whereNotNull('student_id')->whereNotNull('display_name')->count(), 'Names of matched people are not kept.');
    }

    public function test_the_teacher_matches_a_participant_and_it_is_remembered_next_time(): void
    {
        $this->connectZoom();
        $lc = $this->liveClass('zoom', 'https://zoom.us/j/81234567890');
        $this->fakeZoomReport([$this->zp("Mummy's iPad", '10:00', '10:40', null, 'ipad')]);
        $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/live-classes/{$lc->id}/provider-attendance")->assertOk();

        $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/live-classes/{$lc->id}/provider-attendance/match", ['participant_key' => 'ipad', 'student_id' => $this->tunde->id])->assertOk();
        $rows = collect($this->actingAs($this->teacher, 'sanctum')->getJson("{$this->host}/live-classes/{$lc->id}/attendance")->json('students'))->keyBy('admission_number');
        $this->assertSame('present', $rows['HT/002']['status']);

        // Next week the same device is recognised.
        $next = $this->liveClass('zoom', 'https://zoom.us/j/81234567890');
        $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/live-classes/{$next->id}/provider-attendance")->assertOk()->assertJsonPath('unmatched', 0);
    }

    public function test_a_report_not_yet_published_is_retried_by_the_scheduled_run(): void
    {
        $this->connectZoom();
        $lc = $this->liveClass('zoom', 'https://zoom.us/j/81234567890');
        Http::fake(['zoom.us/oauth/token' => Http::response(['access_token' => 'tok']), 'api.zoom.us/*' => Http::response([], 404)]);

        $this->artisan('lms:sync-attendance')->assertSuccessful();
        $this->assertSame('waiting', $lc->fresh()->provider_sync_status);
        $this->assertSame('page', $this->actingAs($this->teacher, 'sanctum')->getJson("{$this->host}/live-classes/{$lc->id}/attendance")->json('source'));
    }

    public function test_zoom_join_links_carry_the_admission_number(): void
    {
        $lc = $this->liveClass('zoom', 'https://zoom.us/j/81234567890?pwd=abc');
        $lc->update(['scheduled_for' => now()->addMinutes(2)]);
        $url = $this->actingAs($this->ada->user, 'sanctum')->postJson("{$this->host}/live-classes/{$lc->id}/join")->assertOk()->json('meeting_url');

        $this->assertSame('https://zoom.us/j/81234567890?pwd=abc&uname=Ada%20Obi%20%28HT%2F001%29', $url);
    }

    public function test_google_meet_participants_are_read_with_a_service_account(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])
            ?: openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => dirname(PHP_BINARY).'/extras/ssl/openssl.cnf']);
        if (! $key) {
            $this->markTestSkipped('OpenSSL cannot generate a key on this machine.');
        }
        openssl_pkey_export($key, $pem, null, is_file(dirname(PHP_BINARY).'/extras/ssl/openssl.cnf') ? ['config' => dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'] : []);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'gtok']),
            'meet.googleapis.com/v2/conferenceRecords?*' => Http::response(['conferenceRecords' => [['name' => 'conferenceRecords/rec1', 'startTime' => '2026-10-07T09:59:00Z', 'endTime' => '2026-10-07T10:41:00Z']]]),
            'meet.googleapis.com/v2/conferenceRecords/rec1/participants?*' => Http::response(['participants' => [
                ['name' => 'conferenceRecords/rec1/participants/p1', 'signedinUser' => ['user' => 'users/111', 'displayName' => 'Tunde Bello']],
            ]]),
            'meet.googleapis.com/v2/conferenceRecords/rec1/participants/p1/participantSessions*' => Http::response(['participantSessions' => [
                ['startTime' => '2026-10-07T10:02:00Z', 'endTime' => '2026-10-07T10:15:00Z'],
            ]]),
        ]);

        $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/live-classes/providers/google_meet", [
            'service_account_json' => json_encode(['client_email' => 'sp@hilltop.iam.gserviceaccount.com', 'private_key' => $pem]), 'impersonate' => 'it@hilltop.test',
        ])->assertOk();

        $lc = $this->liveClass('google_meet', 'https://meet.google.com/abc-defg-hij');
        $this->actingAs($this->teacher, 'sanctum')->postJson("{$this->host}/live-classes/{$lc->id}/provider-attendance")->assertOk()->assertJsonPath('matched', 1);

        $row = collect($this->actingAs($this->teacher, 'sanctum')->getJson("{$this->host}/live-classes/{$lc->id}/attendance")->json('students'))->firstWhere('admission_number', 'HT/002');
        $this->assertSame('partial', $row['status'], '13 of 40 minutes.');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'filter=space.meeting_code') && str_contains(urldecode($r->url()), '"abc-defg-hij"'));
    }
}
