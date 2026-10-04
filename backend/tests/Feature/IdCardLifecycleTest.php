<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\IdCard;
use App\Models\IdCardPrintRun;
use App\Models\IdCardSetting;
use App\Models\NotificationLog;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\IdCard\IdCardIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** ID card production, issuance, renewal and digital cards (TASK 18). */
class IdCardLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://graceland.localhost');
        Http::preventStrayRequests();

        $this->school = School::create(['name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland']);
        $this->admin = $this->user('Mr Bello', 'school_admin');
        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addDays(20), 'is_current' => true]);
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@graceland.test', 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u, 'sanctum')->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    private function student(string $name): Student
    {
        $path = 'photos/'.str()->slug($name).'.png';
        $full = storage_path('app/public/'.$path);
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $this->png(400, 500));

        return Student::create(['school_id' => $this->school->id, 'user_id' => $this->user($name, 'student')->id, 'class_id' => $this->class->id, 'admission_number' => 'GC/'.random_int(1000, 9999), 'passport_photo_path' => $path, 'status' => 'active']);
    }

    private function png(int $w, int $h): string
    {
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\x00".str_repeat("\xC0", $w);
        }
        $chunk = fn ($t, $d) => pack('N', strlen($d)).$t.$d.pack('N', crc32($t.$d));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 1, 0, 0, 0, 0)).$chunk('IDAT', gzcompress($raw, 9)).$chunk('IEND', '');
    }

    private function printRun(): IdCardPrintRun
    {
        $this->as($this->admin)->postJson('/api/v1/id-cards/print-runs', ['holder_type' => 'student', 'class_id' => $this->class->id])->assertStatus(202);
        $run = IdCardPrintRun::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('completed', $run->status, (string) $run->error);

        return $run;
    }

    private function card(Student $s): IdCard
    {
        return IdCard::withoutGlobalScopes()->forHolder('student', $s->id)->orderByDesc('id')->first();
    }

    public function test_generated_print_file_printed_and_handed_over_are_separate_facts(): void
    {
        $ada = $this->student('Ada Obi');
        $bayo = $this->student('Bayo Ade');
        $run = $this->printRun();

        $this->assertSame('print_ready', $this->card($ada)->production_status);
        $this->as($this->admin)->get("/api/v1/id-cards/print-runs/{$run->id}/download")->assertOk();
        $this->assertSame('print_ready', $this->card($ada)->production_status, 'Downloading the PDF prints nothing.');

        // A printed card can't be handed over before the print is confirmed.
        $this->as($this->admin)->postJson('/api/v1/id-cards/hand-over', ['card_ids' => [$this->card($ada)->id]])->assertJsonPath('results.0.ok', false);

        $this->as($this->admin)->postJson("/api/v1/id-cards/print-runs/{$run->id}/confirm-printed", ['misprinted_card_ids' => [$this->card($bayo)->id]])
            ->assertOk()->assertJsonPath('printed', 1)->assertJsonPath('misprinted', 1);
        $this->as($this->admin)->postJson("/api/v1/id-cards/print-runs/{$run->id}/confirm-printed")->assertStatus(409);

        $this->assertSame('printed', $this->card($ada)->production_status);
        $this->assertSame('print_ready', $this->card($bayo)->production_status, 'A misprint waits for the next run.');
        $this->assertTrue(NotificationLog::withoutGlobalScopes()->where('user_id', $ada->user_id)->where('body', 'like', 'ID card ready:%')->exists());
        $this->assertFalse(NotificationLog::withoutGlobalScopes()->where('user_id', $bayo->user_id)->where('body', 'like', 'ID card ready:%')->exists());

        $this->as($this->admin)->postJson('/api/v1/id-cards/hand-over', ['card_ids' => [$this->card($ada)->id], 'collected_by' => 'Mrs Obi (mother)'])->assertJsonPath('handed_over', 1);
        $card = $this->card($ada);
        $this->assertSame('issued', $card->production_status);
        $this->assertSame('Mrs Obi (mother)', $card->collected_by);

        $events = array_column($this->as($this->admin)->getJson("/api/v1/id-cards/{$card->id}/events")->json('data'), 'event');
        $this->assertSame(['generated', 'print_file_made', 'printed', 'handed_over'], $events);
    }

    public function test_with_the_issuance_rule_on_a_card_verifies_only_once_handed_over(): void
    {
        IdCardSetting::create(['school_id' => $this->school->id, 'valid_only_when_issued' => true]);
        $ada = $this->student('Ada Obi');
        $run = $this->printRun();
        $card = $this->card($ada);

        $this->getJson("/api/v1/verify-id/{$card->verify_token}?format=json")->assertJsonPath('status', 'not_issued')->assertJsonPath('valid', false);

        $this->as($this->admin)->postJson("/api/v1/id-cards/print-runs/{$run->id}/confirm-printed")->assertOk();
        $this->as($this->admin)->postJson('/api/v1/id-cards/hand-over', ['card_ids' => [$card->id]])->assertJsonPath('handed_over', 1);

        $this->getJson("/api/v1/verify-id/{$card->verify_token}?format=json")->assertJsonPath('valid', true);
        $this->assertSame(['not_issued', 'active'], DB::table('id_card_verification_logs')->orderBy('id')->pluck('outcome')->all());
    }

    public function test_leaving_the_school_ends_the_card(): void
    {
        $ada = $this->student('Ada Obi');
        $card = app(IdCardIssuanceService::class)->issue($this->school->id, 'student', $ada->id);

        $ada->update(['status' => 'withdrawn']);

        $card->refresh();
        $this->assertSame('revoked', $card->status);
        $this->assertSame('left_school', $card->revoked_reason);
        $this->getJson("/api/v1/verify-id/{$card->verify_token}?format=json")->assertJsonPath('valid', false);
    }

    public function test_dashboard_counts_follow_the_cards(): void
    {
        $ada = $this->student('Ada Obi');
        $this->student('Bayo Ade');
        $this->student('Chika Eze');
        $issued = app(IdCardIssuanceService::class)->issue($this->school->id, 'student', $ada->id);
        app(IdCardIssuanceService::class)->replace($issued, 'lost', $this->admin->id);

        $s = $this->as($this->admin)->getJson('/api/v1/id-cards/dashboard?holder_type=student')->assertOk()->json('summary');
        $this->assertSame(3, $s['eligible']);
        $this->assertSame(2, $s['not_generated']);
        $this->assertSame(1, $s['generated']);
        $this->assertSame(1, $s['pending_print']);
        $this->assertSame(1, $s['deactivated']);
        $this->assertSame(0, $s['lost_replacement_pending'], 'The lost card has its replacement.');
        $this->assertSame(1, $s['expiring_soon'], 'The session ends in 20 days.');
    }

    public function test_renewal_supersedes_the_old_card_and_keeps_it(): void
    {
        $ada = $this->student('Ada Obi');
        $old = app(IdCardIssuanceService::class)->issue($this->school->id, 'student', $ada->id);

        $this->assertSame([$old->id], array_column($this->as($this->admin)->getJson('/api/v1/id-cards/renewals')->json('data'), 'id'));
        $this->as($this->admin)->postJson('/api/v1/id-cards/renew', ['card_ids' => [$old->id], 'expires_on' => now()->addYear()->toDateString()])->assertJsonPath('renewed', 1);

        $this->assertSame('replaced', $old->fresh()->status);
        $new = $this->card($ada);
        $this->assertSame($old->id, (int) $new->replaces_id);
        $this->assertNotSame($old->serial, $new->serial);
        $this->assertSame([], $this->as($this->admin)->getJson('/api/v1/id-cards/renewals')->json('data'));
    }

    public function test_digital_cards_show_live_status_and_only_where_enabled(): void
    {
        $ada = $this->student('Ada Obi');
        $parent = $this->user('Mrs Obi', 'parent');
        Guardian::create(['school_id' => $this->school->id, 'user_id' => $parent->id])->students()->attach($ada->id);
        $card = app(IdCardIssuanceService::class)->issue($this->school->id, 'student', $ada->id);

        $this->as($ada->user)->getJson('/api/v1/me/id-card')->assertNotFound();

        IdCardSetting::create(['school_id' => $this->school->id, 'digital_cards_enabled' => true]);
        $mine = $this->as($ada->user)->getJson('/api/v1/me/id-card')->assertOk()->json('card');
        $this->assertTrue($mine['valid']);
        $this->assertStringStartsWith('data:image', (string) $mine['qr']);
        $this->as($parent)->getJson('/api/v1/parent/id-cards')->assertNotFound();

        IdCardSetting::where('school_id', $this->school->id)->update(['parents_see_digital_card' => true]);
        $this->assertSame($card->serial, $this->as($parent)->getJson('/api/v1/parent/id-cards')->assertOk()->json('children.0.card.serial'));

        app(IdCardIssuanceService::class)->revoke($card, 'lost', $this->admin->id);
        $mine = $this->as($ada->user)->getJson('/api/v1/me/id-card')->json('card');
        $this->assertSame('revoked', $mine['status']);
        $this->assertNull($mine['qr'], 'A dead card carries no scannable code.');
    }

    public function test_staff_check_a_card_by_number_and_it_is_logged(): void
    {
        $ada = $this->student('Ada Obi');
        $card = app(IdCardIssuanceService::class)->issue($this->school->id, 'student', $ada->id);
        $teacher = $this->user('Mr Chidi', 'teacher');

        $this->as($teacher)->postJson('/api/v1/id-cards/lookup', ['serial' => $card->serial])->assertOk()->assertJsonPath('valid', true)->assertJsonPath('holder_name', 'Ada Obi');
        $this->as($teacher)->postJson('/api/v1/id-cards/lookup', ['serial' => 'NOPE-1'])->assertNotFound();
        $this->as($ada->user)->postJson('/api/v1/id-cards/lookup', ['serial' => $card->serial])->assertForbidden();

        $log = $this->as($this->admin)->getJson('/api/v1/id-cards/verification-log')->json('data');
        $this->assertCount(2, $log);
        $this->assertSame('Mr Chidi', $log[1]['checked_by']);
    }

    public function test_staff_cards_follow_the_schools_validity_period(): void
    {
        $staffUser = $this->user('Mrs Bello', 'teacher');
        $staff = Staff::create(['school_id' => $this->school->id, 'user_id' => $staffUser->id, 'staff_id' => 'STF-001', 'designation' => 'Teacher']);

        $this->assertNull(app(IdCardIssuanceService::class)->issue($this->school->id, 'staff', $staff->id)->expires_on);

        IdCardSetting::create(['school_id' => $this->school->id, 'staff_validity_months' => 24]);
        $card = app(IdCardIssuanceService::class)->issue($this->school->id, 'staff', $staff->id, ['force_new' => true]);
        $this->assertSame(now()->addMonths(24)->toDateString(), $card->expires_on->toDateString());
    }
}
