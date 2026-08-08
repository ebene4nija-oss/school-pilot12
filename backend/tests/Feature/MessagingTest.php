<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageThread;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teacher↔parent messaging.
 *
 * Two defects this covers: any user in the school could post into any thread,
 * and thread roles were assigned by who typed first rather than by role.
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Chat Academy',
            'slug' => 'chat',
            'subdomain' => 'chat',
            'domain' => 'chat.schoolpilot.test',
        ]);

        $this->teacher = $this->user('Teacher One', 'teacher@chat.test', 'teacher');
        $this->parent = $this->user('Parent One', 'parent@chat.test', 'parent');
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function url(string $path): string
    {
        return 'http://chat.schoolpilot.test/api/v1' . $path;
    }

    /**
     * When a parent starts the conversation they must still be stored on the
     * parent side. The old code hardcoded sender => teacher_id, inverting the
     * roles and making both columns meaningless.
     */
    public function test_parent_initiated_thread_stores_roles_the_right_way_round()
    {
        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->teacher->id,
                'body' => 'Good morning sir, about my son.',
            ])
            ->assertStatus(201);

        $thread = MessageThread::first();

        $this->assertSame($this->teacher->id, $thread->teacher_id);
        $this->assertSame($this->parent->id, $thread->parent_id);
    }

    public function test_teacher_initiated_thread_stores_roles_the_right_way_round()
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->parent->id,
                'body' => 'Please see me about homework.',
            ])
            ->assertStatus(201);

        $thread = MessageThread::first();

        $this->assertSame($this->teacher->id, $thread->teacher_id);
        $this->assertSame($this->parent->id, $thread->parent_id);
    }

    /** The core hole: an unrelated user posting into someone else's thread. */
    public function test_outsider_cannot_post_into_someone_elses_thread()
    {
        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->teacher->id,
                'body' => 'Private matter about my child.',
            ]);

        $thread = MessageThread::first();
        $outsider = $this->user('Nosy Parent', 'nosy@chat.test', 'parent');

        $this->actingAs($outsider, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->teacher->id,
                'thread_id' => $thread->id,
                'body' => 'Injecting myself into this conversation.',
            ])
            ->assertStatus(403);

        $this->assertSame(1, Message::where('thread_id', $thread->id)->count());
    }

    public function test_outsider_cannot_read_someone_elses_thread()
    {
        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->teacher->id,
                'body' => 'Private matter.',
            ]);

        $thread = MessageThread::first();
        $outsider = $this->user('Nosy Two', 'nosy2@chat.test', 'parent');

        $this->actingAs($outsider, 'sanctum')
            ->getJson($this->url("/messages/threads/{$thread->id}"))
            ->assertStatus(403);
    }

    /** A conversation must have a staff side and a parent side. */
    public function test_parent_cannot_open_a_thread_with_another_parent()
    {
        $otherParent = $this->user('Parent Two', 'parent2@chat.test', 'parent');

        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'recipient_id' => $otherParent->id,
                'body' => 'Hello fellow parent.',
            ])
            ->assertStatus(422);
    }

    public function test_unread_counts_and_marking_as_read()
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->parent->id,
                'body' => 'Message one.',
            ]);

        $thread = MessageThread::first();

        $this->actingAs($this->parent, 'sanctum')
            ->getJson($this->url('/messages/threads'))
            ->assertStatus(200)
            ->assertJsonPath('total_unread', 1)
            ->assertJsonPath('data.0.unread_count', 1);

        // Opening the thread marks the other side's messages seen.
        $this->actingAs($this->parent, 'sanctum')
            ->getJson($this->url("/messages/threads/{$thread->id}"))
            ->assertStatus(200);

        $this->actingAs($this->parent, 'sanctum')
            ->getJson($this->url('/messages/threads'))
            ->assertJsonPath('total_unread', 0);

        // The sender's own message never counts as unread for the sender.
        $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url('/messages/threads'))
            ->assertJsonPath('total_unread', 0);
    }
}
