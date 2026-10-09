<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School store / inventory: what a school sells or hands to students
 * (uniforms, books, consumables), issued to a student and confirmed received
 * by a parent. Optional: off until the school switches it on.
 *
 * No new personal data: a line points at an existing student, and the only
 * free text is the parent's note on a missing item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            // The school's own choice. Off until it opts in.
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // uniform | book | consumable | other
            $table->string('category', 12)->default('other');
            // Size or edition, e.g. "Age 8-9" or "JSS 1". Same item, different stock.
            $table->string('variant', 60)->nullable();
            $table->decimal('unit_price', 12, 2)->default(0);
            // The school decides: added to the term invoice, or handed out free / already paid.
            $table->boolean('charge_to_fees')->default(true);
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('reorder_level')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'name', 'variant']);
        });

        // Every change to stock, so the balance can always be explained.
        Schema::create('inventory_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->integer('change');
            // received | issued | adjusted | returned
            $table->string('reason', 10);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('issue_id')->nullable();
            $table->integer('balance_after');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'item_id']);
        });

        // A set handed out together, e.g. "JSS 1 new student pack".
        Schema::create('inventory_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_id')->constrained('inventory_packs')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);

            $table->unique(['pack_id', 'item_id']);
        });

        // One hand-over to a student.
        Schema::create('inventory_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pack_id')->nullable()->constrained('inventory_packs')->nullOnDelete();
            // awaiting | confirmed | disputed | resolved
            $table->string('status', 10)->default('awaiting');
            $table->string('parent_note', 500)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'student_id']);
        });

        Schema::create('inventory_issue_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained('inventory_issues')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            // Copied at issue, so a later rename or price change doesn't rewrite history.
            $table->string('item_name', 120);
            $table->string('variant', 60)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->boolean('charged');
            // pending | received | missing | cancelled
            $table->string('status', 10)->default('pending');
            $table->timestamps();
        });

        // What reaches the term invoice. pending until the invoice exists.
        Schema::create('inventory_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('issue_line_id')->unique()->constrained('inventory_issue_lines')->cascadeOnDelete();
            $table->string('title');
            $table->decimal('amount', 12, 2);
            // pending | posted | void
            $table->string('status', 8)->default('pending');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'term_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['inventory_charges', 'inventory_issue_lines', 'inventory_issues', 'inventory_pack_items', 'inventory_packs', 'inventory_stock_movements', 'inventory_items', 'inventory_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
