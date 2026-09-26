<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A `nurse` role: a school nurse's own sign-in, limited to the clinic log.
 *
 * The clinic log was recorded by admins and teachers on the nurse's behalf,
 * with the nurse's name typed as free text. A nurse now signs in and records
 * visits herself, and can reach nothing else — not the roster, not results,
 * not fees.
 *
 * `role` is an enum. On Postgres Laravel enforces that with a CHECK
 * constraint, which a column change leaves in place, so the constraint is
 * replaced explicitly there. SQLite rebuilds the table on `change()`.
 */
return new class extends Migration
{
    private const ROLES = ['super_admin', 'school_admin', 'teacher', 'student', 'parent', 'nurse'];

    private const PREVIOUS = ['super_admin', 'school_admin', 'teacher', 'student', 'parent'];

    public function up(): void
    {
        $this->setRoles(self::ROLES);
    }

    public function down(): void
    {
        // A nurse cannot be left holding a role the column no longer allows.
        DB::table('user_profiles')->where('role', 'nurse')->update(['role' => 'teacher']);

        $this->setRoles(self::PREVIOUS);
    }

    private function setRoles(array $roles): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($r) => "'{$r}'", $roles));

            DB::statement('ALTER TABLE user_profiles DROP CONSTRAINT IF EXISTS user_profiles_role_check');
            DB::statement("ALTER TABLE user_profiles ADD CONSTRAINT user_profiles_role_check CHECK (role IN ({$list}))");

            return;
        }

        Schema::table('user_profiles', function (Blueprint $table) use ($roles) {
            $table->enum('role', $roles)->default('student')->change();
        });
    }
};
