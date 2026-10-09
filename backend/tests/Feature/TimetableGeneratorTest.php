<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The web timetable builder posts an empty body and expects the school's own
 * classes and teachers to be used. It used to get a 422 every time.
 */
class TimetableGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://greenfield.localhost/api/v1/timetable';

    /**
     * SS 2A (science) and SS 2B (arts) get a week each: their own teachers,
     * their own stream's subjects, and no teacher in both arms at once. A
     * student sees only their own arm's week.
     */
    public function test_each_arm_gets_its_own_timetable_with_its_own_teachers(): void
    {
        $school = School::create(['name' => 'Kings', 'slug' => 'kingsarm', 'subdomain' => 'kingsarm']);
        $user = fn (string $name, string $role) => tap(
            User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@kingsarm.test', 'password' => bcrypt('password')]),
            fn ($u) => UserProfile::create(['school_id' => $school->id, 'user_id' => $u->id, 'role' => $role])
        );
        $admin = $user('Principal', 'school_admin');
        [$bello, $okon, $ade, $eze] = [$user('Mr Bello', 'teacher'), $user('Mrs Okon', 'teacher'), $user('Mrs Ade', 'teacher'), $user('Mr Eze', 'teacher')];

        AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(10)]);
        $ss2 = SchoolClass::create(['school_id' => $school->id, 'name' => 'SS 2', 'level_category' => 'senior_secondary']);
        $a = \App\Models\Arm::create(['school_id' => $school->id, 'class_id' => $ss2->id, 'name' => 'A', 'stream' => 'science']);
        $b = \App\Models\Arm::create(['school_id' => $school->id, 'class_id' => $ss2->id, 'name' => 'B', 'stream' => 'arts']);

        $subject = fn (string $name, ?array $streams) => tap(Subject::create(['school_id' => $school->id, 'name' => $name]), fn ($s) => DB::table('class_subjects')->insert([
            'school_id' => $school->id, 'class_id' => $ss2->id, 'subject_id' => $s->id, 'is_compulsory' => true,
            'streams' => $streams ? json_encode($streams) : null, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $maths = $subject('Mathematics', null);
        $english = $subject('English', null);
        $physics = $subject('Physics', ['science']);
        $literature = $subject('Literature', ['arts']);

        $assign = fn ($teacher, $subj, $arm) => DB::table('teacher_subjects')->insert(['school_id' => $school->id, 'teacher_id' => $teacher->id, 'subject_id' => $subj->id, 'class_id' => $ss2->id, 'arm_id' => $arm?->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $assign($bello, $physics, $a);
        $assign($okon, $literature, $b);
        $assign($ade, $maths, $a);
        $assign($eze, $maths, $b);
        $assign($ade, $english, null); // one English teacher for both arms

        $token = $admin->createToken('t')->plainTextToken;
        $res = $this->withToken($token)->postJson('http://kingsarm.localhost/api/v1/timetable/generate', ['max_daily_teacher_workload' => 8])->assertOk();
        $lessons = collect($res->json('timetable'))->where('is_break', false);

        $armA = $lessons->where('arm_id', $a->id);
        $armB = $lessons->where('arm_id', $b->id);
        $this->assertSame(5 * 8, $armA->count());
        $this->assertSame(5 * 8, $armB->count());

        $this->assertContains('Physics', $armA->pluck('subject')->unique());
        $this->assertNotContains('Literature', $armA->pluck('subject')->unique());
        $this->assertContains('Literature', $armB->pluck('subject')->unique());
        $this->assertNotContains('Physics', $armB->pluck('subject')->unique());

        // Each arm's own Mathematics teacher.
        $this->assertSame([$ade->id], $armA->where('subject', 'Mathematics')->pluck('teacher_id')->unique()->values()->all());
        $this->assertSame([$eze->id], $armB->where('subject', 'Mathematics')->pluck('teacher_id')->unique()->values()->all());

        // Mrs Ade teaches in both arms but never in both at once.
        $lessons->groupBy(fn ($e) => "{$e['teacher_id']}|{$e['day']}|{$e['slot']}")->each(fn ($g) => $this->assertCount(1, $g));

        // Published, a student in SS 2B sees SS 2B's week only.
        $this->withToken($token)->postJson('http://kingsarm.localhost/api/v1/timetable/versions/'.$res->json('version.id').'/publish')->assertOk();
        $pupil = $user('Bisi Ade', 'student');
        \App\Models\Student::create(['school_id' => $school->id, 'user_id' => $pupil->id, 'class_id' => $ss2->id, 'arm_id' => $b->id, 'admission_number' => 'K1', 'status' => 'active']);

        $this->app['auth']->forgetGuards();
        $mine = $this->actingAs($pupil, 'sanctum')->getJson('http://kingsarm.localhost/api/v1/me/timetable')->assertOk()->json('days');
        $subjects = collect($mine)->flatten(1)->pluck('subject_name')->filter()->unique();
        $this->assertContains('Literature', $subjects);
        $this->assertNotContains('Physics', $subjects);

        // The view names the arm.
        $view = $this->actingAs($admin, 'sanctum')->getJson("http://kingsarm.localhost/api/v1/timetable/view?class_id={$ss2->id}&arm_id={$a->id}")->assertOk()->json('entries');
        $this->assertSame(['SS 2 A'], collect($view)->pluck('class_name')->unique()->values()->all());
    }

    /**
     * A primary class with a class teacher and no subject assignments gets a
     * full timetable taught by that teacher, all day, past the daily cap that
     * applies to subject teachers; a specialist takes their subject.
     */
    public function test_a_primary_class_teacher_fills_the_timetable_with_a_specialist_for_french(): void
    {
        $school = School::create(['name' => 'Little Stars', 'slug' => 'stars', 'subdomain' => 'stars']);
        $staff = fn (string $name, string $email, string $role) => tap(
            User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]),
            fn ($u) => UserProfile::create(['school_id' => $school->id, 'user_id' => $u->id, 'role' => $role])
        );
        $admin = $staff('Head', 'head@stars.test', 'school_admin');
        $teacher = $staff('Mrs Ade', 'ade@stars.test', 'teacher');
        $french = $staff('Mme Dubois', 'dubois@stars.test', 'teacher');

        $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(10)]);
        $pry2 = SchoolClass::create(['school_id' => $school->id, 'name' => 'Primary 2', 'level_category' => 'primary']);
        $subjects = collect(['English', 'Mathematics', 'Basic Science', 'Social Studies', 'Verbal Reasoning', 'Quantitative Reasoning', 'French'])->map(fn ($n) => Subject::create(['school_id' => $school->id, 'name' => $n]));
        foreach ($subjects as $s) {
            DB::table('class_subjects')->insert(['school_id' => $school->id, 'class_id' => $pry2->id, 'subject_id' => $s->id, 'is_compulsory' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('class_teacher_assignments')->insert(['school_id' => $school->id, 'session_id' => $session->id, 'class_id' => $pry2->id, 'form_teacher_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('teacher_subjects')->insert(['school_id' => $school->id, 'teacher_id' => $french->id, 'subject_id' => $subjects[6]->id, 'class_id' => $pry2->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        $lessons = collect($this->withToken($admin->createToken('t')->plainTextToken)
            ->postJson('http://stars.localhost/api/v1/timetable/generate', [])->assertOk()->json('timetable'))
            ->where('is_break', false);

        $this->assertSame(5 * 8, $lessons->count());
        $this->assertSame([$french->id], $lessons->where('subject', 'French')->pluck('teacher_id')->unique()->values()->all());
        $this->assertSame([$teacher->id], $lessons->where('subject', '!=', 'French')->pluck('teacher_id')->unique()->values()->all());
        // More than the six-a-day cap that binds subject teachers would allow.
        $this->assertGreaterThan(6 * 5, $lessons->where('teacher_id', $teacher->id)->count());
    }

    private function adminToken(string $email = 'admin@greenfield.test'): string
    {
        return User::where('email', $email)->firstOrFail()->createToken('t')->plainTextToken;
    }

    public function test_an_empty_request_builds_a_clash_free_timetable_from_the_schools_records(): void
    {
        $this->seed(DemoSchoolSeeder::class);
        $token = $this->adminToken();

        $response = $this->withToken($token)->postJson(self::URL.'/generate', [])->assertOk();
        $this->assertSame([], $response->json('skipped'));

        $entries = collect($response->json('timetable'));
        $lessons = $entries->where('is_break', false);

        // Fifteen arms (six secondary classes of two, Nursery 2, Primary 4 of
        // two), each its own week: five days, eight periods, two breaks a day.
        $this->assertSame(15 * 5 * 8, $lessons->count());
        $this->assertSame(15 * 5 * 2, $entries->where('is_break', true)->count());

        // No teacher in two rooms at once.
        $lessons->groupBy(fn ($e) => "{$e['teacher_id']}|{$e['day']}|{$e['slot']}")
            ->each(fn ($group) => $this->assertCount(1, $group));

        // Subjects are spread out: no arm gets one subject more than twice a day.
        $lessons->groupBy(fn ($e) => "{$e['class_id']}|{$e['arm_id']}|{$e['day']}|{$e['subject']}")
            ->each(fn ($group) => $this->assertLessThanOrEqual(2, $group->count()));

        // Every JSS subject appears in a JSS 1 week.
        $jss1 = DB::table('classes')->where('name', 'JSS 1')->value('id');
        $this->assertCount(10, $lessons->where('class_id', $jss1)->pluck('subject')->unique());

        $this->assertNotNull($entries->first()['start_time']);
    }

    public function test_a_draft_can_be_previewed_published_and_read_with_names(): void
    {
        $this->seed(DemoSchoolSeeder::class);
        $token = $this->adminToken();

        $versionId = $this->withToken($token)->postJson(self::URL.'/generate', [])->assertOk()->json('version.id');

        $this->withToken($token)->getJson(self::URL.'/versions')
            ->assertOk()
            ->assertJsonPath('versions.0.id', $versionId)
            ->assertJsonPath('versions.0.entries_count', 15 * 5 * 10);

        $draft = $this->withToken($token)->getJson(self::URL."/view?version_id={$versionId}")->assertOk();
        $lesson = collect($draft->json('entries'))->firstWhere('is_break', false);
        $this->assertNotEmpty($lesson['class_name']);
        $this->assertNotEmpty($lesson['teacher_name']);
        $this->assertSame('08:00', substr($lesson['start_time'], 0, 5));

        // Nothing is published yet.
        $this->withToken($token)->getJson(self::URL.'/view')->assertOk()->assertJsonCount(0, 'entries');

        $this->withToken($token)->postJson(self::URL."/versions/{$versionId}/publish")->assertOk();

        $this->withToken($token)->getJson(self::URL.'/view')->assertOk()->assertJsonCount(15 * 5 * 10, 'entries');
    }

    public function test_a_class_without_teachers_is_skipped_not_fatal(): void
    {
        $this->seed(DemoSchoolSeeder::class);
        $schoolId = DB::table('schools')->where('subdomain', 'greenfield')->value('id');

        DB::table('classes')->insert([
            'school_id' => $schoolId, 'name' => 'JSS 1 Evening', 'level_category' => 'junior_secondary',
            'order_index' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($this->adminToken())->postJson(self::URL.'/generate', [])
            ->assertOk()
            ->assertJsonPath('skipped', ['JSS 1 Evening']);
    }

    public function test_a_school_with_no_teacher_assignments_gets_a_readable_message(): void
    {
        $school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop']);
        $admin = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('http://hilltop.localhost/api/v1/timetable/generate', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This school has no classes yet. Create classes first, then generate a timetable.');

        DB::table('classes')->insert([
            'school_id' => $school->id, 'name' => 'JSS 1', 'order_index' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($token)->postJson('http://hilltop.localhost/api/v1/timetable/generate', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No class has subject teachers assigned yet. Assign a teacher to each subject a class takes, then generate again.');
    }

    public function test_a_clash_names_the_class_in_words(): void
    {
        $this->seed(DemoSchoolSeeder::class);
        $schoolId = DB::table('schools')->where('subdomain', 'greenfield')->value('id');

        // Leave JSS 1 with a single teacher: they cannot cover eight periods
        // a day under the default cap of six.
        $jss1 = DB::table('classes')->where('school_id', $schoolId)->where('name', 'JSS 1')->value('id');
        $keep = DB::table('teacher_subjects')->where('class_id', $jss1)->orderBy('id')->value('id');
        DB::table('teacher_subjects')->where('class_id', $jss1)->where('id', '!=', $keep)->delete();

        $message = $this->withToken($this->adminToken())->postJson(self::URL.'/generate', [])
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('JSS 1', $message);
        $this->assertStringNotContainsString("Class {$jss1}", $message);
    }
}
