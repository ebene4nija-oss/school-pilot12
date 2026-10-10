<?php

namespace Tests\Feature;

use App\Models\Arm;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Setting up many classes and arms at once: preview, commit, safe to repeat. */
class ClassImportTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private string $url = 'http://hilltop.localhost/api/v1/classes/import';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop']);
        $this->admin = User::factory()->create();
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $this->admin->id, 'role' => 'school_admin']);
    }

    private function rows(): array
    {
        return [
            ['class' => 'JSS 1', 'order' => 1, 'arms' => 'A;B;C'],
            ['class' => 'JSS 2', 'order' => 2, 'arms' => 'A;B'],
            ['class' => 'SS 1', 'level' => 'senior_secondary', 'order' => 4, 'arms' => 'Science;Arts'],
            ['class' => 'Primary 1', 'arms' => 'Gold'],
        ];
    }

    public function test_preview_creates_nothing_and_commit_creates_everything()
    {
        $preview = $this->actingAs($this->admin, 'sanctum')->postJson($this->url, ['rows' => $this->rows()])->assertOk();
        $this->assertSame(['classes_to_create' => 4, 'arms_to_create' => 8, 'already_there' => 0], $preview->json('summary'));
        $this->assertSame(0, SchoolClass::where('school_id', $this->school->id)->count(), 'A preview saves nothing.');

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url, ['rows' => $this->rows(), 'commit' => true])
            ->assertOk()->assertJsonPath('committed', true);

        $jss1 = SchoolClass::where('school_id', $this->school->id)->where('name', 'JSS 1')->first();
        $this->assertSame('junior_secondary', $jss1->level_category, 'Level worked out from the name.');
        $this->assertSame(['A', 'B', 'C'], Arm::where('class_id', $jss1->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame('primary', SchoolClass::where('name', 'Primary 1')->value('level_category'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'classes.imported', 'school_id' => $this->school->id]);
    }

    public function test_running_it_again_adds_only_what_is_new()
    {
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url, ['rows' => $this->rows(), 'commit' => true])->assertOk();

        $again = [['class' => 'jss 1', 'arms' => 'A;b;D']]; // any case; D is new
        $res = $this->actingAs($this->admin, 'sanctum')->postJson($this->url, ['rows' => $again, 'commit' => true])->assertOk();

        $this->assertSame(0, $res->json('summary.classes_to_create'));
        $this->assertSame(1, $res->json('summary.arms_to_create'));
        $this->assertSame(1, SchoolClass::where('school_id', $this->school->id)->whereRaw('lower(name) = ?', ['jss 1'])->count());
        $jss1 = SchoolClass::where('name', 'JSS 1')->first();
        $this->assertSame(4, Arm::where('class_id', $jss1->id)->count());
    }

    public function test_a_class_whose_level_cannot_be_told_is_reported_not_guessed()
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson($this->url, [
            'rows' => [['class' => 'Year Seven', 'arms' => 'A'], ['class' => 'JSS 1']],
            'commit' => true,
        ])->assertStatus(422);

        $this->assertSame(1, $res->json('errors.0.row'));
        $this->assertSame(0, SchoolClass::where('school_id', $this->school->id)->count(), 'Nothing half-imported.');
    }

    public function test_only_an_admin_may_import()
    {
        $teacher = User::factory()->create();
        UserProfile::create(['school_id' => $this->school->id, 'user_id' => $teacher->id, 'role' => 'teacher']);

        $this->actingAs($teacher, 'sanctum')->postJson($this->url, ['rows' => $this->rows(), 'commit' => true])->assertForbidden();
    }
}
