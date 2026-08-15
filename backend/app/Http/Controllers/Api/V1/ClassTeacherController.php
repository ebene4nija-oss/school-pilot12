<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\AuditLog;
use App\Models\ClassTeacherAssignment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Form teachers.
 *
 * One form teacher (and optionally one assistant) per class-arm per session.
 * Everything downstream — the class-teacher comment on a report card, the
 * default recipient for a parent's message about a child, ownership of the
 * daily register — needs a single answer to "whose class is this?", and until
 * now there was none.
 */
class ClassTeacherController extends Controller
{
    private function schoolId(Request $request): ?int
    {
        $profile = $request->user()?->userProfile;

        return $profile?->school_id;
    }

    /**
     * Assignments for a session, defaulting to the current one.
     *
     * Returns a row per class-arm the school actually runs — including the
     * unassigned ones, which is the half an admin needs to see in September.
     */
    public function index(Request $request)
    {
        $schoolId = $this->schoolId($request);
        $sessionId = $request->query('session_id');

        if ($sessionId) {
            $session = AcademicSession::where('school_id', $schoolId)->find($sessionId);
        } else {
            $session = AcademicSession::where('school_id', $schoolId)
                ->where('is_current', true)
                ->first();
        }

        if (! $session) {
            return response()->json([
                'message' => 'No academic session found. Create one before assigning form teachers.',
            ], 404);
        }

        $assignments = ClassTeacherAssignment::where('session_id', $session->id)
            ->with([
                'formTeacher:id,name,email',
                'assistantTeacher:id,name,email',
            ])
            ->get()
            ->keyBy(fn ($a) => $a->class_id . ':' . ($a->arm_id ?? 'none'));

        // One head-count query for the whole school rather than one per arm.
        $headcounts = Student::where('school_id', $schoolId)
            ->where('status', 'active')
            ->selectRaw('class_id, arm_id, count(*) as total')
            ->groupBy('class_id', 'arm_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->class_id . ':' . ($r->arm_id ?? 'none') => (int) $r->total]);

        $armsByClass = Arm::where('school_id', $schoolId)
            ->orderBy('name')
            ->get()
            ->groupBy('class_id');

        $rows = [];

        foreach (SchoolClass::where('school_id', $schoolId)->orderBy('order_index')->get() as $class) {
            $arms = $armsByClass->get($class->id, collect());

            // A class with no arms is one placement, not zero.
            $placements = $arms->isEmpty()
                ? [null]
                : $arms->all();

            foreach ($placements as $arm) {
                $key = $class->id . ':' . ($arm->id ?? 'none');
                $assignment = $assignments->get($key);

                $rows[] = [
                    'class_id' => $class->id,
                    'class_name' => $class->name,
                    'arm_id' => $arm->id ?? null,
                    'arm_name' => $arm->name ?? null,
                    'student_count' => $headcounts[$key] ?? 0,
                    'assignment_id' => $assignment?->id,
                    'form_teacher' => $assignment?->formTeacher
                        ? ['id' => $assignment->formTeacher->id, 'name' => $assignment->formTeacher->name]
                        : null,
                    'assistant_teacher' => $assignment?->assistantTeacher
                        ? ['id' => $assignment->assistantTeacher->id, 'name' => $assignment->assistantTeacher->name]
                        : null,
                ];
            }
        }

        return response()->json([
            'session' => ['id' => $session->id, 'name' => $session->name],
            'data' => $rows,
            'meta' => [
                'total_placements' => count($rows),
                'unassigned' => count(array_filter($rows, fn ($r) => $r['form_teacher'] === null)),
            ],
        ]);
    }

    /**
     * Assign, or re-assign, the form teacher of one class-arm.
     *
     * Idempotent by placement: posting twice for JSS 2 Gold replaces the
     * teacher rather than creating a second row, because the unique index
     * cannot enforce that on its own when `arm_id` is NULL.
     */
    public function store(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validator = Validator::make($request->all(), [
            'session_id' => [
                'required',
                Rule::exists('academic_sessions', 'id')->where('school_id', $schoolId),
            ],
            'class_id' => [
                'required',
                Rule::exists('classes', 'id')->where('school_id', $schoolId),
            ],
            'arm_id' => [
                'nullable',
                Rule::exists('arms', 'id')
                    ->where('school_id', $schoolId)
                    ->where('class_id', $request->input('class_id')),
            ],
            'form_teacher_id' => 'required|integer',
            'assistant_teacher_id' => 'nullable|integer|different:form_teacher_id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        /*
         * Staff membership is checked against this school's profiles, not
         * against `users` — a plain `exists:users,id` would happily accept
         * another school's teacher, or one of this school's parents.
         */
        foreach (['form_teacher_id', 'assistant_teacher_id'] as $field) {
            $teacherId = $request->input($field);

            if (! $teacherId) {
                continue;
            }

            $isStaff = UserProfile::where('school_id', $schoolId)
                ->where('user_id', $teacherId)
                ->whereIn('role', ['teacher', 'school_admin'])
                ->exists();

            if (! $isStaff) {
                return response()->json([
                    'errors' => [$field => ['That user is not a teacher at this school.']],
                ], 422);
            }
        }

        $armId = $request->input('arm_id');

        $assignment = DB::transaction(function () use ($request, $schoolId, $armId) {
            $existing = ClassTeacherAssignment::where('session_id', $request->session_id)
                ->where('class_id', $request->class_id)
                ->when($armId === null,
                    fn ($q) => $q->whereNull('arm_id'),
                    fn ($q) => $q->where('arm_id', $armId),
                )
                ->lockForUpdate()
                ->first();

            $attributes = [
                'school_id' => $schoolId,
                'session_id' => $request->session_id,
                'class_id' => $request->class_id,
                'arm_id' => $armId,
                'form_teacher_id' => $request->form_teacher_id,
                'assistant_teacher_id' => $request->assistant_teacher_id,
                'assigned_by' => $request->user()->id,
            ];

            if ($existing) {
                $existing->update($attributes);

                return $existing;
            }

            return ClassTeacherAssignment::create($attributes);
        });

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'class_teacher.assigned',
            'auditable_type' => ClassTeacherAssignment::class,
            'auditable_id' => $assignment->id,
            'new_values' => $assignment->only([
                'session_id', 'class_id', 'arm_id', 'form_teacher_id', 'assistant_teacher_id',
            ]),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Form teacher assigned.',
            'assignment' => $assignment->load([
                'formTeacher:id,name',
                'assistantTeacher:id,name',
                'schoolClass:id,name',
                'arm:id,name',
            ]),
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $schoolId = $this->schoolId($request);

        $assignment = ClassTeacherAssignment::where('school_id', $schoolId)->findOrFail($id);

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'class_teacher.unassigned',
            'auditable_type' => ClassTeacherAssignment::class,
            'auditable_id' => $assignment->id,
            'old_values' => $assignment->only([
                'session_id', 'class_id', 'arm_id', 'form_teacher_id', 'assistant_teacher_id',
            ]),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $assignment->delete();

        return response()->json(['message' => 'Form teacher unassigned.']);
    }

    /**
     * "Which classes am I the form teacher of?" — the teacher's own view.
     *
     * Deliberately not parameterised by teacher id: a teacher reads their own
     * assignments, an admin reads the whole school through `index`.
     */
    public function myClasses(Request $request)
    {
        $schoolId = $this->schoolId($request);
        $userId = $request->user()->id;

        $sessionId = $request->query('session_id')
            ?: AcademicSession::where('school_id', $schoolId)->where('is_current', true)->value('id');

        $assignments = ClassTeacherAssignment::where('session_id', $sessionId)
            ->where(function ($q) use ($userId) {
                $q->where('form_teacher_id', $userId)
                  ->orWhere('assistant_teacher_id', $userId);
            })
            ->with(['schoolClass:id,name', 'arm:id,name', 'session:id,name'])
            ->get()
            ->map(fn ($a) => [
                'assignment_id' => $a->id,
                'class_id' => $a->class_id,
                'class_name' => $a->schoolClass->name ?? null,
                'arm_id' => $a->arm_id,
                'arm_name' => $a->arm->name ?? null,
                'session' => $a->session->name ?? null,
                'role' => $a->form_teacher_id === $userId ? 'form_teacher' : 'assistant',
                'student_count' => Student::where('school_id', $schoolId)
                    ->where('class_id', $a->class_id)
                    ->when($a->arm_id === null,
                        fn ($q) => $q->whereNull('arm_id'),
                        fn ($q) => $q->where('arm_id', $a->arm_id),
                    )
                    ->where('status', 'active')
                    ->count(),
            ]);

        return response()->json(['data' => $assignments]);
    }
}
