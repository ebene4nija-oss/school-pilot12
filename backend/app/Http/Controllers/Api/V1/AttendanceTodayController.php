<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The school's attendance today, for an administrator's phone.
 *
 * Which registers are in and which are not, who is absent with a number to
 * ring, and which staff have clocked in. Every figure here was already
 * recorded; nothing answered "how is today going" without opening each
 * class's register in turn.
 */
class AttendanceTodayController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school to see its attendance.'], 409);
        }

        $request->validate(['date' => 'nullable|date']);
        $date = $request->filled('date') ? substr((string) $request->input('date'), 0, 10) : now()->toDateString();

        $classes = DB::table('classes')->where('school_id', $schoolId)
            ->orderBy('order_index')->orderBy('name')
            ->get(['id', 'name']);

        $roll = DB::table('students')->where('school_id', $schoolId)
            ->where('status', 'active')->whereNull('deleted_at')
            ->selectRaw('class_id, COUNT(*) as n')->groupBy('class_id')
            ->pluck('n', 'class_id');

        $marks = DB::table('attendance_records as r')
            ->join('students as s', 's.id', '=', 'r.student_id')
            ->where('r.school_id', $schoolId)
            ->whereDate('r.date', $date)
            ->whereNotNull('r.student_id')
            ->selectRaw('s.class_id, r.status, COUNT(*) as n')
            ->groupBy('s.class_id', 'r.status')
            ->get()
            ->groupBy('class_id');

        $classRows = $classes->map(function ($c) use ($roll, $marks) {
            $byStatus = ($marks->get($c->id) ?? collect())->pluck('n', 'status');

            return [
                'class_id' => $c->id,
                'class' => $c->name,
                'students' => (int) ($roll[$c->id] ?? 0),
                'present' => (int) ($byStatus['present'] ?? 0),
                'late' => (int) ($byStatus['late'] ?? 0),
                'absent' => (int) ($byStatus['absent'] ?? 0),
                'excused' => (int) ($byStatus['excused'] ?? 0),
                'marked' => $byStatus->sum() > 0,
            ];
        })->filter(fn ($c) => $c['students'] > 0)->values();

        // Absent today, with the first guardian's number to call.
        $absent = DB::table('attendance_records as r')
            ->join('students as s', 's.id', '=', 'r.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('classes as c', 'c.id', '=', 's.class_id')
            ->where('r.school_id', $schoolId)
            ->whereDate('r.date', $date)
            ->where('r.status', 'absent')
            ->orderBy('c.name')->orderBy('u.name')
            ->get(['s.id as student_id', 'u.name', 'c.name as class']);

        $phones = DB::table('student_guardian as sg')
            ->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
            ->join('user_profiles as p', 'p.user_id', '=', 'g.user_id')
            ->whereIn('sg.student_id', $absent->pluck('student_id'))
            ->whereNotNull('p.phone')
            ->orderBy('sg.guardian_id')
            ->get(['sg.student_id', 'p.phone'])
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->first()->phone);

        $absentRows = $absent->map(fn ($a) => [
            'student_id' => (int) $a->student_id,
            'name' => $a->name,
            'class' => $a->class,
            'guardian_phone' => $phones[$a->student_id] ?? null,
        ])->values();

        // Staff: who has clocked in today, and when.
        $staff = DB::table('staff as st')
            ->join('users as u', 'u.id', '=', 'st.user_id')
            ->where('st.school_id', $schoolId)
            ->whereNull('st.deleted_at')
            ->orderBy('u.name')
            ->get(['st.id', 'u.name']);

        $clockIns = DB::table('attendance_records')
            ->where('school_id', $schoolId)
            ->whereDate('date', $date)
            ->whereNotNull('staff_id')
            ->selectRaw('staff_id, MIN(created_at) as first_at')
            ->groupBy('staff_id')
            ->pluck('first_at', 'staff_id');

        $staffRows = $staff->map(fn ($s) => [
            'name' => $s->name,
            'clocked_in_at' => isset($clockIns[$s->id]) ? \Illuminate\Support\Carbon::parse($clockIns[$s->id])->toIso8601String() : null,
        ])->values();

        if ($absentRows->isNotEmpty()) {
            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $request->user()->id,
                'action' => 'attendance.today_viewed',
                'auditable_type' => \App\Models\School::class,
                'auditable_id' => $schoolId,
                // Counts only: copying the numbers into the audit trail would
                // just duplicate the personal data.
                'new_values' => [
                    'date' => $date,
                    'absent_listed' => $absentRows->count(),
                    'phones_disclosed' => $absentRows->whereNotNull('guardian_phone')->count(),
                ],
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json([
            'date' => $date,
            'summary' => [
                'students' => $classRows->sum('students'),
                'present' => $classRows->sum('present'),
                'late' => $classRows->sum('late'),
                'absent' => $classRows->sum('absent'),
                'classes' => $classRows->count(),
                'classes_marked' => $classRows->where('marked', true)->count(),
            ],
            'classes' => $classRows,
            'absent' => $absentRows,
            'staff' => [
                'total' => $staffRows->count(),
                'clocked_in' => $staffRows->whereNotNull('clocked_in_at')->count(),
                'list' => $staffRows,
            ],
        ]);
    }
}
