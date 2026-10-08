<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\ReportCard\ReportCardTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Apex Report design (docs/templates/apex-report) stays importable, and
 * the data it relies on — subject groups, class average, best and focus
 * subject, first-in-class, next term date, per-grade classes — is filled in.
 */
class ApexReportTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function bundle(): array
    {
        return json_decode(file_get_contents(base_path('../docs/templates/apex-report/apex-report.json')), true);
    }

    public function test_the_bundle_imports_and_renders_its_sample(): void
    {
        $school = School::create(['name' => 'Alderwood International School', 'slug' => 'alderwood', 'subdomain' => 'alderwood']);

        $templates = app(ReportCardTemplateService::class);
        $template = $templates->import($this->bundle(), $school->id);

        $this->assertSame('draft', $template->status);

        $html = $templates->render($template, $templates->sampleContext());

        $this->assertStringContainsString('GRACELAND COLLEGE', $html);
        $this->assertStringContainsString('CORE SUBJECTS', $html);
        $this->assertStringContainsString('OTHER SUBJECTS', $html);
        $this->assertStringContainsString('gk-A1', $html);
        $this->assertStringContainsString('sb-80', $html);
        $this->assertStringContainsString('Subject Scores', $html);
        $this->assertStringContainsString('SAMPLE-TOKEN-0000', $html);
    }

    public function test_a_real_term_fills_every_panel(): void
    {
        $school = School::create(['name' => 'Alderwood', 'slug' => 'alderwood', 'subdomain' => 'alderwood']);
        $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31']);
        $first = Term::create(['school_id' => $school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-14', 'end_date' => '2026-12-11']);
        Term::create(['school_id' => $school->id, 'session_id' => $session->id, 'name' => 'Second Term', 'start_date' => '2027-01-11', 'end_date' => '2027-04-02']);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Year 6', 'level_category' => 'primary']);

        $maths = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics', 'category' => 'core']);
        $music = Subject::create(['school_id' => $school->id, 'name' => 'Music', 'category' => 'elective']);

        $make = function (string $name, array $marks) use ($school, $class, $first, $maths, $music) {
            $user = User::create(['name' => $name, 'email' => str_replace(' ', '', strtolower($name)).'@al.test', 'password' => 'x']);
            $user->userProfile()->create(['school_id' => $school->id, 'role' => 'student']);
            $student = Student::create(['school_id' => $school->id, 'user_id' => $user->id, 'class_id' => $class->id, 'admission_number' => 'AL/'.$name]);

            foreach ([[$maths, $marks[0]], [$music, $marks[1]]] as [$subject, $total]) {
                ScoreEntry::create(['school_id' => $school->id, 'term_id' => $first->id, 'student_id' => $student->id, 'subject_id' => $subject->id, 'total_score' => $total, 'grade' => null]);
            }

            return $student;
        };

        $top = $make('Ada Top', [96, 88]);
        $make('Bola Next', [70, 60]);

        $context = $this->inSchool($school->id, fn () => app(ReportCardTemplateService::class)->buildContext($top->fresh(), $first->fresh()));

        $this->assertTrue($context['student']['is_top']);
        $this->assertSame(78.5, $context['summary']['class_average']); // (92 + 65) / 2
        $this->assertSame('Mathematics', $context['summary']['best_subject']);
        $this->assertSame('Music', $context['summary']['focus_subject']);
        $this->assertSame('2027-01-11', $context['term']['next_term_begins']);
        $this->assertSame(['core', 'elective'], array_column($context['score_groups'], 'key'));
        $this->assertSame('Mathematics', $context['score_groups'][0]['scores'][0]['subject']);
    }

    public function test_the_grade_and_band_filters(): void
    {
        $html = app(\App\Services\ReportCard\TemplateRenderer::class)->render(
            '{{#each rows}}<i class="{{ this.g | grade_key }} {{ this.s | score_band }}"></i>{{/each}}',
            ['rows' => [['g' => 'B+', 's' => 81], ['g' => 'A1', 's' => 100], ['g' => 'E', 's' => 12], ['g' => '', 's' => null]]],
        );

        $this->assertStringContainsString('gk-Bplus sb-80', $html);
        $this->assertStringContainsString('gk-A1 sb-90', $html);
        $this->assertStringContainsString('gk-E sb-below40', $html);
        $this->assertStringContainsString('gk-none sb-none', $html);
    }
}
