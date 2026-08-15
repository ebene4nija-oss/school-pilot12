<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A school admin's own copy of their data.
     *
     * Not a backup — nightly Postgres snapshots are the disaster-recovery
     * mechanism and remain the platform's job (doc §7.15). This is
     * portability: the file a school can hold, take to another system, or
     * produce for an NDPA request. The old endpoint tried to do it inline in
     * a JSON response, which neither survives a real school's row counts nor
     * gives them anything openable.
     */
    public function up(): void
    {
        Schema::create('school_data_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            $table->string('status')->default('pending'); // pending|processing|complete|failed
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('row_counts')->nullable();

            /*
             * Health data is excluded unless asked for, explicitly and per
             * export. The four encrypted columns on `students` are the ones
             * `$hidden` exists to keep out of ordinary responses; an archive
             * that quietly included them would undo that in one download.
             */
            $table->boolean('includes_medical')->default(false);

            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Archives are deleted after this; a copy of a whole school's
            // records should not sit on disk indefinitely.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_data_exports');
    }
};
