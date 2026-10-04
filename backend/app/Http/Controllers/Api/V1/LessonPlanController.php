<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Homework;
use App\Models\LearningMaterial;
use App\Models\LessonPlan;
use App\Models\LiveClass;
use App\Models\SchoolClass;
use App\Services\Lms\LmsService;
use App\Services\Sections\HeadResolver;
use App\Support\InAppNotifier;
use App\Support\Sections;
use App\Support\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lesson plans (TASK 14 §5). One plan serves a classroom lesson or a virtual
 * class, so online and physical teaching share the same academic record. A
 * submitted plan is reviewed by the subject's HOD or the section head.
 */
class LessonPlanController extends Controller
{
    private const FIELDS = ['week', 'topic', 'subtopic', 'objectives', 'resources', 'activities', 'assessment', 'outcomes', 'duration_minutes'];

    public function __construct(private LmsService $lms, private HeadResolver $heads)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = LessonPlan::where('school_id', $this->schoolId())
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('teacher:id,name')->orderByDesc('id')->limit(300)->get();

        // A teacher sees their own plans and the ones they review.
        if (! $this->lms->isAdmin($user)) {
            $rows = $rows->filter(fn ($p) => (int) $p->teacher_id === $user->id || ($p->status !== 'draft' && $this->canReview($user, $p)))->values();
        }

        return response()->json(['data' => $rows->map(fn ($p) => $p->only(['id', 'class_id', 'subject_id', 'term_id', 'week', 'topic', 'subtopic', 'duration_minutes', 'status', 'review_note']) + [
            'teacher' => $p->teacher?->name, 'mine' => (int) $p->teacher_id === $user->id,
        ])]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $p = LessonPlan::where('school_id', $this->schoolId())->with('materials:learning_materials.id,title,type', 'teacher:id,name')->findOrFail($id);
        abort_unless((int) $p->teacher_id === $request->user()->id || $this->lms->isAdmin($request->user()) || $this->canReview($request->user(), $p), 403);

        return response()->json([
            'plan' => $p,
            'live_classes' => LiveClass::where('lesson_plan_id', $p->id)->get(['id', 'title', 'scheduled_for', 'status']),
            'homework' => Homework::where('lesson_plan_id', $p->id)->get(['id', 'title', 'due_date', 'status']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $this->validatePlan($request, true);
        abort_unless($this->lms->teachesClass($request->user(), (int) $data['class_id']), 403, 'You can only plan for classes you teach.');

        $plan = LessonPlan::create(array_intersect_key($data, array_flip([...self::FIELDS, 'class_id', 'subject_id', 'term_id'])) + [
            'school_id' => $schoolId, 'teacher_id' => $request->user()->id, 'session_id' => TeacherScope::currentSessionId($schoolId), 'status' => 'draft',
        ]);
        $this->syncMaterials($request, $plan, $data['material_ids'] ?? null);

        return response()->json(['plan' => $plan->fresh('materials')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plan = LessonPlan::where('school_id', $this->schoolId())->findOrFail($id);
        abort_unless((int) $plan->teacher_id === $request->user()->id, 403, 'Only the teacher who wrote this plan edits it.');
        abort_if($plan->status === 'approved', 409, 'An approved plan is part of the record. Copy it to make changes.');

        $data = $this->validatePlan($request, false);
        $plan->update(array_intersect_key($data, array_flip([...self::FIELDS, 'term_id'])) + ($plan->status === 'returned' ? ['status' => 'draft'] : []));
        $this->syncMaterials($request, $plan, $data['material_ids'] ?? null);

        return response()->json(['plan' => $plan->fresh('materials')]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $plan = LessonPlan::where('school_id', $this->schoolId())->whereIn('status', ['draft', 'returned'])->findOrFail($id);
        abort_unless((int) $plan->teacher_id === $request->user()->id, 403);
        $plan->update(['status' => 'submitted']);

        $level = Sections::levelGroupOf(SchoolClass::whereKey($plan->class_id)->value('level_category'));
        $reviewers = array_merge(
            $this->heads->holders($plan->school_id, 'hod', $plan->class_id, $level, $plan->subject_id),
            $this->heads->holders($plan->school_id, 'level_head', $plan->class_id, $level)
        );
        InAppNotifier::send($plan->school_id, $reviewers, 'lms', 'Lesson plan to review', "{$request->user()->name} submitted \"{$plan->topic}\".");

        return response()->json(['plan' => $plan->fresh()]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $plan = LessonPlan::where('school_id', $this->schoolId())->where('status', 'submitted')->findOrFail($id);
        abort_unless($this->canReview($request->user(), $plan), 403, 'Only the head of department, the section head or an admin reviews lesson plans.');
        $data = $request->validate(['approve' => 'required|boolean', 'note' => 'nullable|required_if:approve,false|string|max:500']);

        $plan->update(['status' => $data['approve'] ? 'approved' : 'returned', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null]);
        InAppNotifier::send($plan->school_id, [(int) $plan->teacher_id], 'lms', $data['approve'] ? 'Lesson plan approved' : 'Lesson plan returned', "\"{$plan->topic}\"".($data['approve'] ? ' was approved.' : ": {$data['note']}"));

        return response()->json(['plan' => $plan->fresh()]);
    }

    /** Next term's (or another class's) starting point: a fresh draft. */
    public function copy(Request $request, int $id): JsonResponse
    {
        $source = LessonPlan::where('school_id', $this->schoolId())->findOrFail($id);
        abort_unless((int) $source->teacher_id === $request->user()->id || $this->lms->isAdmin($request->user()), 403);
        $data = $request->validate([
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $this->schoolId())],
            'term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $this->schoolId())],
        ]);
        $classId = (int) ($data['class_id'] ?? $source->class_id);
        abort_unless($this->lms->teachesClass($request->user(), $classId), 403, 'You can only plan for classes you teach.');

        $copy = $source->replicate(['status', 'reviewed_by', 'reviewed_at', 'review_note']);
        $copy->fill(['class_id' => $classId, 'term_id' => $data['term_id'] ?? $source->term_id, 'teacher_id' => $request->user()->id, 'status' => 'draft', 'session_id' => TeacherScope::currentSessionId($this->schoolId())])->save();
        $copy->materials()->sync($source->materials()->pluck('learning_materials.id'));

        return response()->json(['plan' => $copy->fresh('materials')], 201);
    }

    private function canReview($user, LessonPlan $plan): bool
    {
        if ($this->lms->isAdmin($user)) {
            return true;
        }
        $level = Sections::levelGroupOf(SchoolClass::whereKey($plan->class_id)->value('level_category'));

        return in_array($user->id, $this->heads->holders($plan->school_id, 'hod', $plan->class_id, $level, $plan->subject_id), true)
            || in_array($user->id, $this->heads->holders($plan->school_id, 'level_head', $plan->class_id, $level), true);
    }

    private function validatePlan(Request $request, bool $creating): array
    {
        $schoolId = $this->schoolId();
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'class_id' => [$creating ? 'required' : 'prohibited', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'subject_id' => [$creating ? 'required' : 'prohibited', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'week' => 'nullable|integer|min:1|max:20',
            'topic' => "{$req}|string|max:255",
            'subtopic' => 'nullable|string|max:255',
            'objectives' => 'nullable|string|max:5000',
            'resources' => 'nullable|string|max:5000',
            'activities' => 'nullable|string|max:8000',
            'assessment' => 'nullable|string|max:5000',
            'outcomes' => 'nullable|string|max:5000',
            'duration_minutes' => 'nullable|integer|min:5|max:600',
            'material_ids' => 'nullable|array|max:30',
            'material_ids.*' => ['integer', Rule::exists('learning_materials', 'id')->where('school_id', $schoolId)],
        ]);
    }

    private function syncMaterials(Request $request, LessonPlan $plan, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }
        $allowed = LearningMaterial::whereIn('id', $ids)->get()->filter(fn ($m) => $this->lms->canSee($request->user(), $m))->pluck('id');
        $plan->materials()->sync($allowed);
    }
}
