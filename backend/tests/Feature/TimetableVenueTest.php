<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Venues on the timetable and venue clashes (TASK 07). */
class TimetableVenueTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $chidi;
    private User $bello;
    private SchoolClass $jss1;
    private SchoolClass $jss2;
    private Venue $lab;
    private Venue $field;
    private Venue $jss2Room;
    private int $version;
    private array $e = [];
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 07:00:00'); // Monday

        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->admin = $this->user('Admin', 'school_admin');
        $this->chidi = $this->user('Mr Chidi', 'teacher');
        $this->bello = $this->user('Mrs Bello', 'teacher');
        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Basic Science', 'code' => 'BSC']);

        $this->as($this->admin)->postJson("{$this->host}/venues", ['name' => 'JSS 1 Room', 'class_id' => $this->jss1->id])->assertCreated();
        $this->jss2Room = Venue::find($this->as($this->admin)->postJson("{$this->host}/venues", ['name' => 'JSS 2 Room', 'class_id' => $this->jss2->id])->json('venue.id'));
        $this->lab = Venue::find($this->as($this->admin)->postJson("{$this->host}/venues", ['name' => 'Science Lab', 'kind' => 'lab', 'capacity' => 40])->json('venue.id'));
        $this->field = Venue::find($this->as($this->admin)->postJson("{$this->host}/venues", ['name' => 'Sports Field', 'kind' => 'field', 'shared' => true])->json('venue.id'));
        $this->as($this->chidi)->postJson("{$this->host}/venues", ['name' => 'Staff Room'])->assertForbidden();

        $this->version = DB::table('timetable_versions')->insertGetId(['school_id' => $this->school->id, 'name' => 'T1', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['jss1P1', $this->jss1, $this->chidi, 'P1', '08:00', '08:40'], ['jss2P1', $this->jss2, $this->bello, 'P1', '08:00', '08:40'], ['jss2P2', $this->jss2, $this->chidi, 'P2', '08:40', '09:20']] as [$key, $class, $teacher, $slot, $start, $end]) {
            $this->e[$key] = DB::table('timetable_entries')->insertGetId([
                'timetable_version_id' => $this->version, 'school_class_id' => $class->id, 'subject_id' => $subject->id, 'subject_name' => 'Basic Science',
                'teacher_id' => $teacher->id, 'day_of_week' => 'Monday', 'slot_name' => $slot, 'start_time' => $start, 'end_time' => $end, 'is_break' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u, 'sanctum');
    }

    public function test_a_teacher_sets_where_their_lesson_is_and_cannot_take_a_booked_venue(): void
    {
        $this->as($this->bello)->putJson("{$this->host}/timetable/entries/{$this->e['jss2P1']}/venue", ['venue_id' => $this->lab->id])->assertOk()->assertJsonPath('venue', 'Science Lab');

        $this->as($this->chidi)->putJson("{$this->host}/timetable/entries/{$this->e['jss1P1']}/venue", ['venue_id' => $this->lab->id])
            ->assertStatus(409)->assertJsonPath('message', 'Science Lab is taken then by JSS 2 (P1).');
        $this->as($this->chidi)->putJson("{$this->host}/timetable/entries/{$this->e['jss2P1']}/venue", ['venue_id' => null])->assertForbidden();

        // JSS 2's own room is free at P1 now that JSS 2 is in the lab; the field takes anyone.
        $free = collect($this->as($this->chidi)->getJson("{$this->host}/timetable/entries/{$this->e['jss1P1']}/free-venues")->json('data'))->pluck('name')->all();
        $this->assertSame(['JSS 1 Room', 'JSS 2 Room', 'Sports Field'], $free);
    }

    public function test_a_lesson_moved_into_another_class_s_home_room_clashes(): void
    {
        $this->as($this->chidi)->putJson("{$this->host}/timetable/entries/{$this->e['jss1P1']}/venue", ['venue_id' => $this->jss2Room->id])
            ->assertStatus(409)->assertJsonPath('message', 'JSS 2 Room is taken then by JSS 2 (P1).');
    }

    public function test_a_double_booked_venue_blocks_publishing_but_a_shared_one_does_not(): void
    {
        DB::table('timetable_entries')->whereIn('id', [$this->e['jss1P1'], $this->e['jss2P1']])->update(['venue_id' => $this->lab->id]);
        $res = $this->as($this->admin)->postJson("{$this->host}/timetable/versions/{$this->version}/publish")->assertStatus(422);
        $this->assertSame('venue', $res->json('clashes.0.type'));
        $this->assertStringContainsString('Science Lab has JSS 1 and JSS 2', $res->json('message'));

        DB::table('timetable_entries')->whereIn('id', [$this->e['jss1P1'], $this->e['jss2P1']])->update(['venue_id' => $this->field->id]);
        $this->as($this->admin)->postJson("{$this->host}/timetable/versions/{$this->version}/publish")->assertOk();

        $view = collect($this->as($this->admin)->getJson("{$this->host}/timetable/view")->json('entries'))->keyBy('id');
        $this->assertSame('Sports Field', $view[$this->e['jss1P1']]['venue_name']);
        $this->assertSame('JSS 2 Room', $view[$this->e['jss2P2']]['venue_name'], 'No venue set: the class\'s own room.');
    }

    public function test_a_one_day_move_shows_on_the_students_day(): void
    {
        $this->as($this->admin)->postJson("{$this->host}/timetable/versions/{$this->version}/publish")->assertOk();
        $ada = $this->user('Ada Obi', 'student');
        Student::create(['school_id' => $this->school->id, 'user_id' => $ada->id, 'class_id' => $this->jss2->id, 'admission_number' => 'A1', 'status' => 'active']);

        $this->as($this->chidi)->postJson("{$this->host}/timetable/entries/{$this->e['jss2P2']}/venue-changes", ['date' => '2026-10-06', 'venue_id' => $this->lab->id])->assertStatus(422);
        $this->as($this->chidi)->postJson("{$this->host}/timetable/entries/{$this->e['jss2P2']}/venue-changes", ['date' => '2026-10-05', 'venue_id' => $this->lab->id, 'reason' => 'Practical'])->assertCreated();

        $periods = collect($this->as($ada)->getJson("{$this->host}/me/dashboard")->assertOk()->json('today.periods'))->keyBy('slot_name');
        $this->assertSame('Science Lab', $periods['P2']['venue']);
        $this->assertSame('JSS 2 Room', $periods['P1']['venue']);

        // The lab is now taken at P2 that day, but not on other Mondays.
        $other = DB::table('timetable_entries')->insertGetId(['timetable_version_id' => $this->version, 'school_class_id' => $this->jss1->id, 'subject_name' => 'Agric', 'teacher_id' => $this->bello->id, 'day_of_week' => 'Monday', 'slot_name' => 'P2', 'start_time' => '08:40', 'end_time' => '09:20', 'is_break' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->as($this->bello)->postJson("{$this->host}/timetable/entries/{$other}/venue-changes", ['date' => '2026-10-05', 'venue_id' => $this->lab->id])->assertStatus(409);
        $this->as($this->bello)->postJson("{$this->host}/timetable/entries/{$other}/venue-changes", ['date' => '2026-10-12', 'venue_id' => $this->lab->id])->assertCreated();
    }
}
