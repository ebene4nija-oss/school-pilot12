<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\School;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /me/notifications` — gap G6.
 *
 * `notification_logs` was built as a delivery ledger: "did the school actually
 * send 4,000 texts last term", and a record for a parent disputing that they
 * were told about a fee deadline. Both are about the *send*, and the one
 * endpoint over it was admin-only — so a parent could receive a push, tap it
 * away, and never find out what it said.
 */
class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $parent;
    private User $otherParent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland',
        ]);

        $this->parent = $this->user('Mrs Okeke', 'okeke@graceland.test', 'parent');
        $this->otherParent = $this->user('Mr Bello', 'bello@graceland.test', 'parent');
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        UserProfile::create(['user_id' => $user->id, 'school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function log(User $to, string $body, string $status = 'sent', ?string $category = 'fee_reminder'): NotificationLog
    {
        return NotificationLog::create([
            'school_id' => $this->school->id,
            'user_id' => $to->id,
            'channel' => 'push',
            'category' => $category,
            'recipient' => '+2348030000000',
            'body' => $body,
            'status' => $status,
            'sent_at' => now(),
        ]);
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    public function test_a_parent_reads_back_what_they_were_sent()
    {
        $this->log($this->parent, 'Second term fees are due on 30 September.');

        $this->as($this->parent)
            ->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Second term fees are due on 30 September.')
            ->assertJsonPath('data.0.category', 'fee_reminder')
            ->assertJsonPath('data.0.read', false)
            ->assertJsonPath('unread_count', 1);
    }

    public function test_the_inbox_is_one_persons_and_not_the_schools()
    {
        $this->log($this->parent, 'Yours.');
        $this->log($this->otherParent, 'Another family.');

        $this->as($this->parent)
            ->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Yours.');
    }

    /**
     * Scoped by user, not by school — otherwise the inbox quietly becomes a
     * second copy of the admin-only delivery ledger.
     */
    public function test_an_admin_gets_their_own_messages_like_anyone_else()
    {
        $admin = $this->user('Principal', 'principal@graceland.test', 'school_admin');

        $this->log($this->parent, 'A parent message.');
        $this->log($admin, 'An admin message.');

        $this->as($admin)
            ->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'An admin message.');
    }

    /**
     * A push that never left the server is an operational fact for the ledger.
     * Showing it in a list of messages the phone *did* receive invents a
     * notification the recipient never got.
     */
    public function test_a_failed_send_is_not_an_inbox_item()
    {
        $this->log($this->parent, 'Delivered.', 'sent');
        $this->log($this->parent, 'Never left the building.', 'failed');
        $this->log($this->parent, 'Still queued.', 'queued');

        $this->as($this->parent)
            ->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Delivered.')
            ->assertJsonPath('unread_count', 1);
    }

    /** NDPA §12: the caller knows their own number; no need to ship it back. */
    public function test_the_recipient_address_is_not_returned()
    {
        $this->log($this->parent, 'Anything.');

        $body = $this->as($this->parent)->getJson('/api/v1/me/notifications')->getContent();

        $this->assertStringNotContainsString('+2348030000000', $body);
        $this->assertStringNotContainsString('recipient', $body);
    }

    public function test_marking_everything_read_clears_the_badge()
    {
        $this->log($this->parent, 'One.');
        $this->log($this->parent, 'Two.');

        $this->as($this->parent)
            ->postJson('/api/v1/me/notifications/read')
            ->assertOk()
            ->assertJsonPath('marked_read', 2)
            ->assertJsonPath('unread_count', 0);

        $this->as($this->parent)
            ->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.read', true)
            ->assertJsonPath('unread_count', 0);
    }

    public function test_a_single_message_can_be_marked_read()
    {
        $one = $this->log($this->parent, 'One.');
        $this->log($this->parent, 'Two.');

        $this->as($this->parent)
            ->postJson('/api/v1/me/notifications/read', ['ids' => [$one->id]])
            ->assertOk()
            ->assertJsonPath('marked_read', 1)
            ->assertJsonPath('unread_count', 1);
    }

    /**
     * The id is filtered in the same statement that writes, so a borrowed id
     * updates no rows rather than being checked and then trusted.
     */
    public function test_marking_someone_elses_message_read_does_nothing()
    {
        $theirs = $this->log($this->otherParent, 'Another family.');

        $this->as($this->parent)
            ->postJson('/api/v1/me/notifications/read', ['ids' => [$theirs->id]])
            ->assertOk()
            ->assertJsonPath('marked_read', 0);

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_unread_only_narrows_the_list()
    {
        $read = $this->log($this->parent, 'Already seen.');
        $read->update(['read_at' => now()]);
        $this->log($this->parent, 'New.');

        $this->as($this->parent)
            ->getJson('/api/v1/me/notifications?unread_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'New.');
    }

    /** A parent of a nine-year pupil has years of these. */
    public function test_the_inbox_is_paginated_and_the_badge_counts_past_the_page()
    {
        foreach (range(1, 30) as $i) {
            $this->log($this->parent, "Message {$i}.");
        }

        $this->as($this->parent)
            ->getJson('/api/v1/me/notifications?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 30)
            // The badge is the whole inbox, not this page.
            ->assertJsonPath('unread_count', 30);
    }

    public function test_the_inbox_requires_authentication()
    {
        $this->getJson('/api/v1/me/notifications')->assertStatus(401);
        $this->postJson('/api/v1/me/notifications/read')->assertStatus(401);
    }
}
