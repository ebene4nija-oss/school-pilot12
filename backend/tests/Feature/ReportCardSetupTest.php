<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ClassTeacherAssignment;
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
use Tests\TestCase;

/**
 * Onboarding choices that fill a report card: any number of CAs, what is
 * rated and which comments print; and teachers entering them each term.
 */
class ReportCardSetupTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $formTeacher;

    private User $otherTeacher;

    private Term $term;

    private SchoolClass $jss1;

    private Subject $maths;

    private Student $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'gf', 'subdomain' => 'gf']);
        $this->admin = $this->person('Head', 'school_admin');
        $this->formTeacher = $this->person('Mrs Form', 'teacher');
        $this->otherTeacher = $this->person('Mr Other', 'teacher');

        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31']);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-14', 'end_date' => '2026-12-11']);
        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1', 'level_category' => 'junior_secondary']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'category' => 'core']);

        ClassTeacherAssignment::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'class_id' => $this->jss1->id, 'form_teacher_id' => $this->formTeacher->id]);

        $this->ada = Student::create(['school_id' => $this->school->id, 'user_id' => $this->person('Ada Obi', 'student')->id, 'class_id' => $this->jss1->id, 'admission_number' => 'GF/001']);
    }

    private function person(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str_replace(' ', '.', strtolower($name)).'@gf.test', 'password' => bcrypt('x')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function threeCaScheme()
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/assessment/ca-schemes', [
            'name' => '10/10/10/70',
            'components' => [
                ['name' => '1st CA', 'max' => 10, 'kind' => 'ca'],
                ['name' => '2nd CA', 'max' => 10, 'kind' => 'ca'],
                ['name' => '3rd CA', 'max' => 10, 'kind' => 'ca'],
                ['name' => 'Exam', 'max' => 70, 'kind' => 'exam'],
            ],
        ]);
    }

    private function score(array $body)
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/assessment/score', array_merge([
            'term_id' => $this->term->id, 'student_id' => $this->ada->id, 'subject_id' => $this->maths->id,
        ], $body));
    }

    // ------------------------------------------------------------ CAs

    public function test_a_school_can_mark_in_three_cas(): void
    {
        $this->threeCaScheme()->assertCreated()->assertJsonPath('scheme.first_ca_weight', 10)->assertJsonPath('scheme.second_ca_weight', 20)->assertJsonPath('scheme.exam_weight', 70);

        $this->score(['components' => ['ca1' => 8, 'ca2' => 9, 'ca3' => 7, 'exam' => 60]])->assertOk();

        $entry = ScoreEntry::firstOrFail();
        $this->assertEquals(['ca1' => 8.0, 'ca2' => 9.0, 'ca3' => 7.0, 'exam' => 60.0], $entry->component_scores);
        // Legacy columns still filled: first CA, other CAs summed, exam.
        $this->assertEquals(8, $entry->first_ca);
        $this->assertEquals(16, $entry->second_ca);
        $this->assertEquals(60, $entry->exam);
        $this->assertEquals(84, $entry->total_score);
    }

    public function test_a_later_part_can_be_entered_without_losing_the_others(): void
    {
        $this->threeCaScheme()->assertCreated();
        $this->score(['components' => ['ca1' => 8, 'ca2' => 9]])->assertOk();
        $this->score(['components' => ['ca3' => 10]])->assertOk();

        $this->assertEquals(['ca1' => 8.0, 'ca2' => 9.0, 'ca3' => 10.0], ScoreEntry::firstOrFail()->component_scores);
    }

    public function test_each_part_is_capped_and_unknown_parts_are_refused(): void
    {
        $this->threeCaScheme()->assertCreated();

        $this->score(['components' => ['ca3' => 11]])->assertStatus(422)->assertJsonPath('errors', ['components.ca3' => ['3rd CA is marked out of 10.']]);
        $this->score(['components' => ['ca4' => 5]])->assertStatus(422);
        // The old two-CA fields cannot be squeezed into a four-part scheme.
        $this->score(['first_ca' => 8, 'second_ca' => 8, 'exam' => 50])->assertStatus(422);
    }

    public function test_the_classic_fields_still_work_for_a_two_ca_school(): void
    {
        $this->score(['first_ca' => 15, 'second_ca' => 18, 'exam' => 50])->assertOk();

        $entry = ScoreEntry::firstOrFail();
        $this->assertEquals(['ca1' => 15.0, 'ca2' => 18.0, 'exam' => 50.0], $entry->component_scores);
        $this->assertSame('A1', $entry->grade);
    }

    public function test_the_score_sheet_template_round_trips(): void
    {
        $this->threeCaScheme()->assertCreated();

        $csv = $this->actingAs($this->admin, 'sanctum')->get("/api/v1/teacher/score-template?class_id={$this->jss1->id}")->assertOk()->getContent();

        $this->assertStringContainsString('"3rd CA (10)"', $csv);
        $this->assertStringContainsString('"GF/001"', $csv);

        $filled = str_replace('"GF/001","Ada Obi",,,,', '"GF/001","Ada Obi",9,8,10,55', $csv);

        $this->actingAs($this->admin, 'sanctum')->post('/api/v1/teacher/import-scores', [
            'term_id' => $this->term->id, 'subject_id' => $this->maths->id,
            'file' => UploadedFile::fake()->createWithContent('sheet.csv', $filled),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('imported_count', 1);

        $this->assertEquals(['ca1' => 9.0, 'ca2' => 8.0, 'ca3' => 10.0, 'exam' => 55.0], ScoreEntry::firstOrFail()->component_scores);
    }

    public function test_the_report_card_shows_a_column_per_part(): void
    {
        $this->threeCaScheme()->assertCreated();
        $this->score(['components' => ['ca1' => 8, 'ca2' => 9, 'ca3' => 7, 'exam' => 60]])->assertOk();

        $context = app(ReportCardTemplateService::class)->buildContext($this->ada->fresh(), $this->term);

        $this->assertSame(['1st CA', '2nd CA', '3rd CA', 'Exam'], array_column($context['ca_components'], 'name'));
        $this->assertSame([8.0, 9.0, 7.0, 60.0], array_column($context['scores'][0]['components'], 'score'));
    }

    // ------------------------------------------------ settings + entries

    public function test_the_school_chooses_what_it_rates(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/report-settings')->assertOk()->assertJsonPath('configured', false);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/report-settings', [
            'affective_traits' => ['Punctuality', 'Neatness'],
            'psychomotor_skills' => ['Handwriting'],
            'rating_scale' => [['value' => 'A', 'label' => 'Excellent'], ['value' => 'B', 'label' => 'Good'], ['value' => 'C', 'label' => 'Fair']],
            'form_teacher_comment' => true,
            'principal_comment' => true,
            'principal_title' => 'Head Teacher',
        ])->assertOk();

        $this->actingAs($this->formTeacher, 'sanctum')->getJson('/api/v1/report-settings')
            ->assertOk()->assertJsonPath('configured', true)->assertJsonPath('settings.principal_title', 'Head Teacher');

        $this->actingAs($this->formTeacher, 'sanctum')->putJson('/api/v1/report-settings', [])->assertForbidden();
    }

    public function test_the_form_teacher_rates_and_comments_and_the_card_prints_them(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/report-settings', [
            'affective_traits' => ['Punctuality'], 'psychomotor_skills' => ['Handwriting'],
            'rating_scale' => [['value' => '5', 'label' => 'Excellent'], ['value' => '4', 'label' => 'Very good'], ['value' => '1', 'label' => 'Poor']],
            'form_teacher_comment' => true, 'principal_comment' => true, 'principal_title' => 'Principal',
        ])->assertOk();

        $query = "term_id={$this->term->id}&class_id={$this->jss1->id}";

        $this->actingAs($this->formTeacher, 'sanctum')->getJson("/api/v1/report-entries?{$query}")
            ->assertOk()->assertJsonPath('can_write_principal', false)->assertJsonPath('students.0.name', 'Ada Obi');

        $this->actingAs($this->formTeacher, 'sanctum')->putJson('/api/v1/report-entries', [
            'term_id' => $this->term->id, 'class_id' => $this->jss1->id,
            'entries' => [['student_id' => $this->ada->id, 'ratings' => ['Punctuality' => '5', 'Handwriting' => '4'], 'form_teacher_comment' => 'A steady term.']],
        ])->assertOk();

        // The principal's comment is an administrator's.
        $this->actingAs($this->formTeacher, 'sanctum')->putJson('/api/v1/report-entries', [
            'term_id' => $this->term->id, 'class_id' => $this->jss1->id,
            'entries' => [['student_id' => $this->ada->id, 'principal_comment' => 'Well done.']],
        ])->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/report-entries', [
            'term_id' => $this->term->id, 'class_id' => $this->jss1->id,
            'entries' => [['student_id' => $this->ada->id, 'principal_comment' => 'Well done.']],
        ])->assertOk();

        $context = app(ReportCardTemplateService::class)->buildContext($this->ada->fresh(), $this->term);

        $this->assertSame('A steady term.', $context['comments']['class_teacher']);
        $this->assertSame('Well done.', $context['comments']['principal']);
        $this->assertSame([['name' => 'Punctuality', 'group' => 'affective', 'rating' => '5', 'rating_label' => 'Excellent']], $context['affective_traits']);
        $this->assertSame('Handwriting', $context['psychomotor_skills'][0]['name']);
    }

    public function test_ratings_must_be_on_the_scale_and_teachers_stay_in_their_class(): void
    {
        $query = "term_id={$this->term->id}&class_id={$this->jss1->id}";

        $this->actingAs($this->otherTeacher, 'sanctum')->getJson("/api/v1/report-entries?{$query}")->assertForbidden();

        $this->actingAs($this->formTeacher, 'sanctum')->putJson('/api/v1/report-entries', [
            'term_id' => $this->term->id, 'class_id' => $this->jss1->id,
            'entries' => [['student_id' => $this->ada->id, 'ratings' => ['Punctuality' => '9']]],
        ])->assertStatus(422);

        $this->actingAs($this->formTeacher, 'sanctum')->putJson('/api/v1/report-entries', [
            'term_id' => $this->term->id, 'class_id' => $this->jss1->id,
            'entries' => [['student_id' => $this->ada->id, 'ratings' => ['Flying' => '5']]],
        ])->assertStatus(422);
    }

    public function test_a_school_can_leave_a_comment_off_its_card(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/report-settings', [
            'affective_traits' => [], 'psychomotor_skills' => [],
            'rating_scale' => [['value' => '5'], ['value' => '1']],
            'form_teacher_comment' => true, 'principal_comment' => false, 'principal_title' => 'Principal',
        ])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/report-entries', [
            'term_id' => $this->term->id, 'class_id' => $this->jss1->id,
            'entries' => [['student_id' => $this->ada->id, 'principal_comment' => 'Hidden.']],
        ])->assertOk();

        $context = app(ReportCardTemplateService::class)->buildContext($this->ada->fresh(), $this->term);

        $this->assertNull($context['comments']['principal']);
        $this->assertFalse($context['comments']['show_principal']);
    }
}
