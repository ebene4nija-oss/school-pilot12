<?php

namespace Tests\Feature;

use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\QuestionBankItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * `GET /cbt/exams/{id}/offline-package` for a candidate — gap G4.
 *
 * The route was staff-only, which was fine while offline sitting was a
 * desktop-lab concern. It stops being fine the moment a pupil sits a paper on a
 * handset: the exam dies on the diagrams when the signal does, because nothing
 * let the device pre-cache them.
 *
 * The candidate payload is **media only**. Everything below is really one
 * assertion said several ways: pre-downloading must never become a second, and
 * laxer, way into a paper.
 */
class CbtOfflinePackageTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private User $studentUser;
    private Student $student;
    private Subject $subject;
    private SchoolClass $jss2;
    private SchoolClass $ss1;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://graceland.localhost');

        $this->school = School::create([
            'name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland',
        ]);

        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->ss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'SS 1']);
        $this->subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);

        $this->teacher = $this->user('Mrs Adeyemi', 'adeyemi@graceland.test', 'teacher');
        $this->studentUser = $this->user('Adaeze Nwosu', 'adaeze@graceland.test', 'student');

        $this->student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->studentUser->id,
            'class_id' => $this->jss2->id,
            'admission_number' => 'GC/2026/001',
        ]);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function exam(array $overrides = []): CbtExam
    {
        $exam = CbtExam::create(array_merge([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'class_id' => $this->jss2->id,
            'title' => 'First CA — Mathematics',
            'duration_minutes' => 30,
            'status' => 'published',
            'allow_offline' => true,
            'max_attempts' => 1,
            'created_by' => $this->teacher->id,
        ], $overrides));

        $question = QuestionBankItem::create([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'topic' => 'Algebra',
            'question' => 'The correct answer to this is B and must never leak.',
            'question_type' => 'multiple_choice',
            'options' => ['A' => '3', 'B' => '4', 'C' => '5', 'D' => '6'],
            'correct_answer' => 'B',
            'marks' => 2,
            'status' => 'approved',
        ]);

        CbtExamQuestion::create([
            'exam_id' => $exam->id,
            'question_id' => $question->id,
            'order_index' => 1,
        ]);

        return $exam;
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    public function test_a_candidate_can_pre_download_their_own_papers_media()
    {
        $exam = $this->exam();

        $this->as($this->studentUser)
            ->getJson("/api/v1/cbt/exams/{$exam->id}/offline-package")
            ->assertOk()
            ->assertJsonPath('exam.id', $exam->id)
            ->assertJsonPath('exam.title', 'First CA — Mathematics')
            ->assertJsonPath('question_count', 1)
            ->assertJsonPath('media_manifest.asset_count', 0);
    }

    /**
     * The whole reason the candidate payload is safe: it is checksums and byte
     * sizes, not a paper.
     */
    public function test_the_candidate_package_carries_no_question_text_or_answers()
    {
        $exam = $this->exam();

        $body = $this->as($this->studentUser)
            ->getJson("/api/v1/cbt/exams/{$exam->id}/offline-package")
            ->getContent();

        $this->assertStringNotContainsString('must never leak', $body);
        $this->assertStringNotContainsString('correct_answer', $body);
        $this->assertStringNotContainsString('options', $body);
        // Nor the marking scheme.
        $this->assertStringNotContainsString('pass_mark', $body);
    }

    /** The class binding that stops a JSS1 pupil opening the SS3 mock. */
    public function test_a_candidate_cannot_pre_download_another_classes_paper()
    {
        $exam = $this->exam(['class_id' => $this->ss1->id]);

        $this->as($this->studentUser)
            ->getJson("/api/v1/cbt/exams/{$exam->id}/offline-package")
            ->assertStatus(403);
    }

    /**
     * A draft is not a paper yet. Its media gives away what is coming, and it
     * may still change before publication.
     */
    public function test_a_candidate_cannot_pre_download_an_unpublished_paper()
    {
        $exam = $this->exam(['status' => 'draft']);

        $this->as($this->studentUser)
            ->getJson("/api/v1/cbt/exams/{$exam->id}/offline-package")
            ->assertStatus(403);
    }

    /**
     * Downloading before the paper opens is the entire point — over the school
     * wifi, the day before, rather than on mobile data in the exam hall.
     */
    public function test_a_candidate_may_download_before_the_exam_opens()
    {
        $exam = $this->exam([
            'opens_at' => now()->addDay(),
            'closes_at' => now()->addDays(2),
        ]);

        $this->as($this->studentUser)
            ->getJson("/api/v1/cbt/exams/{$exam->id}/offline-package")
            ->assertOk()
            ->assertJsonPath('exam.id', $exam->id);
    }

    public function test_a_candidate_cannot_download_a_closed_paper()
    {
        $exam = $this->exam(['closes_at' => now()->subDay()]);

        $this->as($this->studentUser)
            ->getJson("/api/v1/cbt/exams/{$exam->id}/offline-package")
            ->assertStatus(403);
    }

    public function test_an_online_only_paper_says_so_rather_than_half_working()
    {
        $exam = $this->exam(['allow_offline' => false]);

        $this->as($this->studentUser)
            ->getJson("/api/v1/cbt/exams/{$exam->id}/offline-package")
            ->assertStatus(403)
            ->assertJsonPath('error', 'This exam must be sat online. Ask your school if you expect to be offline.');
    }

    public function test_a_candidate_at_another_school_gets_nothing()
    {
        $exam = $this->exam();

        $rival = School::create(['name' => 'Rival', 'slug' => 'rival', 'subdomain' => 'rival']);
        $outsiderUser = User::create([
            'name' => 'Outsider', 'email' => 'outsider@rival.test', 'password' => bcrypt('password'),
        ]);
        UserProfile::create(['school_id' => $rival->id, 'user_id' => $outsiderUser->id, 'role' => 'student']);
        Student::create([
            'school_id' => $rival->id,
            'user_id' => $outsiderUser->id,
            'class_id' => SchoolClass::create(['school_id' => $rival->id, 'name' => 'JSS 2'])->id,
            'admission_number' => 'RV/2026/001',
        ]);

        $this->actingAs($outsiderUser, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'rival.localhost'])
            ->getJson("/api/v1/cbt/exams/{$exam->id}/offline-package")
            ->assertStatus(404);
    }

    /** The staff payload is unchanged — they are provisioning a lab. */
    public function test_staff_still_get_the_papers_shape()
    {
        $exam = $this->exam(['pass_mark' => 40]);

        $this->as($this->teacher)
            ->getJson("/api/v1/cbt/exams/{$exam->id}/offline-package")
            ->assertOk()
            ->assertJsonPath('exam.max_attempts', 1)
            // Decimal cast, so it serialises as a string.
            ->assertJsonPath('exam.pass_mark', '40.00')
            ->assertJsonPath('question_count', 1);
    }

    public function test_a_parent_has_no_business_here()
    {
        $exam = $this->exam();
        $parent = $this->user('Mrs Okeke', 'okeke@graceland.test', 'parent');

        $this->as($parent)
            ->getJson("/api/v1/cbt/exams/{$exam->id}/offline-package")
            ->assertStatus(403);
    }
}
