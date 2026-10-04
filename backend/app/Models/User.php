<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Always eager-load the tenant profile — it is accessed on every
     * authenticated request by BelongsToTenant scope, RequireRole
     * middleware, and controller getSchoolId() helpers.
     */
    protected $with = ['userProfile'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
        'password_changed_at',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    public function userProfile()
    {
        return $this->hasOne(UserProfile::class);
    }

    /** A student's name change is part of their record's history. */
    protected static function booted(): void
    {
        static::updated(function (User $user) {
            if (! $user->wasChanged('name')) {
                return;
            }

            $student = Student::withoutGlobalScopes()->where('user_id', $user->id)->first(['id', 'school_id']);

            if (! $student) {
                return;
            }

            try {
                StudentRecordChange::withoutGlobalScopes()->create([
                    'school_id' => $student->school_id,
                    'student_id' => $student->id,
                    'field' => 'name',
                    'old_value' => $user->getOriginal('name'),
                    'new_value' => $user->name,
                    'reason' => StudentRecordChange::currentReason(),
                    'changed_by' => auth()->id(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    public function customRoles()
    {
        return $this->belongsToMany(CustomRole::class, 'user_roles', 'user_id', 'custom_role_id')
                    ->withPivot('assigned_by', 'assigned_at')
                    ->withTimestamps();
    }

    public function loginHistories()
    {
        return $this->hasMany(LoginHistory::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(UserActivityLog::class);
    }

    public function teacherProfile()
    {
        return $this->hasOne(TeacherProfile::class);
    }

    public function parentProfile()
    {
        return $this->hasOne(ParentProfile::class);
    }

    /**
     * Check if the account is currently locked.
     */
    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }
}

