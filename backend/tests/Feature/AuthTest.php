<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_receive_sanctum_token()
    {
        $school = School::create([
            'name' => 'Spring Valley College',
            'slug' => 'spring-valley',
            'subdomain' => 'springvalley',
        ]);

        $user = User::create([
            'name' => 'Principal David',
            'email' => 'david@springvalley.edu.ng',
            'password' => Hash::make('secret123'),
        ]);

        UserProfile::create([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'role' => 'school_admin',
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => 'springvalley.localhost'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'david@springvalley.edu.ng',
                'password' => 'secret123',
                'subdomain' => 'springvalley',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']])
            ->assertJson(['user' => ['role' => 'school_admin']]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'user.login',
        ]);
    }

    public function test_school_admin_can_invite_new_teacher()
    {
        $school = School::create([
            'name' => 'Spring Valley College',
            'slug' => 'spring-valley',
            'subdomain' => 'springvalley',
        ]);

        $adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@springvalley.edu.ng',
            'password' => Hash::make('password'),
        ]);

        UserProfile::create([
            'school_id' => $school->id,
            'user_id' => $adminUser->id,
            'role' => 'school_admin',
        ]);

        $token = $adminUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/invite', [
                'name' => 'Teacher Grace',
                'email' => 'grace@springvalley.edu.ng',
                'role' => 'teacher',
                'phone' => '+2348012345678',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'user_id', 'temp_password']);

        $this->assertDatabaseHas('users', ['email' => 'grace@springvalley.edu.ng']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.invited']);
    }
}
