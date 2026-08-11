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

    // ==================================================================
    // Gap G10 — neither list is unbounded any more
    // ==================================================================

    /**
     * A form teacher keeps one thread per family for years. "Every conversation
     * I have ever had" is not a payload to hand a handset on every open of the
     * Messages tab.
     */
    public function test_the_thread_list_is_paginated()
    {
        foreach (range(1, 5) as $i) {
            $parent = $this->user("Parent {$i}", "parent{$i}@chat.test", 'parent');

            $this->actingAs($parent, 'sanctum')->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->teacher->id,
                'body' => "Message from family {$i}.",
            ])->assertStatus(201);
        }

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url('/messages/threads?per_page=2'))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    /**
     * The badge counts every conversation, not the page.
     *
     * A teacher with forty threads and unread messages in the fortieth would
     * otherwise see a badge that says zero.
     */
    public function test_the_unread_badge_counts_past_the_first_page()
    {
        foreach (range(1, 5) as $i) {
            $parent = $this->user("Parent {$i}", "parent{$i}@chat.test", 'parent');

            $this->actingAs($parent, 'sanctum')->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->teacher->id,
                'body' => "Message from family {$i}.",
            ])->assertStatus(201);
        }

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url('/messages/threads?per_page=1'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total_unread', 5);
    }

    /**
     * A thread used to load every message ever exchanged about a child, on
     * every open, to render the last dozen.
     */
    public function test_a_thread_returns_the_latest_page_of_messages_not_all_of_them()
    {
        $this->actingAs($this->parent, 'sanctum')->postJson($this->url('/messages/send'), [
            'recipient_id' => $this->teacher->id,
            'body' => 'Message 1',
        ])->assertStatus(201);

        $thread = MessageThread::first();

        foreach (range(2, 10) as $i) {
            $this->actingAs($this->parent, 'sanctum')->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->teacher->id,
                'thread_id' => $thread->id,
                'body' => "Message {$i}",
            ])->assertStatus(201);
        }

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url("/messages/threads/{$thread->id}?per_page=4"))
            ->assertStatus(200);

        $bodies = collect($response->json('messages'))->pluck('body')->all();

        // The newest four, oldest-first — the order a chat is drawn in.
        $this->assertSame(['Message 7', 'Message 8', 'Message 9', 'Message 10'], $bodies);
        $this->assertTrue($response->json('messages_meta.has_more'));
    }

    /** Scrolling up. `before_id` walks backwards through the conversation. */
    public function test_older_messages_are_reachable_by_paging_backwards()
    {
        $this->actingAs($this->parent, 'sanctum')->postJson($this->url('/messages/send'), [
            'recipient_id' => $this->teacher->id,
            'body' => 'Message 1',
        ])->assertStatus(201);

        $thread = MessageThread::first();

        foreach (range(2, 6) as $i) {
            $this->actingAs($this->parent, 'sanctum')->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->teacher->id,
                'thread_id' => $thread->id,
                'body' => "Message {$i}",
            ])->assertStatus(201);
        }

        $first = $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url("/messages/threads/{$thread->id}?per_page=3"))
            ->assertStatus(200);

        $before = $first->json('messages_meta.next_before_id');
        $this->assertNotNull($before);

        $older = $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url("/messages/threads/{$thread->id}?per_page=3&before_id={$before}"))
            ->assertStatus(200);

        $this->assertSame(
            ['Message 1', 'Message 2', 'Message 3'],
            collect($older->json('messages'))->pluck('body')->all()
        );
        $this->assertFalse($older->json('messages_meta.has_more'));
    }

    /**
     * The messages moved to the top level of the response.
     *
     * They used to hang off `thread`, where the mobile client's
     * `listOf(data, ['messages'])` could not see them — the thread view was
     * rendering "No messages yet" over a full conversation.
     */
    public function test_messages_are_at_the_top_level_where_clients_look_for_them()
    {
        $this->actingAs($this->parent, 'sanctum')->postJson($this->url('/messages/send'), [
            'recipient_id' => $this->teacher->id,
            'body' => 'Good afternoon.',
        ])->assertStatus(201);

        $thread = MessageThread::first();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url("/messages/threads/{$thread->id}"))
            ->assertStatus(200);

        $this->assertSame('Good afternoon.', $response->json('messages.0.body'));
        $this->assertSame('Parent One', $response->json('messages.0.sender.name'));
        $this->assertNull($response->json('thread.messages'));
    }
}
