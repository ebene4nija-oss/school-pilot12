<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications (§7.13).
 *
 * The spec asks for push, SMS and a WhatsApp funnel. What existed: a
 * NotificationController with no routes at all — the only controller in the
 * codebase that was entirely unreachable — a WhatsAppService called from
 * inside two other controllers, an SmsService never called from anywhere, and
 * no push integration of any kind.
 *
 * `device_tokens` is what push needs and never had: somewhere to put the token
 * the mobile app registers on install.
 *
 * `notification_logs` matters more than it looks. SMS costs the school money
 * per message, so "did the school actually send 4,000 texts last term" has to
 * be answerable, and a parent disputing that they were told about a fee
 * deadline needs a record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('device_tokens')) {
            Schema::create('device_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('token', 512);
                $table->string('platform', 16); // android | ios | web
                $table->string('device_name')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                // A reinstall issues a new token; the same token must never be
                // registered twice or every push is sent twice.
                $table->unique(['user_id', 'token'], 'device_token_unique');
                $table->index(['school_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('notification_logs')) {
            Schema::create('notification_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('channel', 16); // push | sms | whatsapp
                $table->string('category', 64)->nullable(); // fee_reminder | result_published | ...
                $table->string('recipient')->nullable(); // phone number or token tail
                $table->text('body');
                $table->string('status', 16)->default('queued'); // queued | sent | failed
                $table->text('failure_reason')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'channel', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('device_tokens');
    }
};
