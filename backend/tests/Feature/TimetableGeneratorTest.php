<?php

namespace Tests\Feature;

use App\Models\School;
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

        // Six classes, five days, eight periods each, plus two breaks a day.
        $this->assertSame(6 * 5 * 8, $lessons->count());
        $this->assertSame(6 * 5 * 2, $entries->where('is_break', true)->count());

        // No teacher in two rooms at once.
        $lessons->groupBy(fn ($e) => "{$e['teacher_id']}|{$e['day']}|{$e['slot']}")
            ->each(fn ($group) => $this->assertCount(1, $group));

        // Subjects are spread out: no class gets one subject more than twice a day.
        $lessons->groupBy(fn ($e) => "{$e['class_id']}|{$e['day']}|{$e['subject']}")
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
            ->assertJsonPath('versions.0.entries_count', 6 * 5 * 10);

        $draft = $this->withToken($token)->getJson(self::URL."/view?version_id={$versionId}")->assertOk();
        $lesson = collect($draft->json('entries'))->firstWhere('is_break', false);
        $this->assertNotEmpty($lesson['class_name']);
        $this->assertNotEmpty($lesson['teacher_name']);
        $this->assertSame('08:00', substr($lesson['start_time'], 0, 5));

        // Nothing is published yet.
        $this->withToken($token)->getJson(self::URL.'/view')->assertOk()->assertJsonCount(0, 'entries');

        $this->withToken($token)->postJson(self::URL."/versions/{$versionId}/publish")->assertOk();

        $this->withToken($token)->getJson(self::URL.'/view')->assertOk()->assertJsonCount(6 * 5 * 10, 'entries');
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
