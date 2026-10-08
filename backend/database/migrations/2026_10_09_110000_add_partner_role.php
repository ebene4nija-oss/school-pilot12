<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A `partner` role: the sign-in for people in the partner programme.
 *
 * A partner belongs to no school (school_id is null) and reaches only the
 * partner portal's own routes; BindTenantFromUser refuses everything else.
 *
 * Same constraint handling as the bursar role (2026_09_27_020000).
 */
return new class extends Migration
{
    private const ROLES = ['super_admin', 'school_admin', 'teacher', 'student', 'parent', 'nurse', 'bursar', 'partner'];

    private const PREVIOUS = ['super_admin', 'school_admin', 'teacher', 'student', 'parent', 'nurse', 'bursar'];

    public function up(): void
    {
        $this->setRoles(self::ROLES);
    }

    public function down(): void
    {
        // A partner cannot be left holding a role the column no longer allows;
        // their login is removed, the partner record stays.
        $userIds = DB::table('user_profiles')->where('role', 'partner')->pluck('user_id');
        DB::table('partners')->whereIn('user_id', $userIds)->update(['user_id' => null]);
        DB::table('user_profiles')->where('role', 'partner')->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();

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
