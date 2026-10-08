<?php

namespace Tests\Feature;

use App\Models\Arm;
use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\LiveClass;
use App\Models\LmsSetting;
use App\Models\NotificationLog;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\TeacherScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Virtual classes and learning materials (TASK 14), against its acceptance criteria. */
class LmsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $teacher;
    private User $other;
    private User $parent;
    private User $nurseryParent;
    private SchoolClass $jss1;
    private SchoolClass $jss2;
    private SchoolClass $nursery;
    private Arm $armA;
    private Subject $maths;
    private Student $ada;
    private Student $bayo;
    private Student $chika;
    private Student $dayo;
    private Student $emeka;
    private Student $fola;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        TeacherScope::forget();

        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->admin = $this->user('Admin', 'school_admin');
        $this->teacher = $this->user('Mr Chidi', 'teacher');
        $this->other = $this->user('Mrs Bello', 'teacher');
        $this->parent = $this->user('Mrs Obi', 'parent');
        $this->nurseryParent = $this->user('Mr Ade', 'parent');

        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1', 'level_category' => 'junior_secondary']);
        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'level_category' => 'junior_secondary']);
        $this->nursery = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'Nursery 1', 'level_category' => 'nursery']);
        $this->armA = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'name' => 'A']);
        $armB = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'name' => 'B']);
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);

        foreach ([$this->jss1, $this->nursery] as $class) {
            DB::table('teacher_subjects')->insert(['school_id' => $this->school->id, 'teacher_id' => $this->teacher->id, 'subject_id' => $this->maths->id, 'class_id' => $class->id]);
        }
        DB::table('teacher_subjects')->insert(['school_id' => $this->school->id, 'teacher_id' => $this->other->id, 'subject_id' => $this->maths->id, 'class_id' => $this->jss2->id]);

        $this->ada = $this->student('Ada Obi', $this->jss1, $this->armA);
        $this->bayo = $this->student('Bayo Ade', $this->jss1, $this->armA);
        $this->chika = $this->student('Chika Eze', $this->jss1, $armB);
        $this->dayo = $this->student('Dayo Lawal', $this->jss1, $armB);
        $this->emeka = $this->student('Emeka Nwosu', $this->jss2);
        $this->fola = $this->student('Fola Ade', $this->nursery);

        Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->parent->id])->students()->attach($this->ada->id);
        Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->nurseryParent->id])->students()->attach($this->fola->id);
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function student(string $name, SchoolClass $class, ?Arm $arm = null): Student
    {
        return Student::create(['school_id' => $this->school->id, 'user_id' => $this->user($name, 'student')->id, 'class_id' => $class->id, 'arm_id' => $arm?->id, 'admission_number' => strtoupper(substr($name, 0, 3)).rand(10, 99), 'status' => 'active']);
    }

    private function as(User|Student $who)
    {
        return $this->actingAs($who instanceof Student ? $who->user : $who, 'sanctum');
    }

    private function upload(User $by, array $targets, array $extra = [])
    {
        return $this->as($by)->post("{$this->host}/learning-materials", [
            'title' => 'Fractions notes', 'subject_id' => $this->maths->id, 'class_id' => $this->jss1->id, 'topic' => 'Fractions',
            'file' => UploadedFile::fake()->create('fractions.pdf', 120, 'application/pdf'), 'targets' => $targets,
        ] + $extra, ['Accept' => 'application/json']);
    }

    // ==================================================================
    // Materials (criteria 1, 2, 11)
    // ==================================================================

    public function test_the_app_opens_a_material_through_a_short_lived_signed_link(): void
    {
        $id = $this->upload($this->teacher, [['target_type' => 'class', 'class_id' => $this->jss1->id]])->assertCreated()->json('material.id');

        $this->as($this->emeka)->getJson("{$this->host}/learning-materials/{$id}/link")->assertNotFound();
        $url = $this->as($this->ada)->getJson("{$this->host}/learning-materials/{$id}/link")->assertOk()->json('url');
        $this->app['auth']->forgetGuards();

        // No session needed: the signature is the permission, and it cannot be bent to another file.
        $this->get($url)->assertOk()->assertDownload('fractions.pdf');
        $this->get(str_replace("/learning-materials/{$id}/file", '/learning-materials/'.($id + 1).'/file', $url))->assertForbidden();
        $this->travel(11)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_a_material_reaches_its_class_and_nobody_else(): void
    {
        $id = $this->upload($this->teacher, [['target_type' => 'class', 'class_id' => $this->jss1->id]])->assertCreated()->json('material.id');

        $this->assertSame([$id], array_column($this->as($this->ada)->getJson("{$this->host}/learning-materials")->json('data'), 'id'));
        $this->assertSame([], $this->as($this->emeka)->getJson("{$this->host}/learning-materials")->json('data'));
        $this->as($this->emeka)->get("{$this->host}/learning-materials/{$id}/download")->assertNotFound();

        $this->as($this->ada)->get("{$this->host}/learning-materials/{$id}/download")->assertOk()->assertDownload('fractions.pdf');
        $this->assertSame([$id], array_column($this->as($this->parent)->getJson("{$this->host}/learning-materials")->json('data'), 'id'));

        $reach = $this->as($this->teacher)->getJson("{$this->host}/learning-materials/{$id}/reach")->assertOk()->json();
        $this->assertSame(4, $reach['targeted']);
        $this->assertSame(1, $reach['opened']);

        $this->assertTrue(NotificationLog::withoutGlobalScopes()->where('user_id', $this->bayo->user_id)->where('body', 'like', 'New learning material:%')->exists());
        $this->assertFalse(NotificationLog::withoutGlobalScopes()->where('user_id', $this->emeka->user_id)->exists());

        LmsSetting::create(['school_id' => $this->school->id, 'parents_see_materials' => false]);
        $this->assertSame([], $this->as($this->parent)->getJson("{$this->host}/learning-materials")->json('data'));
    }

    public function test_an_arm_or_a_named_student_narrows_the_audience(): void
    {
        $arm = $this->upload($this->teacher, [['target_type' => 'arm', 'class_id' => $this->jss1->id, 'arm_id' => $this->armA->id]])->json('material.id');
        $one = $this->upload($this->teacher, [['target_type' => 'student', 'student_id' => $this->chika->id]])->json('material.id');

        $this->assertEqualsCanonicalizing([$arm], array_column($this->as($this->ada)->getJson("{$this->host}/learning-materials")->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$one], array_column($this->as($this->chika)->getJson("{$this->host}/learning-materials")->json('data'), 'id'));
        $this->assertSame([], $this->as($this->dayo)->getJson("{$this->host}/learning-materials")->json('data'));
    }

    public function test_a_teacher_shares_only_within_their_own_classes(): void
    {
        $this->upload($this->teacher, [['target_type' => 'class', 'class_id' => $this->jss2->id]])->assertForbidden();
        $this->upload($this->teacher, [['target_type' => 'student', 'student_id' => $this->emeka->id]])->assertForbidden();
        $this->upload($this->teacher, [['target_type' => 'section', 'section' => 'secondary']])->assertForbidden();
        $this->upload($this->admin, [['target_type' => 'section', 'section' => 'secondary']])->assertCreated();
    }

    public function test_unsafe_file_types_are_refused(): void
    {
        $this->as($this->teacher)->post("{$this->host}/learning-materials", [
            'title' => 'Tool', 'file' => UploadedFile::fake()->create('setup.exe', 10), 'targets' => [['target_type' => 'class', 'class_id' => $this->jss1->id]],
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_a_link_material_returns_its_address(): void
    {
        $id = $this->as($this->teacher)->postJson("{$this->host}/learning-materials", [
            'title' => 'Khan video', 'url' => 'https://www.khanacademy.org/math/fractions', 'targets' => [['target_type' => 'class', 'class_id' => $this->jss1->id]],
        ])->assertCreated()->json('material.id');

        $this->as($this->ada)->getJson("{$this->host}/learning-materials/{$id}/download")->assertJsonPath('url', 'https://www.khanacademy.org/math/fractions');
    }

    public function test_with_approval_on_a_material_waits_for_a_reviewer(): void
    {
        LmsSetting::create(['school_id' => $this->school->id, 'require_material_approval' => true]);
        $res = $this->upload($this->teacher, [['target_type' => 'class', 'class_id' => $this->jss1->id]])->assertCreated();
        $id = $res->json('material.id');
        $this->assertSame('pending_approval', $res->json('material.status'));

        $this->assertSame([], $this->as($this->ada)->getJson("{$this->host}/learning-materials")->json('data'));
        $this->as($this->other)->postJson("{$this->host}/learning-materials/{$id}/review", ['approve' => true])->assertForbidden();
        $this->as($this->admin)->postJson("{$this->host}/learning-materials/{$id}/review", ['approve' => false])->assertStatus(422);
        $this->as($this->admin)->postJson("{$this->host}/learning-materials/{$id}/review", ['approve' => true])->assertOk();

        $this->assertCount(1, $this->as($this->ada)->getJson("{$this->host}/learning-materials")->json('data'));
    }

    // ==================================================================
    // Lesson plans
    // ==================================================================

    public function test_a_lesson_plan_is_drafted_submitted_reviewed_and_then_fixed(): void
    {
        $this->as($this->teacher)->postJson("{$this->host}/lesson-plans", ['class_id' => $this->jss2->id, 'subject_id' => $this->maths->id, 'topic' => 'Fractions'])->assertForbidden();

        $id = $this->as($this->teacher)->postJson("{$this->host}/lesson-plans", [
            'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'topic' => 'Introduction to fractions', 'duration_minutes' => 40,
            'objectives' => 'Identify and represent simple fractions', 'assessment' => 'Five-question quiz',
        ])->assertCreated()->json('plan.id');

        $this->assertSame([], $this->as($this->other)->getJson("{$this->host}/lesson-plans")->json('data'), 'A draft is the author\'s alone.');

        $this->as($this->teacher)->postJson("{$this->host}/lesson-plans/{$id}/submit")->assertOk();
        $this->as($this->admin)->postJson("{$this->host}/lesson-plans/{$id}/review", ['approve' => true])->assertOk();
        $this->as($this->teacher)->putJson("{$this->host}/lesson-plans/{$id}", ['topic' => 'Changed'])->assertStatus(409);

        $copy = $this->as($this->teacher)->postJson("{$this->host}/lesson-plans/{$id}/copy")->assertCreated()->json('plan');
        $this->assertSame('draft', $copy['status']);
    }

    // ==================================================================
    // Assignments (criteria 6, 7)
    // ==================================================================

    public function test_an_assignment_for_one_arm_is_invisible_to_the_other(): void
    {
        $id = $this->as($this->teacher)->postJson("{$this->host}/academics/homework", [
            'class_id' => $this->jss1->id, 'arm_id' => $this->armA->id, 'subject_id' => $this->maths->id, 'title' => 'Fractions worksheet',
            'description' => 'Do questions 1-10', 'due_date' => now()->addDays(3)->toDateString(), 'max_score' => 20,
        ])->assertCreated()->json('homework.id');

        $this->assertSame([$id], array_column($this->as($this->ada)->getJson("{$this->host}/academics/homework")->json('data'), 'id'));
        $this->assertSame([], $this->as($this->chika)->getJson("{$this->host}/academics/homework")->json('data'));
        $this->as($this->chika)->postJson("{$this->host}/academics/homework/{$id}/submit", ['body' => 'Mine'])->assertForbidden();

        $this->assertSame(2, $this->as($this->teacher)->getJson("{$this->host}/academics/homework/{$id}/submissions")->json('summary.class_size'));

        // Graded out of the assignment's own maximum.
        $this->as($this->ada)->postJson("{$this->host}/academics/homework/{$id}/submit", ['body' => 'Answers'])->assertCreated();
        $sub = HomeworkSubmission::first();
        $this->as($this->teacher)->postJson("{$this->host}/academics/submissions/{$sub->id}/grade", ['marks_awarded' => 18])->assertOk();
        $this->assertSame(20.0, (float) $sub->fresh()->marks_available);
    }

    public function test_a_draft_is_hidden_until_published_and_then_announced(): void
    {
        $id = $this->as($this->teacher)->postJson("{$this->host}/academics/homework", [
            'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'title' => 'Draft work', 'description' => 'x', 'due_date' => now()->addDays(3)->toDateString(), 'draft' => true,
        ])->assertCreated()->json('homework.id');

        $this->assertSame([], $this->as($this->ada)->getJson("{$this->host}/academics/homework")->json('data'));
        $this->as($this->ada)->postJson("{$this->host}/academics/homework/{$id}/submit", ['body' => 'x'])->assertForbidden();

        $this->as($this->teacher)->postJson("{$this->host}/academics/homework/{$id}/publish")->assertOk();
        $this->assertCount(1, $this->as($this->ada)->getJson("{$this->host}/academics/homework")->json('data'));
        $this->assertTrue(NotificationLog::withoutGlobalScopes()->where('user_id', $this->parent->id)->where('body', 'like', 'New assignment for your child:%')->exists());
    }

    public function test_file_submissions_late_policy_and_return_for_correction(): void
    {
        $hw = Homework::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->teacher->id, 'title' => 'Project', 'description' => 'Build it', 'due_date' => now()->addDay()->toDateString(), 'submission_type' => 'file', 'allow_late' => false, 'status' => 'published']);

        $this->as($this->ada)->postJson("{$this->host}/academics/homework/{$hw->id}/submit", ['body' => 'no file'])->assertStatus(422);
        $res = $this->as($this->ada)->post("{$this->host}/academics/homework/{$hw->id}/submit", ['files' => [UploadedFile::fake()->image('page1.jpg'), UploadedFile::fake()->create('page2.pdf', 50, 'application/pdf')]], ['Accept' => 'application/json'])->assertCreated();
        $this->assertCount(2, $res->json('files'));
        $fileId = $res->json('files.0.id');

        $this->as($this->teacher)->get("{$this->host}/academics/submission-files/{$fileId}")->assertOk();
        $this->as($this->other)->get("{$this->host}/academics/submission-files/{$fileId}")->assertNotFound();
        $this->as($this->parent)->get("{$this->host}/academics/submission-files/{$fileId}")->assertOk();

        $sub = HomeworkSubmission::first();
        $this->as($this->teacher)->postJson("{$this->host}/academics/submissions/{$sub->id}/return", ['note' => 'Show your working'])->assertOk();

        $this->travel(3)->days();
        // Past the deadline: a new submission is refused, a returned one may come back.
        $this->as($this->bayo)->post("{$this->host}/academics/homework/{$hw->id}/submit", ['files' => [UploadedFile::fake()->image('late.jpg')]], ['Accept' => 'application/json'])->assertStatus(409);
        $this->as($this->ada)->post("{$this->host}/academics/homework/{$hw->id}/submit", ['files' => [UploadedFile::fake()->image('fixed.jpg')]], ['Accept' => 'application/json'])->assertOk();

        $sub->refresh();
        $this->assertSame('submitted', $sub->status);
        $this->assertSame(1, (int) $sub->resubmissions);
        $this->assertFalse($sub->is_late, 'Lateness was fixed at the first, on-time submission.');
        $this->assertSame(1, DB::table('homework_submission_files')->where('submission_id', $sub->id)->count());
    }

    public function test_a_parent_submits_for_a_nursery_child_only_where_allowed(): void
    {
        $nurseryHw = Homework::create(['school_id' => $this->school->id, 'class_id' => $this->nursery->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->teacher->id, 'title' => 'Colour the shapes', 'description' => 'Photo of the page', 'due_date' => now()->addDay()->toDateString(), 'allow_parent_assist' => true, 'status' => 'published']);
        $jssHw = Homework::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->teacher->id, 'title' => 'Algebra', 'description' => 'x', 'due_date' => now()->addDay()->toDateString(), 'allow_parent_assist' => true, 'status' => 'published']);

        $this->as($this->nurseryParent)->postJson("{$this->host}/parent/homework/{$nurseryHw->id}/submit", ['student_id' => $this->fola->id, 'body' => 'Done with mum'])->assertCreated();
        $this->assertTrue((bool) HomeworkSubmission::where('student_id', $this->fola->id)->value('parent_assisted'));

        $this->as($this->parent)->postJson("{$this->host}/parent/homework/{$jssHw->id}/submit", ['student_id' => $this->ada->id, 'body' => 'x'])->assertForbidden();
        $this->as($this->parent)->postJson("{$this->host}/parent/homework/{$nurseryHw->id}/submit", ['student_id' => $this->fola->id, 'body' => 'x'])->assertNotFound();
    }

    // ==================================================================
    // Virtual classes (criteria 3, 4, 5)
    // ==================================================================

    private function liveClass(array $over = []): LiveClass
    {
        return LiveClass::create($over + ['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->teacher->id, 'title' => 'Fractions live', 'provider' => 'google_meet', 'meeting_url' => 'https://meet.google.com/abc-defg-hij', 'scheduled_for' => now()->addMinutes(2), 'duration_minutes' => 40, 'status' => 'scheduled']);
    }

    public function test_weekly_scheduling_notifies_families_and_recording_needs_a_policy(): void
    {
        $base = ['title' => 'Maths', 'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'provider' => 'zoom', 'meeting_url' => 'https://zoom.us/j/1', 'scheduled_for' => now()->addDay()->toIso8601String(), 'duration_minutes' => 40];

        $this->as($this->teacher)->postJson("{$this->host}/live-classes", $base + ['recording_enabled' => true])->assertStatus(422);
        $this->as($this->teacher)->postJson("{$this->host}/live-classes", $base + ['repeat_weekly_until' => now()->addDays(22)->toDateString()])->assertCreated()->assertJsonPath('sessions', 4);

        $this->assertSame(4, LiveClass::whereNotNull('series_id')->count());
        $this->assertTrue(NotificationLog::withoutGlobalScopes()->where('user_id', $this->parent->id)->where('body', 'like', 'Virtual class scheduled:%')->exists());

        $first = LiveClass::orderBy('scheduled_for')->first();
        $this->as($this->teacher)->postJson("{$this->host}/live-classes/{$first->id}/cancel", ['reason' => 'Mid-term break', 'whole_series' => true])->assertJsonPath('cancelled', 4);
    }

    public function test_only_the_class_joins_and_only_once_open(): void
    {
        $lc = $this->liveClass(['scheduled_for' => now()->addHour()]);

        $this->as($this->ada)->postJson("{$this->host}/live-classes/{$lc->id}/join")->assertStatus(409);
        $this->travel(58)->minutes();
        $this->as($this->emeka)->postJson("{$this->host}/live-classes/{$lc->id}/join")->assertNotFound();
        $this->as($this->ada)->postJson("{$this->host}/live-classes/{$lc->id}/join")->assertOk()->assertJsonPath('meeting_url', 'https://meet.google.com/abc-defg-hij');
    }

    /** Attendance is time in the lesson, not a click on the link (§3.3). */
    public function test_attendance_comes_from_time_connected(): void
    {
        $lc = $this->liveClass();
        $start = $lc->scheduled_for->copy();

        $this->as($this->ada)->postJson("{$this->host}/live-classes/{$lc->id}/join")->assertOk();   // stays the whole lesson
        $this->as($this->bayo)->postJson("{$this->host}/live-classes/{$lc->id}/join")->assertOk();  // leaves after ten minutes

        for ($m = -1; $m <= 42; $m++) {
            $this->travelTo($start->copy()->addMinutes($m));
            $this->as($this->ada)->postJson("{$this->host}/live-classes/{$lc->id}/heartbeat");
            if ($m <= 10) {
                $this->as($this->bayo)->postJson("{$this->host}/live-classes/{$lc->id}/heartbeat", ['leaving' => $m === 10]);
            }
            if ($m === 15) {
                $this->as($this->chika)->postJson("{$this->host}/live-classes/{$lc->id}/join")->assertOk();  // joins late
            }
            if ($m > 15) {
                $this->as($this->chika)->postJson("{$this->host}/live-classes/{$lc->id}/heartbeat");
            }
        }

        $rows = collect($this->as($this->teacher)->getJson("{$this->host}/live-classes/{$lc->id}/attendance")->assertOk()->json('students'))->keyBy('student_id');
        $this->assertSame('present', $rows[$this->ada->id]['status']);
        $this->assertSame('partial', $rows[$this->bayo->id]['status']);
        $this->assertSame('late', $rows[$this->chika->id]['status']);
        $this->assertSame('absent', $rows[$this->dayo->id]['status']);
        $this->assertEqualsWithDelta(40, $rows[$this->ada->id]['minutes_connected'], 0.1, 'Time is capped at the lesson window.');

        // Bayo's connection dropped: the teacher corrects, with a reason, on the record.
        $this->as($this->teacher)->putJson("{$this->host}/live-classes/{$lc->id}/attendance/{$this->bayo->id}", ['status' => 'present'])->assertStatus(422);
        $this->as($this->other)->putJson("{$this->host}/live-classes/{$lc->id}/attendance/{$this->bayo->id}", ['status' => 'present', 'reason' => 'x x x'])->assertForbidden();
        $this->as($this->teacher)->putJson("{$this->host}/live-classes/{$lc->id}/attendance/{$this->bayo->id}", ['status' => 'present', 'reason' => 'Network dropped; was in class'])->assertOk();
        $this->assertTrue(AuditLog::where('action', 'lms.virtual_attendance_corrected')->exists());
        $this->assertSame('present', collect($this->as($this->teacher)->getJson("{$this->host}/live-classes/{$lc->id}/attendance")->json('students'))->firstWhere('student_id', $this->bayo->id)['status']);
    }

    public function test_switching_off_virtual_classes_closes_joining(): void
    {
        $lc = $this->liveClass();
        $this->school->forceFill(['module_switches' => ['live_classes' => false]])->save();

        $this->as($this->ada)->postJson("{$this->host}/live-classes/{$lc->id}/join")->assertForbidden();
    }

    // ==================================================================
    // Dashboards (criteria 9, 10)
    // ==================================================================

    public function test_student_parent_and_staff_views(): void
    {
        $this->liveClass(['scheduled_for' => now()->addDay()]);
        Homework::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->teacher->id, 'title' => 'Due soon', 'description' => 'x', 'due_date' => now()->addDays(2)->toDateString(), 'status' => 'published']);
        $missed = Homework::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->teacher->id, 'title' => 'Last week', 'description' => 'x', 'due_date' => now()->subDays(3)->toDateString(), 'status' => 'published']);
        Homework::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->teacher->id, 'title' => 'Before that', 'description' => 'x', 'due_date' => now()->subDays(5)->toDateString(), 'status' => 'published']);
        HomeworkSubmission::create(['school_id' => $this->school->id, 'homework_id' => $missed->id, 'student_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()->subDays(4), 'status' => 'submitted']);

        $me = $this->as($this->ada)->getJson("{$this->host}/me/learning")->assertOk()->json();
        $this->assertCount(1, $me['upcoming_classes']);
        $this->assertEquals(['pending' => 1, 'submitted' => 1, 'missed' => 1], array_intersect_key($me['assignments']['counts'], ['pending' => 0, 'submitted' => 0, 'missed' => 0]));

        $kids = $this->as($this->parent)->getJson("{$this->host}/parent/learning")->assertOk()->json('children');
        $this->assertCount(1, $kids);
        $this->as($this->parent)->getJson("{$this->host}/parent/learning?student_id={$this->emeka->id}")->assertNotFound();

        $o = $this->as($this->teacher)->getJson("{$this->host}/lms/overview")->assertOk()->json();
        $this->assertTrue($o['scope']['teacher_only']);
        $this->assertSame(3, $o['metrics']['assignments_set']);
        // Two past assignments × four students = 8 owed, 1 handed in.
        $this->assertSame(12.5, $o['metrics']['submission_rate']);
        $this->assertContains($this->bayo->id, array_column($o['needs_support'], 'student_id'));
        $this->assertNotContains($this->ada->id, array_column($o['needs_support'], 'student_id'));

        $this->as($this->teacher)->getJson("{$this->host}/lms/overview?section=primary")->assertOk();
        $this->as($this->ada)->getJson("{$this->host}/lms/overview")->assertForbidden();
    }

    public function test_reminders_go_once(): void
    {
        $this->liveClass(['scheduled_for' => now()->addMinutes(20)]);
        Homework::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->teacher->id, 'title' => 'Tomorrow', 'description' => 'x', 'due_date' => now()->addDay()->toDateString(), 'status' => 'published']);

        // The real CLI marks platform commands cross-tenant on start-up; an
        // in-process artisan() call skips that, so say it here.
        $this->assertSame(0, $this->acrossSchools(fn () => $this->artisan('lms:remind')->run()));
        $this->assertSame(0, $this->acrossSchools(fn () => $this->artisan('lms:remind')->run()));

        $this->assertSame(1, NotificationLog::withoutGlobalScopes()->where('user_id', $this->ada->user_id)->where('body', 'like', 'Virtual class soon:%')->count());
        $this->assertSame(1, NotificationLog::withoutGlobalScopes()->where('user_id', $this->ada->user_id)->where('body', 'like', 'Homework due tomorrow:%')->count());
    }
}
