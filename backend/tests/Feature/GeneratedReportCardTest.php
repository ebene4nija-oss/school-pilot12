<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportCardJob;
use App\Models\AcademicSession;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Term-end report cards: queueing a run, seeing which children are ready,
 * and downloading the stored PDFs.
 */
class GeneratedReportCardTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $admin;
    protected User $teacher;
    protected Term $term;
    protected SchoolClass $class;
    protected Student $ada;
    protected Student $bayo;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://graceland.localhost');
        Storage::fake('local');

        $this->school = School::create(['name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland']);
        $this->admin = $this->makeUser('bello@graceland.test', 'school_admin', $this->school->id);
        $this->teacher = $this->makeUser('tutor@graceland.test', 'teacher', $this->school->id);

        $session = AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-07-31',
        ]);

        $this->term = Term::create([
            'school_id' => $this->school->id,
            'session_id' => $session->id,
            'name' => 'First Term',
            'start_date' => '2026-09-14',
            'end_date' => '2026-12-11',
        ]);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);

        $this->ada = $this->makeStudent('ada', 'GC/001');
        $this->bayo = $this->makeStudent('bayo', 'GC/002');
    }

    public function test_an_admin_queues_a_class_run()
    {
        Bus::fake();

        $this->as($this->admin)
            ->postJson('/api/v1/report-cards/generate', ['term_id' => $this->term->id, 'class_id' => $this->class->id])
            ->assertStatus(202)
            ->assertJsonStructure(['batch_id', 'status_url']);

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 2
            && $batch->name === "report-cards:school:{$this->school->id}");
    }

    public function test_the_list_shows_which_cards_are_ready_and_what_blocks_the_rest()
    {
        Storage::disk('local')->put(
            GenerateReportCardJob::pathFor($this->school->id, $this->term->id, $this->ada->id),
            '%PDF-1.4 ada'
        );

        $subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);
        ScoreEntry::create([
            'school_id' => $this->school->id,
            'term_id' => $this->term->id,
            'student_id' => $this->bayo->id,
            'subject_id' => $subject->id,
            'total_score' => 60,
            'ai_comment_status' => 'pending_approval',
        ]);

        $res = $this->as($this->admin)
            ->getJson("/api/v1/report-cards/generated?term_id={$this->term->id}&class_id={$this->class->id}")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('ready', 1);

        $rows = collect($res->json('data'))->keyBy('student_id');
        $this->assertTrue($rows[$this->ada->id]['ready']);
        $this->assertSame('ada', $rows[$this->ada->id]['name']);
        $this->assertFalse($rows[$this->bayo->id]['ready']);
        $this->assertSame(1, $rows[$this->bayo->id]['pending_ai_comments']);
    }

    public function test_an_admin_downloads_a_generated_card()
    {
        Storage::disk('local')->put(
            GenerateReportCardJob::pathFor($this->school->id, $this->term->id, $this->ada->id),
            '%PDF-1.4 ada'
        );

        $this->as($this->admin)
            ->get("/api/v1/report-cards/generated/{$this->ada->id}/{$this->term->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->as($this->admin)
            ->getJson("/api/v1/report-cards/generated/{$this->bayo->id}/{$this->term->id}")
            ->assertStatus(404);
    }

    public function test_another_schools_card_cannot_be_downloaded()
    {
        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        $otherAdmin = $this->makeUser('admin@other.test', 'school_admin', $other->id);

        Storage::disk('local')->put(
            GenerateReportCardJob::pathFor($this->school->id, $this->term->id, $this->ada->id),
            '%PDF-1.4 ada'
        );

        $this->actingAs($otherAdmin, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'other.localhost'])
            ->getJson("/api/v1/report-cards/generated/{$this->ada->id}/{$this->term->id}")
            ->assertStatus(404);
    }

    public function test_teachers_cannot_list_or_download_the_schools_cards()
    {
        $this->as($this->teacher)
            ->getJson("/api/v1/report-cards/generated?term_id={$this->term->id}")
            ->assertStatus(403);

        $this->as($this->teacher)
            ->getJson("/api/v1/report-cards/generated/{$this->ada->id}/{$this->term->id}")
            ->assertStatus(403);
    }

    private function makeStudent(string $name, string $admission): Student
    {
        $user = $this->makeUser("{$name}@graceland.test", 'student', $this->school->id);

        return Student::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'class_id' => $this->class->id,
            'admission_number' => $admission,
        ]);
    }

    private function makeUser(string $email, string $role, ?int $schoolId): User
    {
        $user = User::create([
            'name' => explode('@', $email)[0],
            'email' => $email,
            'password' => bcrypt('password'),
        ]);

        UserProfile::create(['school_id' => $schoolId, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }
}
