<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Timetable\CoverService;
use App\Support\InAppNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Substitution for absent teachers (TASK 07). */
class CoverController extends Controller
{
    public function __construct(private CoverService $cover)
    {
    }

    public function sheet(Request $request): JsonResponse
    {
        $date = Carbon::parse($request->input('date', now()->toDateString()));

        return response()->json($this->cover->coverSheet($this->schoolId(), $date) + [
            'recorded_absences' => DB::table('teacher_absences as a')->join('users as u', 'u.id', '=', 'a.teacher_id')
                ->where('a.school_id', $this->schoolId())->whereDate('a.date', $date)->get(['a.id', 'a.teacher_id', 'u.name', 'a.reason']),
        ]);
    }

    public function recordAbsence(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'teacher_id' => ['required', Rule::exists('user_profiles', 'user_id')->where('school_id', $schoolId)->where('role', 'teacher')],
            'date' => 'required|date|after_or_equal:today',
            'reason' => 'nullable|in:sick,training,official_duty,other',
        ]);
        $id = DB::table('teacher_absences')->updateOrInsert(['teacher_id' => $data['teacher_id'], 'date' => $data['date']],
            ['school_id' => $schoolId, 'reason' => $data['reason'] ?? 'other', 'recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Absence recorded. Their lessons now appear on the cover sheet.'], 201);
    }

    public function removeAbsence(int $id): JsonResponse
    {
        DB::table('teacher_absences')->where('school_id', $this->schoolId())->where('id', $id)->delete();

        return response()->json(['message' => 'Absence removed.']);
    }

    public function assign(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'entry_id' => 'required|integer',
            'substitute_teacher_id' => 'required|integer',
            'note' => 'nullable|string|max:255',
        ]);
        $date = Carbon::parse($data['date']);

        try {
            $id = $this->cover->assign($schoolId, $date, $data['entry_id'], $data['substitute_teacher_id'], $request->user()->id, $data['note'] ?? null);
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $lesson = $this->cover->lessonsOn($schoolId, $date)->firstWhere('id', $data['entry_id']);
        InAppNotifier::send($schoolId, [$data['substitute_teacher_id']], 'timetable', 'Cover lesson',
            "Please cover {$lesson->class} {$lesson->subject_name} ({$lesson->slot_name}".($lesson->start_time ? ', '.substr($lesson->start_time, 0, 5) : '').') on '.$date->format('D j M').'.', true);
        AuditLog::create(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'action' => 'timetable.cover_arranged', 'auditable_type' => 'timetable_substitution', 'auditable_id' => $id, 'new_values' => $data]);

        return response()->json(['id' => $id, 'message' => 'Cover arranged. The teacher has been told.'], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $row = DB::table('timetable_substitutions')->where('school_id', $this->schoolId())->where('id', $id)->where('status', 'covered')->first();
        abort_unless($row, 404);
        DB::table('timetable_substitutions')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        InAppNotifier::send($this->schoolId(), [(int) $row->substitute_teacher_id], 'timetable', 'Cover cancelled', 'A cover lesson on '.Carbon::parse($row->date)->format('D j M').' is no longer needed.');

        return response()->json(['message' => 'Cover cancelled.']);
    }

    /** A teacher's own cover duties from today. */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('timetable_substitutions as s')->join('timetable_entries as e', 'e.id', '=', 's.entry_id')
            ->leftJoin('classes as c', 'c.id', '=', 'e.school_class_id')->leftJoin('users as u', 'u.id', '=', 's.absent_teacher_id')
            ->where('s.school_id', $this->schoolId())->where('s.substitute_teacher_id', $request->user()->id)->where('s.status', 'covered')
            ->whereDate('s.date', '>=', now()->toDateString())->orderBy('s.date')->orderBy('e.start_time')
            ->get(['s.id', 's.date', 'c.name as class', 'e.subject_name', 'e.slot_name', 'e.start_time', 'e.end_time', 'u.name as covering_for', 's.note'])]);
    }
}
