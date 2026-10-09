<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdmissionApplication;
use App\Models\Broadsheet;
use App\Models\CbtExam;
use App\Models\LeaveRequest;
use App\Models\LearningMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Everything waiting on a school administrator, in one read.
 *
 * Each kind already had its own list and its own decision endpoint, spread
 * over five pages of the web portal. On a phone that is five requests on paid
 * data before the inbox can say "3 things need you". This returns a compact
 * row per item and the counts; the decision still goes to the kind's own
 * endpoint (`type` says which), so every rule those endpoints enforce —
 * documents verified before an offer, a reason before sending a CBT paper
 * back, whose step a broadsheet is on — still applies.
 *
 * Read-only, and only what the existing lists already show an admin.
 */
class ApprovalsController extends Controller
{
    private const LIMIT = 50;

    public function index(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school to see its approvals.'], 409);
        }

        $leave = LeaveRequest::where('school_id', $schoolId)
            ->where('status', 'pending')
            ->with('staff.user:id,name')
            ->orderBy('start_date')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (LeaveRequest $l) => [
                'type' => 'leave',
                'id' => $l->id,
                'title' => trim(($l->staff?->user?->name ?? 'Staff').' — '.str_replace('_', ' ', (string) $l->leave_type).' leave'),
                'detail' => $l->start_date?->format('j M').' – '.$l->end_date?->format('j M')
                    .($l->days_requested ? " · {$l->days_requested} day(s)" : ''),
                'note' => $l->reason,
                'submitted_at' => $l->created_at?->toIso8601String(),
            ]);

        $notes = LearningMaterial::where('school_id', $schoolId)
            ->where('status', 'pending_approval')
            ->with(['uploader:id,name', 'schoolClass:id,name', 'subject:id,name'])
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (LearningMaterial $m) => [
                'type' => 'lesson_note',
                'id' => $m->id,
                'title' => $m->title,
                'detail' => collect([$m->subject?->name, $m->schoolClass?->name, $m->uploader ? 'by '.$m->uploader->name : null])->filter()->implode(' · '),
                'note' => $m->description,
                'submitted_at' => $m->created_at?->toIso8601String(),
            ]);

        $exams = CbtExam::where('school_id', $schoolId)
            ->where('approval_status', 'pending')
            ->with('subject:id,name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
        $setters = DB::table('users')->whereIn('id', $exams->pluck('created_by')->filter())->pluck('name', 'id');
        $exams = $exams->map(fn (CbtExam $e) => [
            'type' => 'cbt_exam',
            'id' => $e->id,
            'title' => $e->title,
            'detail' => collect([$e->subject?->name, $e->duration_minutes ? "{$e->duration_minutes} min" : null, isset($setters[$e->created_by]) ? 'by '.$setters[$e->created_by] : null])->filter()->implode(' · '),
            'note' => null,
            'submitted_at' => $e->updated_at?->toIso8601String(),
        ]);

        $admissions = AdmissionApplication::where('school_id', $schoolId)
            ->whereIn('status', ['submitted', 'under_review', 'assessment'])
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
        $classNames = DB::table('classes')->whereIn('id', $admissions->pluck('intended_class_id')->filter())->pluck('name', 'id');
        $admissions = $admissions->map(fn (AdmissionApplication $a) => [
            'type' => 'admission',
            'id' => $a->id,
            'title' => $a->applicant_name,
            'detail' => collect([
                isset($classNames[$a->intended_class_id]) ? 'for '.$classNames[$a->intended_class_id] : null,
                $a->reference,
                AdmissionApplication::STATUSES[$a->status] ?? $a->status,
            ])->filter()->implode(' · '),
            'note' => null,
            'submitted_at' => $a->created_at?->toIso8601String(),
        ]);

        $broadsheets = Broadsheet::where('school_id', $schoolId)
            ->where('status', 'in_review')
            ->with(['schoolClass:id,name', 'term:id,name'])
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Broadsheet $b) => [
                'type' => 'broadsheet',
                'id' => $b->id,
                'title' => collect([$b->schoolClass?->name, $b->term?->name])->filter()->implode(' · ') ?: 'Broadsheet',
                'detail' => 'Waiting at: '.($b->steps[$b->current_step]['label'] ?? 'review'),
                'note' => null,
                'submitted_at' => $b->updated_at?->toIso8601String(),
            ]);

        $groups = [
            'leave' => $leave,
            'lesson_note' => $notes,
            'cbt_exam' => $exams,
            'admission' => $admissions,
            'broadsheet' => $broadsheets,
        ];

        return response()->json([
            'counts' => collect($groups)->map->count(),
            'total' => collect($groups)->sum(fn ($g) => $g->count()),
            'items' => collect($groups)->flatten(1)->values(),
        ]);
    }
}
