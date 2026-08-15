<?php

namespace Tests\Feature;

use App\Jobs\GenerateIdCardRunJob;
use App\Models\AcademicSession;
use App\Models\IdCard;
use App\Models\IdCardTemplate;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\IdCard\CardImpositionService;
use App\Services\IdCard\IdCardIssuanceService;
use App\Services\IdCard\IdCardPrintService;
use App\Services\IdCard\IdCardTemplateService;
use App\Services\IdCard\PhotoPreflightService;
use App\Services\ReportCard\TemplateException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class IdCardTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $admin;
    protected SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://graceland.localhost');

        // Printing a card must make no outbound request at all: QR codes are
        // encoded locally, photos are read from disk, and the PDF renderer has
        // remote fetching off. A stray request fails the test.
        Http::preventStrayRequests();

        $this->school = School::create([
            'name' => 'Graceland College',
            'slug' => 'graceland',
            'subdomain' => 'graceland',
        ]);

        $this->admin = User::create([
            'name' => 'Mr Bello',
            'email' => 'bello@graceland.test',
            'password' => bcrypt('password'),
        ]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $this->admin->id, 'role' => 'school_admin']);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    private function makeStudent(string $name, ?string $photo = null): Student
    {
        $user = User::create([
            'name' => $name,
            'email' => str()->slug($name) . '@graceland.test',
            'password' => bcrypt('password'),
        ]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'role' => 'student']);

        return Student::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'class_id' => $this->class->id,
            'admission_number' => 'GC/2024/' . random_int(1000, 9999),
            'passport_photo_path' => $photo,
            'status' => 'active',
        ]);
    }

    /**
     * A real 2x2 PNG, written where the preflight service looks for photos.
     * Tiny on purpose — several tests care that a small photo is *warned*
     * about rather than refused.
     */
    private function writePhoto(string $relative, int $width = 400, int $height = 500): string
    {
        $path = storage_path('app/public/' . $relative);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $this->pngBytes($width, $height));

        return $relative;
    }

    /** A minimal valid PNG of the requested dimensions. */
    private function pngBytes(int $width, int $height): string
    {
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00" . str_repeat("\xC0", $width); // 1 bit/px, mostly white
        }

        $chunk = function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 1, 0, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw, 9))
            . $chunk('IEND', '');
    }

    private function bundle(array $overrides = []): array
    {
        return array_merge([
            'engine' => IdCardTemplate::ENGINE,
            'name' => 'Graceland Student Card',
            'slug' => 'graceland-student',
            'version' => 1,
            'holder_type' => 'student',
            'card' => ['size' => 'CR80', 'orientation' => 'landscape'],
            'styles' => '.card { font-size: 8pt; }',
            'front' => '<div class="card"><h1>{{ school.name }}</h1><p>{{ holder.name }}</p>'
                . '<p>{{ holder.id_label }} {{ holder.id_value }}</p>'
                . '<img src="{{ verification.qr_code_url }}" alt=""></div>',
            'back' => '<div class="card"><p>{{ card.serial }}</p><p>{{ verification.verify_url }}</p></div>',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Imposition — the maths that decides whether a run is usable
    // ------------------------------------------------------------------

    public function test_cr80_cards_lay_out_ten_to_an_a4_sheet(): void
    {
        $imposition = app(CardImpositionService::class);
        $geometry = app(IdCardTemplateService::class)->normaliseGeometry([]);

        $plan = $imposition->plan($geometry, ['sheet' => 'A4', 'margin_mm' => 10]);

        $this->assertSame(2, $plan['columns']);
        $this->assertSame(5, $plan['rows']);
        $this->assertSame(10, $plan['per_sheet']);

        // The grid must sit inside the paper, not hang off it.
        $gridRight = $plan['offset_x_mm'] + $plan['columns'] * $plan['slot_width_mm'];
        $gridBottom = $plan['offset_y_mm'] + $plan['rows'] * $plan['slot_height_mm'];
        $this->assertLessThanOrEqual($plan['sheet_width_mm'], $gridRight);
        $this->assertLessThanOrEqual($plan['sheet_height_mm'], $gridBottom);
    }

    /**
     * The bug this whole service exists to prevent: backs laid out in reading
     * order come off a duplex printer attached to the wrong fronts.
     */
    public function test_duplex_long_edge_flip_reverses_each_row_so_backs_meet_their_own_fronts(): void
    {
        $imposition = app(CardImpositionService::class);
        $plan = ['columns' => 2, 'rows' => 3, 'duplex' => 'long-edge'];

        $backs = ['a', 'b', 'c', 'd', 'e', 'f'];

        $this->assertSame(
            ['b', 'a', 'd', 'c', 'f', 'e'],
            $imposition->orderForDuplex($backs, $plan)
        );
    }

    public function test_duplex_short_edge_flip_reverses_the_rows_instead(): void
    {
        $imposition = app(CardImpositionService::class);
        $plan = ['columns' => 2, 'rows' => 3, 'duplex' => 'short-edge'];

        $this->assertSame(
            ['e', 'f', 'c', 'd', 'a', 'b'],
            $imposition->orderForDuplex(['a', 'b', 'c', 'd', 'e', 'f'], $plan)
        );
    }

    public function test_a_card_too_big_for_the_sheet_is_refused_with_a_usable_message(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('does not fit');

        // 120mm of card into 110mm of usable width: no arrangement fits.
        app(CardImpositionService::class)->plan(
            ['width_mm' => 120, 'height_mm' => 120, 'bleed_mm' => 0],
            ['sheet' => 'A4', 'margin_mm' => 50]
        );
    }

    /**
     * The plan promises a sheet count; the PDF has to actually have that many
     * pages. A stray `page-break-after` on the last sheet emits a trailing
     * blank page, which on a duplex run shifts every back onto the wrong side
     * of the paper for the rest of the stack.
     */
    public function test_the_pdf_has_exactly_the_pages_the_plan_promised(): void
    {
        $student = $this->makeStudent('Adaeze Nwosu', $this->writePhoto('photos/adaeze.png'));

        $this->asAdmin()->postJson('/api/v1/id-cards/print-runs', [
            'holder_type' => 'student',
            'class_id' => $this->class->id,
        ])->assertStatus(202);

        $run = \App\Models\IdCardPrintRun::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('completed', $run->status, (string) $run->error);

        $pdf = \Illuminate\Support\Facades\Storage::disk(app(IdCardPrintService::class)->disk())
            ->get($run->pdf_path);

        // dompdf writes one "/Type /Page" object per page, plus a "/Pages" tree
        // node the negative lookahead excludes.
        preg_match_all('#/Type\s*/Page(?!s)#', $pdf, $matches);

        $this->assertCount($run->sheet_count, $matches[0]);
        // One card, double-sided by default: a front sheet and a back sheet.
        $this->assertSame(2, $run->sheet_count);

        $this->assertNotNull($student->fresh()->passport_photo_path);
    }

    public function test_a_partly_filled_last_sheet_still_aligns_its_backs(): void
    {
        $imposition = app(CardImpositionService::class);
        $geometry = app(IdCardTemplateService::class)->normaliseGeometry([]);

        // 12 cards over a 10-up sheet: two fronts and two backs.
        $cards = array_fill(0, 12, ['front' => '<div class="card">F</div>', 'back' => '<div class="card">B</div>']);
        $result = $imposition->impose($cards, $geometry, '', ['duplex' => 'long-edge']);

        $this->assertSame(4, $result['sheet_count']);
        $this->assertSame(4, substr_count($result['html'], 'class="sheet"'));
    }

    // ------------------------------------------------------------------
    // Design import
    // ------------------------------------------------------------------

    public function test_a_valid_bundle_imports_as_a_draft(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/id-cards/templates', $this->bundle());

        $response->assertStatus(201)
            ->assertJsonPath('template.status', 'draft')
            ->assertJsonPath('template.holder_type', 'student');

        // Geometry is stored resolved, so the card keeps its size even if the
        // named-size table is ever edited.
        $this->assertSame(85.6, (float) $response->json('template.card_geometry.width_mm'));
    }

    public function test_a_design_without_the_verification_code_is_refused(): void
    {
        $bundle = $this->bundle([
            'front' => '<div class="card">{{ holder.name }}</div>',
            'back' => '<div class="card">nothing</div>',
        ]);

        $this->asAdmin()->postJson('/api/v1/id-cards/templates', $bundle)
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'verification code'));
    }

    public function test_a_design_with_a_script_tag_is_refused_at_import(): void
    {
        $bundle = $this->bundle([
            'front' => '<div class="card">{{ holder.name }}<script>fetch("/x")</script>'
                . '<img src="{{ verification.qr_code_url }}"></div>',
        ]);

        $this->asAdmin()->postJson('/api/v1/id-cards/templates', $bundle)
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($error) => str_contains($error, '<script>'));
    }

    public function test_activating_a_student_design_does_not_archive_the_staff_design(): void
    {
        $service = app(IdCardTemplateService::class);

        $student = $service->import($this->bundle(), $this->school->id);
        $staff = $service->import($this->bundle([
            'name' => 'Graceland Staff Card',
            'slug' => 'graceland-staff',
            'holder_type' => 'staff',
        ]), $this->school->id);

        $staff->activate();
        $student->activate();

        $this->assertSame('active', $staff->fresh()->status);
        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_the_active_design_cannot_be_deleted(): void
    {
        $template = app(IdCardTemplateService::class)->import($this->bundle(), $this->school->id);
        $template->activate();

        $this->asAdmin()->deleteJson("/api/v1/id-cards/templates/{$template->id}")
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Photo preflight
    // ------------------------------------------------------------------

    public function test_preflight_blocks_a_student_with_no_photo_and_says_who(): void
    {
        $withPhoto = $this->makeStudent('Adaeze Nwosu', $this->writePhoto('photos/adaeze.png'));
        $this->makeStudent('Chidi Okeke', null);

        $response = $this->asAdmin()->postJson('/api/v1/id-cards/preflight', [
            'holder_type' => 'student',
            'class_id' => $this->class->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('eligible', 1)
            ->assertJsonCount(1, 'blocked')
            ->assertJsonPath('blocked.0.reason', 'no_photo')
            ->assertJsonPath('blocked.0.label', 'Chidi Okeke');

        $this->assertNotNull($withPhoto->passport_photo_path);
    }

    /**
     * The check that stops a print run becoming a local file read. The path is
     * attacker-controlled in the sense that it arrives over the API.
     */
    public function test_a_photo_path_that_escapes_the_storage_root_is_refused(): void
    {
        $photos = app(PhotoPreflightService::class);

        foreach (['../../.env', '../../../../etc/passwd', '/etc/passwd'] as $path) {
            $result = $photos->resolve($path);

            $this->assertFalse($result['ok'], "Escaped the photo root with: {$path}");
            $this->assertNull($result['data_uri']);
        }
    }

    public function test_a_remote_photo_url_is_reported_rather_than_fetched(): void
    {
        $result = app(PhotoPreflightService::class)->resolve('https://example.test/face.jpg');

        // Http::preventStrayRequests() in setUp would fail the test if this
        // ever turned into a fetch.
        $this->assertFalse($result['ok']);
        $this->assertSame('remote_photo', $result['problem']);
    }

    public function test_a_low_resolution_photo_warns_but_still_prints(): void
    {
        $photos = app(PhotoPreflightService::class);
        $result = $photos->resolve($this->writePhoto('photos/tiny.png', 60, 80), ['width' => 22.0, 'height' => 28.0]);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('dpi', $result['warnings'][0]);
    }

    public function test_a_resolved_photo_comes_back_as_an_embeddable_data_uri(): void
    {
        $result = app(PhotoPreflightService::class)->resolve($this->writePhoto('photos/ok.png'));

        $this->assertTrue($result['ok']);
        $this->assertStringStartsWith('data:image/png;base64,', $result['data_uri']);
        $this->assertNotNull($result['fingerprint']);
    }

    // ------------------------------------------------------------------
    // Issuance lifecycle
    // ------------------------------------------------------------------

    public function test_reprinting_reuses_the_card_the_holder_already_carries(): void
    {
        $student = $this->makeStudent('Adaeze Nwosu');
        $issuance = app(IdCardIssuanceService::class);

        $first = $issuance->issue($this->school->id, 'student', $student->id);
        $second = $issuance->issue($this->school->id, 'student', $student->id);

        $this->assertSame($first->id, $second->id, 'A second print minted a new identity.');
        $this->assertSame(1, IdCard::withoutGlobalScopes()->count());
    }

    public function test_serials_are_readable_and_sequential_per_school(): void
    {
        $issuance = app(IdCardIssuanceService::class);
        $year = now()->year;

        $a = $issuance->issue($this->school->id, 'student', $this->makeStudent('A One')->id);
        $b = $issuance->issue($this->school->id, 'student', $this->makeStudent('B Two')->id);

        $this->assertSame("GRACELAND/STU/{$year}/0001", $a->serial);
        $this->assertSame("GRACELAND/STU/{$year}/0002", $b->serial);
    }

    public function test_a_student_card_expires_with_the_session_and_a_staff_card_does_not(): void
    {
        $session = AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2026/2027',
            'start_date' => '2026-09-14',
            'end_date' => '2027-07-30',
            'is_current' => true,
        ]);

        $issuance = app(IdCardIssuanceService::class);

        $student = $issuance->issue($this->school->id, 'student', $this->makeStudent('Adaeze Nwosu')->id);
        $this->assertSame('2027-07-30', $student->expires_on->toDateString());
        $this->assertSame($session->id, $student->session_id);

        $staff = $issuance->issue($this->school->id, 'staff', 9999);
        $this->assertNull($staff->expires_on);
    }

    public function test_replacing_a_card_revokes_the_old_one_and_links_the_chain(): void
    {
        $student = $this->makeStudent('Adaeze Nwosu');
        $issuance = app(IdCardIssuanceService::class);

        $original = $issuance->issue($this->school->id, 'student', $student->id);
        $replacement = $issuance->replace($original, 'lost', $this->admin->id);

        $this->assertSame('revoked', $original->fresh()->status);
        $this->assertSame('lost', $original->fresh()->revoked_reason);
        $this->assertSame($original->id, $replacement->replaces_id);
        $this->assertTrue($replacement->isValid());
    }

    public function test_an_expired_card_reads_as_expired_before_any_sweep_has_run(): void
    {
        $card = app(IdCardIssuanceService::class)->issue(
            $this->school->id,
            'student',
            $this->makeStudent('Adaeze Nwosu')->id,
            ['expires_on' => now()->subDay()->toDateString()]
        );

        // The stored column still says active; the answer must not wait on cron.
        $this->assertSame('active', $card->status);
        $this->assertSame('expired', $card->effectiveStatus());
        $this->assertFalse($card->isValid());
    }

    // ------------------------------------------------------------------
    // Verification
    // ------------------------------------------------------------------

    public function test_scanning_a_valid_card_shows_the_holder_and_says_it_is_valid(): void
    {
        $student = $this->makeStudent('Adaeze Nwosu', $this->writePhoto('photos/adaeze.png'));
        $card = app(IdCardIssuanceService::class)->issue($this->school->id, 'student', $student->id);

        $response = $this->get("/api/v1/verify-id/{$card->verify_token}");

        $response->assertOk()
            ->assertSee('Valid card')
            ->assertSee('Adaeze Nwosu')
            ->assertSee('Graceland College')
            // The disclaimer is load-bearing: doc §3 rules out treating this
            // as an access credential, and the page has to say so.
            ->assertSee('not a key', false);

        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_a_revoked_card_scans_as_not_valid_and_gives_only_a_reason_category(): void
    {
        $student = $this->makeStudent('Adaeze Nwosu', $this->writePhoto('photos/adaeze.png'));
        $issuance = app(IdCardIssuanceService::class);
        $card = $issuance->issue($this->school->id, 'student', $student->id);
        $issuance->revoke($card, 'lost', $this->admin->id);

        $this->get("/api/v1/verify-id/{$card->verify_token}")
            ->assertOk()
            ->assertSee('Card withdrawn')
            ->assertSee('reported lost')
            ->assertDontSee('Valid card');
    }

    public function test_an_unknown_token_looks_identical_to_a_dead_one(): void
    {
        $this->getJson('/api/v1/verify-id/not-a-real-token?format=json')
            ->assertStatus(404)
            ->assertJsonPath('found', false)
            ->assertJsonPath('valid', false);
    }

    /**
     * The verification page is public. It must carry the minimum that answers
     * "is this card current?" and nothing that merely happens to be on hand.
     */
    public function test_the_verification_page_discloses_nothing_beyond_the_card(): void
    {
        $student = $this->makeStudent('Adaeze Nwosu', $this->writePhoto('photos/adaeze.png'));
        $student->update([
            'date_of_birth' => '2012-04-03',
            'blood_group' => 'O+',
            'medical_notes' => 'Asthmatic',
            'emergency_contacts' => [['name' => 'Mrs Nwosu', 'phone' => '08030000000']],
        ]);

        $card = app(IdCardIssuanceService::class)->issue($this->school->id, 'student', $student->id);
        $body = $this->get("/api/v1/verify-id/{$card->verify_token}")->getContent();

        /*
         * Strip embedded image payloads before scanning. The page carries the
         * holder's photo as base64, which is random-looking alphanumeric text —
         * a short secret like "O+" appears in it by chance roughly one run in
         * seven, which would make this a flaky test rather than a real one.
         * What is being asserted is that the page's *text* leaks nothing.
         */
        $text = preg_replace('#data:image/[a-z+]+;base64,[A-Za-z0-9+/=]+#i', '[image]', $body);

        foreach (['2012-04-03', 'O+', 'Asthmatic', 'Mrs Nwosu', '08030000000', $student->admission_number] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $text, "Leaked \"{$secret}\" on the public page.");
        }

        // The photo itself is still expected to be there — it is the check that
        // catches a card with a swapped face.
        $this->assertStringContainsString('data:image/', $body);
    }

    public function test_the_verification_token_never_comes_back_through_the_admin_api(): void
    {
        $student = $this->makeStudent('Adaeze Nwosu');
        $card = app(IdCardIssuanceService::class)->issue($this->school->id, 'student', $student->id);

        $body = $this->asAdmin()
            ->getJson("/api/v1/id-cards/holders/student/{$student->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($card->verify_token, $body);
    }

    // ------------------------------------------------------------------
    // Print runs
    // ------------------------------------------------------------------

    public function test_a_run_refuses_to_start_while_holders_are_unprintable(): void
    {
        $this->makeStudent('Adaeze Nwosu', $this->writePhoto('photos/adaeze.png'));
        $this->makeStudent('Chidi Okeke', null);

        Queue::fake();

        $this->asAdmin()->postJson('/api/v1/id-cards/print-runs', [
            'holder_type' => 'student',
            'class_id' => $this->class->id,
        ])->assertStatus(422)
            ->assertJsonPath('preflight.blocked.0.reason', 'no_photo');

        Queue::assertNothingPushed();
    }

    public function test_an_admin_can_knowingly_print_the_rest_of_a_class(): void
    {
        $this->makeStudent('Adaeze Nwosu', $this->writePhoto('photos/adaeze.png'));
        $this->makeStudent('Chidi Okeke', null);

        Queue::fake();

        $this->asAdmin()->postJson('/api/v1/id-cards/print-runs', [
            'holder_type' => 'student',
            'class_id' => $this->class->id,
            'proceed_with_blocked' => true,
        ])->assertStatus(202)
            ->assertJsonPath('run.status', 'queued');

        Queue::assertPushed(GenerateIdCardRunJob::class);
    }

    public function test_a_run_renders_a_pdf_and_stamps_the_cards_it_printed(): void
    {
        $student = $this->makeStudent('Adaeze Nwosu', $this->writePhoto('photos/adaeze.png'));

        $this->asAdmin()->postJson('/api/v1/id-cards/print-runs', [
            'holder_type' => 'student',
            'class_id' => $this->class->id,
        ])->assertStatus(202);

        // Queue is synchronous in tests, so the run has already been rendered.
        $run = \App\Models\IdCardPrintRun::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame('completed', $run->status, (string) $run->error);
        $this->assertSame(1, $run->card_count);
        $this->assertGreaterThan(0, $run->sheet_count);
        $this->assertNotNull($run->pdf_path);

        $card = IdCard::withoutGlobalScopes()->forHolder('student', $student->id)->first();
        $this->assertSame(1, $card->print_count);
        $this->assertNotNull($card->photo_fingerprint);
        $this->assertNotNull($card->last_printed_at);

        $download = $this->asAdmin()->get("/api/v1/id-cards/print-runs/{$run->id}/download");
        $download->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $download->getContent());
    }

    public function test_blood_group_stays_off_a_card_unless_the_school_asks_for_it(): void
    {
        $student = $this->makeStudent('Adaeze Nwosu', $this->writePhoto('photos/adaeze.png'));
        $student->update(['blood_group' => 'O+']);

        $card = app(IdCardIssuanceService::class)->issue($this->school->id, 'student', $student->id);
        $printer = app(IdCardPrintService::class);

        /*
         * Asserted on the field label, not the value. The rendered card embeds
         * the QR as base64, which is different on every run because the token
         * is random — and a short value like "O+" turns up in that blob by
         * chance often enough to make the value-based assertion flaky.
         */
        $without = $printer->renderCard($card, $student->fresh(), null, ['photo' => '']);
        $this->assertStringNotContainsString('Blood group', $without['front'] . $without['back']);

        $with = $printer->renderCard($card, $student->fresh(), null, [
            'photo' => '',
            'include_blood_group' => true,
        ]);
        $this->assertStringContainsString('Blood group', $with['front'] . $with['back']);
        $this->assertStringContainsString('<td>O+</td>', $with['back']);
    }

    // ------------------------------------------------------------------
    // Tenancy
    // ------------------------------------------------------------------

    public function test_a_school_cannot_reach_another_schools_print_run_or_design(): void
    {
        $other = School::create(['name' => 'Rival Academy', 'slug' => 'rival', 'subdomain' => 'rival']);

        $theirTemplate = app(IdCardTemplateService::class)->import($this->bundle(), $other->id);
        $theirRun = \App\Models\IdCardPrintRun::create([
            'school_id' => $other->id,
            'holder_type' => 'student',
            'status' => 'completed',
            'pdf_path' => 'id-cards/999/run.pdf',
            'expires_at' => now()->addDay(),
        ]);

        $this->asAdmin()->getJson("/api/v1/id-cards/templates/{$theirTemplate->id}")->assertStatus(404);
        $this->asAdmin()->getJson("/api/v1/id-cards/print-runs/{$theirRun->id}")->assertStatus(404);
        $this->asAdmin()->get("/api/v1/id-cards/print-runs/{$theirRun->id}/download")->assertStatus(404);
    }
}
