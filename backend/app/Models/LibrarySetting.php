<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A school's lending rules (doc §9.2). */
class LibrarySetting extends Model
{
    use BelongsToTenant;

    protected $table = 'library_settings';

    protected $fillable = ['school_id', 'student_loan_days', 'staff_loan_days', 'student_max_loans', 'staff_max_loans', 'max_renewals', 'hold_days', 'students_reserve', 'notify_parents'];

    protected $casts = [
        'student_loan_days' => 'integer',
        'staff_loan_days' => 'integer',
        'student_max_loans' => 'integer',
        'staff_max_loans' => 'integer',
        'max_renewals' => 'integer',
        'hold_days' => 'integer',
        'students_reserve' => 'boolean',
        'notify_parents' => 'boolean',
    ];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self([
                'school_id' => $schoolId, 'student_loan_days' => 14, 'staff_loan_days' => 30, 'student_max_loans' => 2,
                'staff_max_loans' => 5, 'max_renewals' => 1, 'hold_days' => 3, 'students_reserve' => true, 'notify_parents' => true,
            ]);
    }
}
