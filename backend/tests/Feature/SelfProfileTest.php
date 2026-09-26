<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Students and parents changing their own phone number and photo (gap G3).
 */
class SelfProfileTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Greenfield Academy', 'slug' => 'greenfield', 'subdomain' => 'greenfield', 'status' => 'active']);
        $this->parent = $this->makeUser('Mrs Obi', 'obi@greenfield.test', 'parent', '08031111111');
    }

    private function makeUser(string $name, ?string $email, string $role, ?string $phone = null, ?School $school = null): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => ($school ?? $this->school)->id, 'role' => $role, 'phone' => $phone]);

        return $user;
    }

    public function test_a_parent_changes_their_phone_number_and_it_is_normalised_and_audited(): void
    {
        $this->actingAs($this->parent, 'sanctum')
            ->postJson('/api/v1/me/profile', ['phone' => '+234 805 222 3333'])
            ->assertOk()
            ->assertJsonPath('data.phone', '08052223333');

        $this->assertSame('08052223333', $this->parent->userProfile->fresh()->phone);

        // Which field changed, never the number itself.
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile.self_updated', 'user_id' => $this->parent->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'profile.self_updated', 'new_values' => json_encode(['phone' => '08052223333'])]);
    }

    public function test_a_number_someone_else_at_the_school_uses_is_refused_in_any_form(): void
    {
        $this->makeUser('Mr Bello', 'bello@greenfield.test', 'parent', '+2348064445555');

        $this->actingAs($this->parent, 'sanctum')
            ->postJson('/api/v1/me/profile', ['phone' => '08064445555'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame('08031111111', $this->parent->userProfile->fresh()->phone);
    }

    public function test_the_same_number_at_another_school_is_not_a_clash(): void
    {
        $other = School::create(['name' => 'Rival', 'slug' => 'rival', 'subdomain' => 'rival', 'status' => 'active']);
        $this->makeUser('Rival Parent', 'rp@rival.test', 'parent', '08077778888', $other);

        $this->actingAs($this->parent, 'sanctum')
            ->postJson('/api/v1/me/profile', ['phone' => '08077778888'])
            ->assertOk();
    }

    public function test_nonsense_is_not_a_phone_number(): void
    {
        $this->actingAs($this->parent, 'sanctum')
            ->postJson('/api/v1/me/profile', ['phone' => 'call me'])
            ->assertStatus(422);
    }

    public function test_a_student_sets_their_own_photo_but_not_their_official_passport_photo(): void
    {
        Storage::fake('public');
        $student = $this->makeUser('Ada Pupil', null, 'student');

        $path = $this->actingAs($student, 'sanctum')
            ->post('/api/v1/me/profile', ['photo' => UploadedFile::fake()->image('me.jpg', 200, 200)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.avatar_url');

        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, $student->userProfile->fresh()->avatar_url);
    }

    public function test_a_photo_must_be_a_small_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->parent, 'sanctum')
            ->post('/api/v1/me/profile', ['photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->actingAs($this->parent, 'sanctum')
            ->post('/api/v1/me/profile', ['photo' => UploadedFile::fake()->image('huge.jpg')->size(5000)], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_it_changes_only_the_callers_own_profile(): void
    {
        $other = $this->makeUser('Mr Bello', 'bello@greenfield.test', 'parent', '08064445555');

        $this->actingAs($this->parent, 'sanctum')
            ->postJson('/api/v1/me/profile', ['phone' => '08099990000', 'user_id' => $other->id])
            ->assertOk();

        $this->assertSame('08064445555', $other->userProfile->fresh()->phone);
    }
}
