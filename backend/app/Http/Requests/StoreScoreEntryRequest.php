<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScoreEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->userProfile && in_array($this->user()->userProfile->role, ['super_admin', 'school_admin', 'teacher']);
    }

    public function rules(): array
    {
        $schoolId = $this->user()->userProfile ? $this->user()->userProfile->school_id : null;

        return [
            'term_id'    => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'first_ca'   => 'nullable|numeric|min:0|max:20',
            'second_ca'  => 'nullable|numeric|min:0|max:20',
            'exam'       => 'nullable|numeric|min:0|max:60',
        ];
    }
}
