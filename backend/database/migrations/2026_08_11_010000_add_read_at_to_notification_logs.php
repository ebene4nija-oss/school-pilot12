<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read state, so `notification_logs` can back an inbox (gap G6).
 *
 * The table was built as a delivery ledger — "did the school actually send
 * 4,000 texts last term", and a record for a parent disputing that they were
 * told about a fee deadline. Both of those are about the send, so nothing ever
 * recorded whether the recipient looked at it.
 *
 * An inbox needs that. Without it there is no unread count, which means no
 * badge, which means the screen is a list a parent has no reason to open.
 * Nullable and unset for every existing row: history predating this is neither
 * read nor unread, and defaulting it either way would either bury a real unread
 * message or show a parent 300 phantom ones on upgrade — so the inbox only
 * counts rows created from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('sent_at');

            // The inbox query is always "mine, newest first" and the badge is
            // always "mine, unread". Both ride this index.
            $table->index(['user_id', 'read_at', 'id'], 'notification_logs_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex('notification_logs_inbox_index');
            $table->dropColumn('read_at');
        });
    }
};
