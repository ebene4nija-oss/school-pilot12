<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\TutorSetting;
use App\Support\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The school's side of the AI tutor (TASK 15): where it is on, for which
 * subjects, how much, and how it is used. Usage is counts only; no
 * conversation content reaches this screen.
 */
class TutorAdminController extends Controller
{
    public function settings(): JsonResponse
    {
        return response()->json(TutorSetting::for($this->schoolId()));
    }

    public function update(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'sections' => 'present|nullable|array', 'sections.*' => 'in:nursery,primary,secondary',
            'subject_ids' => 'present|nullable|array', 'subject_ids.*' => ['integer', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'daily_message_limit' => 'nullable|integer|min:1|max:500',
            'exam_lock' => 'boolean',
        ]);
        $s = TutorSetting::updateOrCreate(['school_id' => $schoolId], $data);
        AuditLog::create(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'action' => 'tutor.settings_updated', 'auditable_type' => TutorSetting::class, 'auditable_id' => $s->id, 'new_values' => $data]);

        return response()->json($s->fresh());
    }

    /** Messages by section, class and subject; active students; off-topic redirects. */
    public function usage(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $days = min(max((int) $request->input('days', 30), 1), 365);
        $from = now()->subDays($days)->startOfDay();

        $rows = DB::table('tutor_messages as m')->join('tutor_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('students as s', 's.id', '=', 'c.student_id')->leftJoin('subjects as sub', 'sub.id', '=', 'c.subject_id')
            ->where('c.school_id', $schoolId)->where('m.role', 'student')->where('m.created_at', '>=', $from)
            ->get(['c.student_id', 's.class_id', 'sub.name as subject', 'm.was_redirected', 'm.created_at']);

        $levels = SchoolClass::where('school_id', $schoolId)->pluck('level_category', 'id');
        $names = SchoolClass::where('school_id', $schoolId)->pluck('name', 'id');

        return response()->json([
            'days' => $days,
            'messages' => $rows->count(),
            'active_students' => $rows->pluck('student_id')->unique()->count(),
            'redirected' => $rows->where('was_redirected', true)->count(),
            'by_section' => $rows->countBy(fn ($r) => Sections::sectionOf(Sections::levelGroupOf($levels[$r->class_id] ?? null))),
            'by_class' => $rows->countBy(fn ($r) => $names[$r->class_id] ?? 'Unknown')->sortDesc(),
            'by_subject' => $rows->countBy(fn ($r) => $r->subject ?? 'General')->sortDesc(),
            'by_day' => $rows->countBy(fn ($r) => substr((string) $r->created_at, 0, 10))->sortKeys(),
        ]);
    }
}
