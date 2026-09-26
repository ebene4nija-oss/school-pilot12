<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\GradingScale;
use App\Models\ReportCardDesignRequest;
use App\Models\ReportCardTemplate;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\ReportCard\ReportCardTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Schools sending SchoolPilot their report-card design and grading; SchoolPilot
 * uploading the template; the school approving it. And grading scales per
 * class level, used wherever a grade is worked out.
 */
class ReportCardDesignTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $other;

    private User $admin;

    private User $otherAdmin;

    private User $teacher;

    private User $operator;

    private Term $term;

    private SchoolClass $primary4;

    private SchoolClass $jss2;

    private Subject $maths;

    /** Primary A–E. */
    private const PRIMARY = [
        ['min' => 80, 'max' => 100, 'grade' => 'A', 'remark' => 'Excellent'],
        ['min' => 65, 'max' => 79, 'grade' => 'B', 'remark' => 'Very good'],
        ['min' => 50, 'max' => 64, 'grade' => 'C', 'remark' => 'Good'],
        ['min' => 40, 'max' => 49, 'grade' => 'D', 'remark' => 'Fair'],
        ['min' => 0, 'max' => 39, 'grade' => 'E', 'remark' => 'Needs help'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->school = School::create(['name' => 'Graceland', 'slug' => 'graceland', 'subdomain' => 'graceland']);
        $this->other = School::create(['name' => 'Kingsway', 'slug' => 'kingsway', 'subdomain' => 'kingsway']);

        $this->admin = $this->person('Mrs Head', 'head@gl.test', 'school_admin', $this->school->id);
        $this->otherAdmin = $this->person('Mr Other', 'head@kw.test', 'school_admin', $this->other->id);
        $this->teacher = $this->person('Mr Bello', 'bello@gl.test', 'teacher', $this->school->id);
        $this->operator = $this->person('Ops', 'ops@schoolpilot.test', 'super_admin', null);

        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31']);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-14', 'end_date' => '2026-12-11']);

        $this->primary4 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'Primary 4', 'level_category' => 'primary']);
        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'level_category' => 'junior_secondary']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'category' => 'core']);
    }

    private function person(string $name, string $email, string $role, ?int $schoolId): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $schoolId, 'role' => $role]);

        return $user;
    }

    private function student(SchoolClass $class, string $name): Student
    {
        return Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->person($name, strtolower(str_replace(' ', '.', $name)).'@gl.test', 'student', $this->school->id)->id,
            'class_id' => $class->id,
            'admission_number' => 'GL/'.uniqid(),
        ]);
    }

    private function score(Student $student, float $firstCa, float $secondCa, float $exam)
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/assessment/score', [
            'term_id' => $this->term->id, 'student_id' => $student->id, 'subject_id' => $this->maths->id,
            'first_ca' => $firstCa, 'second_ca' => $secondCa, 'exam' => $exam,
        ]);
    }

    private function brief(array $overrides = []): array
    {
        return array_merge([
            'contact_name' => 'Mrs Head',
            'contact_phone' => '0803 123 4567',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'sections' => ['position_in_class' => true, 'attendance' => true, 'affective_traits' => true, 'grading_key' => true],
            'ca_components' => [['name' => '1st CA', 'max' => 20], ['name' => '2nd CA', 'max' => 20], ['name' => 'Exam', 'max' => 60]],
            'affective_traits' => ['Punctuality', 'Neatness'],
            'notes' => 'School colours are green and gold.',
        ], $overrides);
    }

    private function submit(array $briefOverrides = [], ?array $scales = null)
    {
        return $this->actingAs($this->admin, 'sanctum')->post('/api/v1/report-cards/design-requests', [
            'brief' => json_encode($this->brief($briefOverrides)),
            'grading_scales' => json_encode($scales ?? ['primary' => self::PRIMARY]),
            'files' => [
                ['file' => UploadedFile::fake()->create('our-card.pdf', 300, 'application/pdf'), 'kind' => 'sample', 'label' => 'Current JSS card'],
                ['file' => UploadedFile::fake()->image('crest.png'), 'kind' => 'crest'],
            ],
        ], ['Accept' => 'application/json']);
    }

    private function bundleFile(): UploadedFile
    {
        $templates = app(ReportCardTemplateService::class);

        return UploadedFile::fake()->createWithContent('graceland.json', json_encode([
            'engine' => ReportCardTemplate::ENGINE,
            'name' => 'Graceland Terminal Report',
            'body' => $templates->defaultTemplateBody(),
            'styles' => $templates->defaultTemplateStyles(),
        ]));
    }

    // ------------------------------------------------------------ scales

    public function test_a_scale_with_a_gap_or_overlap_is_refused(): void
    {
        $gap = self::PRIMARY;
        $gap[1]['min'] = 70; // 65–69 uncovered

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/grading-scales/primary', ['bands' => $gap])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No grade covers 65 to 69.');

        $overlap = self::PRIMARY;
        $overlap[1]['max'] = 85;

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/grading-scales/primary', ['bands' => $overlap])
            ->assertStatus(422);
    }

    /** Primary pupils graded A–E, JSS still on WAEC — in the same school. */
    public function test_scores_are_graded_on_the_scale_for_the_class_level(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/grading-scales/primary', ['bands' => self::PRIMARY])
            ->assertOk();

        $pupil = $this->student($this->primary4, 'Tobi Ade');
        $jss = $this->student($this->jss2, 'Ada Obi');

        $this->score($pupil, 15, 15, 40)->assertOk(); // 70
        $this->score($jss, 15, 15, 40)->assertOk();

        $this->assertSame('B', ScoreEntry::where('student_id', $pupil->id)->value('grade'));
        $this->assertSame('B2', ScoreEntry::where('student_id', $jss->id)->value('grade'));
    }

    public function test_saving_a_scale_can_regrade_the_chosen_term(): void
    {
        $pupil = $this->student($this->primary4, 'Tobi Ade');
        $this->score($pupil, 15, 15, 40)->assertOk();
        $this->assertSame('B2', ScoreEntry::where('student_id', $pupil->id)->value('grade'));

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/grading-scales/primary', ['bands' => self::PRIMARY, 'regrade_term_id' => $this->term->id])
            ->assertOk()
            ->assertJsonPath('regraded', 1);

        $this->assertSame('B', ScoreEntry::where('student_id', $pupil->id)->value('grade'));
    }

    public function test_the_report_card_uses_the_school_scale(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/grading-scales/primary', ['bands' => self::PRIMARY])->assertOk();

        $pupil = $this->student($this->primary4, 'Tobi Ade');
        $this->score($pupil, 18, 18, 50)->assertOk(); // 86

        $context = app(ReportCardTemplateService::class)->buildContext($pupil->fresh(), $this->term);

        $this->assertSame('A', $context['summary']['overall_grade']);
        $this->assertSame(['grade' => 'A', 'range' => '80 - 100', 'remark' => 'Excellent'], $context['grading_scale'][0]);
    }

    /** The CSV import had a three-step ladder: 60 came out as "F". */
    public function test_csv_score_import_grades_on_the_full_scale(): void
    {
        $jss = $this->student($this->jss2, 'Ada Obi');

        $csv = "student_id,first_ca,second_ca,exam\n{$jss->id},10,10,40\n";

        $this->actingAs($this->admin, 'sanctum')->post('/api/v1/teacher/import-scores', [
            'term_id' => $this->term->id, 'subject_id' => $this->maths->id,
            'file' => UploadedFile::fake()->createWithContent('scores.csv', $csv),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('C4', ScoreEntry::where('student_id', $jss->id)->value('grade'));
    }

    // --------------------------------------------------------- requests

    public function test_a_school_submits_its_brief_files_and_grading(): void
    {
        $res = $this->submit()->assertCreated()->assertJsonPath('request.status', 'submitted');

        $request = ReportCardDesignRequest::findOrFail($res->json('request.id'));

        $this->assertSame(['passport_photo', 'position_in_class'], array_slice(array_keys($request->requirements['sections']), 0, 2));
        $this->assertTrue($request->requirements['sections']['verification_qr']);
        $this->assertCount(2, $request->files);
        Storage::disk('local')->assertExists($request->files->first()->path);
        $this->assertArrayNotHasKey('path', $res->json('request.files.0'));
    }

    public function test_the_brief_is_checked(): void
    {
        $this->submit(['ca_components' => [['name' => 'CA', 'max' => 30], ['name' => 'Exam', 'max' => 60]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The CA and exam marks add up to 90; they should add up to 100.');

        $gap = self::PRIMARY;
        $gap[4]['max'] = 30;
        $this->submit([], ['primary' => $gap])->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')->post('/api/v1/report-cards/design-requests', [
            'brief' => json_encode($this->brief()),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_one_open_request_at_a_time(): void
    {
        $this->submit()->assertCreated();
        $this->submit()->assertStatus(409);
    }

    /** Submit → operator uploads → school previews and approves → live. */
    public function test_the_whole_journey_ends_with_the_design_live_and_the_files_gone(): void
    {
        $id = $this->submit()->json('request.id');
        $paths = ReportCardDesignRequest::find($id)->files->pluck('path');

        // The operator's queue, the brief, and the school's files.
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/platform/report-card-requests?status=open')
            ->assertOk()->assertJsonPath('data.0.school', 'Graceland')->assertJsonPath('data.0.files', 2);

        $detail = $this->actingAs($this->operator, 'sanctum')->getJson("/api/v1/platform/report-card-requests/{$id}")
            ->assertOk()->assertJsonPath('request.requirements.notes', 'School colours are green and gold.');
        $this->assertArrayNotHasKey('path', $detail->json('request.files.0'));

        $fileId = $detail->json('request.files.0.id');
        $this->actingAs($this->operator, 'sanctum')->get("/api/v1/platform/report-card-requests/{$id}/files/{$fileId}")->assertOk();

        $this->actingAs($this->operator, 'sanctum')->postJson("/api/v1/platform/report-card-requests/{$id}/start")->assertOk();

        $this->actingAs($this->operator, 'sanctum')
            ->post("/api/v1/platform/report-card-requests/{$id}/template", ['bundle' => $this->bundleFile()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('template.status', 'draft');

        // A draft for this school, not live yet.
        $template = ReportCardTemplate::withoutGlobalScopes()->where('school_id', $this->school->id)->firstOrFail();
        $this->assertSame('draft', $template->status);

        // The school previews on sample data with its requested scale.
        $html = $this->actingAs($this->admin, 'sanctum')->get("/api/v1/report-cards/design-requests/{$id}/preview")->assertOk()->getContent();
        $this->assertStringContainsString('GRACELAND', $html);

        $pupil = $this->student($this->primary4, 'Tobi Ade');
        $this->score($pupil, 15, 15, 40)->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/report-cards/design-requests/{$id}/approve", ['regrade_term_id' => $this->term->id])
            ->assertOk();

        $this->assertSame('active', $template->fresh()->status);
        $this->assertSame('approved', ReportCardDesignRequest::find($id)->status);
        $this->assertSame('A', GradingScale::bandsFor($this->school->id, 'primary')[0]['grade']);
        $this->assertSame('B', ScoreEntry::where('student_id', $pupil->id)->value('grade'));

        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertNotNull(ReportCardDesignRequest::find($id)->files_purged_at);
    }

    public function test_the_school_can_send_it_back_and_the_new_upload_replaces_the_draft(): void
    {
        $id = $this->submit()->json('request.id');

        $this->actingAs($this->operator, 'sanctum')
            ->post("/api/v1/platform/report-card-requests/{$id}/template", ['bundle' => $this->bundleFile()], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/report-cards/design-requests/{$id}/request-changes", ['feedback' => 'Make the crest bigger.'])
            ->assertOk();

        $this->actingAs($this->operator, 'sanctum')->getJson("/api/v1/platform/report-card-requests/{$id}")
            ->assertJsonPath('request.status', 'changes_requested')
            ->assertJsonPath('request.school_feedback', 'Make the crest bigger.');

        // Cannot approve until a new design is uploaded.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/report-cards/design-requests/{$id}/approve")->assertStatus(409);

        $this->actingAs($this->operator, 'sanctum')
            ->post("/api/v1/platform/report-card-requests/{$id}/template", ['bundle' => $this->bundleFile()], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame(1, ReportCardTemplate::withoutGlobalScopes()->where('school_id', $this->school->id)->count());
    }

    public function test_an_invalid_bundle_is_refused_with_the_reason(): void
    {
        $id = $this->submit()->json('request.id');

        $bad = UploadedFile::fake()->createWithContent('bad.json', json_encode(['engine' => 'something-else', 'name' => 'X', 'body' => '<p>hi</p>']));

        $this->actingAs($this->operator, 'sanctum')
            ->post("/api/v1/platform/report-card-requests/{$id}/template", ['bundle' => $bad], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame('submitted', ReportCardDesignRequest::find($id)->status);
    }

    public function test_the_operator_can_correct_the_scales_before_approval(): void
    {
        $id = $this->submit()->json('request.id');

        $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/platform/report-card-requests/{$id}/grading-scales", ['grading_scales' => ['default' => GradingScale::WAEC, 'primary' => self::PRIMARY]])
            ->assertOk();

        $this->assertSame(['default', 'primary'], array_keys(ReportCardDesignRequest::find($id)->grading_scales));
    }

    public function test_cancelling_deletes_the_files(): void
    {
        $id = $this->submit()->json('request.id');
        $paths = ReportCardDesignRequest::find($id)->files->pluck('path');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/report-cards/design-requests/{$id}/cancel")->assertOk();

        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_requests_stay_with_their_school_and_their_roles(): void
    {
        $id = $this->submit()->json('request.id');

        $this->actingAs($this->otherAdmin, 'sanctum')->getJson('/api/v1/report-cards/design-requests')->assertOk()->assertJsonPath('open', null);
        $this->actingAs($this->otherAdmin, 'sanctum')->postJson("/api/v1/report-cards/design-requests/{$id}/cancel")->assertNotFound();

        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/v1/report-cards/design-requests')->assertForbidden();
        $this->actingAs($this->teacher, 'sanctum')->putJson('/api/v1/grading-scales/primary', ['bands' => self::PRIMARY])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/platform/report-card-requests')->assertForbidden();
    }
}
