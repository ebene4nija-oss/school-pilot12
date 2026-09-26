<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A `bursar` role: the accounts office's own sign-in, limited to the fee book.
 *
 * Fees were worked by school admins, so whoever took cash at the accounts
 * office also held every result and every health record in the school. A
 * bursar reaches fees, payments, invoices, fee reports, school accounts and
 * result-PIN sales — not results, not the clinic log, not student records
 * beyond what a fee statement shows, and not the school's payment-gateway keys
 * or payroll.
 *
 * Same constraint handling as the nurse role (2026_09_26_060000).
 */
return new class extends Migration
{
    private const ROLES = ['super_admin', 'school_admin', 'teacher', 'student', 'parent', 'nurse', 'bursar'];

    private const PREVIOUS = ['super_admin', 'school_admin', 'teacher', 'student', 'parent', 'nurse'];

    public function up(): void
    {
        $this->setRoles(self::ROLES);
    }

    public function down(): void
    {
        // A bursar cannot be left holding a role the column no longer allows;
        // the narrowest existing staff role is the safe place to put them.
        DB::table('user_profiles')->where('role', 'bursar')->update(['role' => 'nurse']);

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
