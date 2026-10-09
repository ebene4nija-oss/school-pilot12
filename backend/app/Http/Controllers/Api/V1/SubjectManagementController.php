<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Subject;
use App\Services\Academics\SubjectOffering;
use App\Services\Academics\TeachingAssignments;
use App\Support\Sections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubjectManagementController extends Controller
{
    public function __construct(private SubjectOffering $offering)
    {
    }

    private function getSchoolId(Request $request)
    {
        /*
         * Tenant first — see Controller::schoolId(), which prefers the resolved
         * tenant, falls back to the acting user's own profile, and returns null
         * for a request that is explicitly unscoped.
         *
         * The guard here used to test the user's profile *first*. A platform
         * operator has no school on their profile, so for them the guard was
         * always false and this fell through to `tenant_school` — the school
         * resolved from the host, which for an operator is the internal `admin`
         * subdomain they signed in through, not the tenant they are inspecting.
         * These endpoints therefore answered for the wrong school entirely.
         */
        $schoolId = $this->schoolId();

        if ($schoolId !== null) {
            return $schoolId;
        }

        // Host-resolved tenant, for routes reached without an authenticated
        // profile at all.
        $tenant = $request->attributes->get('tenant_school');

        return $tenant ? $tenant->id : $request->attributes->get('school_id');
    }

    // ─── Subject CRUD ────────────────────────────────────────────────

    public function listSubjects(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        /*
         * One class's subjects (and one arm's, when its stream narrows them):
         * what every class-scoped picker should offer, so a Nursery 1
         * broadsheet never lists Physics. A class with no subjects set up
         * falls back to the subjects tagged for its level.
         */
        if ($request->filled('class_id')) {
            $classId = (int) $request->query('class_id');
            abort_unless(DB::table('classes')->where('school_id', $schoolId)->where('id', $classId)->exists(), 404, 'Class not found.');
            $armId = $request->filled('arm_id') ? (int) $request->query('arm_id') : null;
            abort_if($armId && ! DB::table('arms')->where('class_id', $classId)->where('id', $armId)->exists(), 404, 'Arm not found in that class.');

            return response()->json(['data' => $this->offering->subjectsFor($schoolId, $classId, $armId)->values()]);
        }

        if ($request->filled('level_group')) {
            $request->validate(['level_group' => [Rule::in(array_keys(Sections::LEVEL_GROUPS))]]);

            return response()->json(['data' => $this->offering->subjectsAtLevel($schoolId, $request->query('level_group'))]);
        }

        $query = Subject::where('school_id', $schoolId);

        // A deactivated subject is no longer offered: it drops out of every
        // picker (score entry, live classes) but keeps its history. The
        // subjects screen asks for it explicitly.
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($department = $request->query('department')) {
            $query->where('department', $department);
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function storeSubject(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            'name'         => 'required|string|max:255',
            'code'         => ['nullable', 'string', 'max:10', Rule::unique('subjects')->where('school_id', $schoolId)],
            'category'     => 'required|in:core,elective',
            'department'   => 'nullable|in:science,arts,commercial,general',
            'level_groups'   => 'nullable|array',
            'level_groups.*' => [Rule::in(array_keys(Sections::LEVEL_GROUPS))],
            'description'  => 'nullable|string|max:1000',
            'credit_units' => 'nullable|integer|min:1|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $subject = Subject::create([
            'school_id'    => $schoolId,
            'name'         => $request->name,
            'code'         => $request->code,
            'category'     => $request->category,
            'department'   => $request->department,
            'level_groups' => $request->level_groups ? array_values(array_unique($request->level_groups)) : null,
            'description'  => $request->description,
            'credit_units' => $request->credit_units ?? 1,
        ]);

        return response()->json(['message' => 'Subject created.', 'data' => $subject], 201);
    }

    /**
     * Rename, recode, recategorise, or stop offering a subject.
     *
     * There is no delete: scores, results and timetables point at subjects,
     * and a deleted Mathematics would take a term's broadsheet with it.
     * Deactivating hides it from pickers instead.
     */
    public function updateSubject(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $subject = Subject::where('school_id', $schoolId)->find($id);

        if (! $subject) {
            return response()->json(['message' => 'Subject not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'sometimes|required|string|max:255',
            'code'        => ['sometimes', 'nullable', 'string', 'max:10', Rule::unique('subjects')->where('school_id', $schoolId)->ignore($subject->id)],
            'category'    => 'sometimes|required|in:core,elective',
            'department'  => 'sometimes|nullable|in:science,arts,commercial,general',
            'level_groups'   => 'sometimes|nullable|array',
            'level_groups.*' => [Rule::in(array_keys(Sections::LEVEL_GROUPS))],
            'description' => 'sometimes|nullable|string|max:1000',
            'is_active'   => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if (array_key_exists('level_groups', $data)) {
            // An empty list means "every level", stored as null.
            $data['level_groups'] = $data['level_groups'] ? array_values(array_unique($data['level_groups'])) : null;
        }

        $subject->update($data);

        return response()->json(['message' => 'Subject updated.', 'data' => $subject->fresh()]);
    }

    /**
     * Who teaches what, across the school, in one list.
     *
     * The only reader before was per teacher, which cannot answer the question
     * a timetable officer actually asks: "who takes JSS 2 Mathematics?"
     */
    public function assignments(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $rows = DB::table('teacher_subjects')
            ->join('subjects', 'teacher_subjects.subject_id', '=', 'subjects.id')
            ->join('users', 'teacher_subjects.teacher_id', '=', 'users.id')
            ->leftJoin('classes', 'teacher_subjects.class_id', '=', 'classes.id')
            ->leftJoin('arms', 'teacher_subjects.arm_id', '=', 'arms.id')
            ->where('teacher_subjects.school_id', $schoolId)
            ->select(
                'teacher_subjects.id', 'teacher_subjects.is_primary',
                'subjects.id as subject_id', 'subjects.name as subject_name',
                'users.id as teacher_id', 'users.name as teacher_name',
                'classes.id as class_id', 'classes.name as class_name',
                'arms.id as arm_id', 'arms.name as arm_name'
            )
            ->orderBy('subjects.name')
            ->orderBy('classes.name')
            ->get();

        $classCounts = DB::table('class_subjects')
            ->where('school_id', $schoolId)
            ->selectRaw('subject_id, COUNT(*) as total')
            ->groupBy('subject_id')
            ->pluck('total', 'subject_id');

        return response()->json(['data' => $rows, 'class_counts' => $classCounts]);
    }

    /** Stop offering a subject in one class. The subject itself is untouched. */
    public function removeClassSubject(Request $request, $classId, $subjectId)
    {
        $schoolId = $this->getSchoolId($request);

        $deleted = DB::table('class_subjects')
            ->where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->where('subject_id', $subjectId)
            ->delete();

        return $deleted
            ? response()->json(['message' => 'Subject removed from the class.'])
            : response()->json(['message' => 'That class does not offer that subject.'], 404);
    }

    // ─── Assign Teacher to Subject ───────────────────────────────────

    public function assignTeacher(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            // A member of *this* school's teaching staff. This checked only that
            // the user existed, so any account on the platform — another
            // school's teacher, a parent — could be put down to teach here.
            'teacher_id' => ['required', Rule::exists('user_profiles', 'user_id')
                ->where('school_id', $schoolId)
                ->whereIn('role', ['teacher', 'school_admin'])],
            'subject_id' => ['required', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'class_id'   => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            // One arm of the class (SS 2A's Physics); none = every arm.
            'arm_id'     => ['nullable', Rule::exists('arms', 'id')->where('class_id', $request->input('class_id'))],
            'is_primary' => 'nullable|boolean',
            // Make this teacher the only one for the subject in this class/arm.
            'replace'    => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->boolean('replace') && $request->class_id) {
            DB::table('teacher_subjects')
                ->where('school_id', $schoolId)
                ->where('subject_id', $request->subject_id)
                ->where('class_id', $request->class_id)
                ->where(fn ($q) => $request->arm_id ? $q->where('arm_id', $request->arm_id) : $q->whereNull('arm_id'))
                ->delete();
        }

        DB::table('teacher_subjects')->updateOrInsert(
            [
                'school_id'  => $schoolId,
                'teacher_id' => $request->teacher_id,
                'subject_id' => $request->subject_id,
                'class_id'   => $request->class_id,
                'arm_id'     => $request->arm_id,
            ],
            [
                'is_primary'  => $request->is_primary ?? true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );

        return response()->json(['message' => 'Teacher assigned to subject successfully.']);
    }

    public function removeTeacher(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            'teacher_id' => 'required|integer',
            'subject_id' => 'required|integer',
            'class_id'   => 'nullable|integer',
            'arm_id'     => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = DB::table('teacher_subjects')
            ->where('school_id', $schoolId)
            ->where('teacher_id', $request->teacher_id)
            ->where('subject_id', $request->subject_id);

        if ($request->class_id) {
            $query->where('class_id', $request->class_id);
        } elseif ($request->has('class_id')) {
            // An explicit null is the "every class" assignment only. Leaving
            // the key out still removes every assignment, as it always has.
            $query->whereNull('class_id');
        }

        // Same rule for the arm: a value is that arm, an explicit null the
        // whole-class assignment, a missing key every arm.
        if ($request->arm_id) {
            $query->where('arm_id', $request->arm_id);
        } elseif ($request->has('arm_id')) {
            $query->whereNull('arm_id');
        }

        $query->delete();

        return response()->json(['message' => 'Teacher removed from subject.']);
    }

    public function getTeacherSubjects(Request $request, $teacherId)
    {
        $schoolId = $this->getSchoolId($request);

        /*
         * What they teach in effect: their subject assignments, and in a
         * section taught by class teachers every subject of their class. One
         * row per subject and class; `arm_ids` says which arms (empty: all).
         */
        $rows = app(TeachingAssignments::class)->rows($schoolId, (int) $teacherId);
        $subjects = DB::table('subjects')->where('school_id', $schoolId)->whereIn('id', $rows->pluck('subject_id')->all() ?: [0])->get(['id', 'name', 'code'])->keyBy('id');
        $classes = DB::table('classes')->where('school_id', $schoolId)->pluck('name', 'id');
        $arms = DB::table('arms')->where('school_id', $schoolId)->pluck('name', 'id');

        $data = $rows->groupBy(fn ($r) => $r->subject_id.':'.($r->class_id ?? 0))->map(function ($group) use ($subjects, $classes, $arms) {
            $first = $group->first();
            $armIds = $group->contains(fn ($r) => $r->arm_id === null) ? [] : $group->pluck('arm_id')->unique()->sort()->values()->all();

            return [
                'subject_id' => $first->subject_id,
                'subject_name' => $subjects[$first->subject_id]->name ?? null,
                'code' => $subjects[$first->subject_id]->code ?? null,
                'class_id' => $first->class_id,
                'class_name' => $first->class_id ? ($classes[$first->class_id] ?? null) : null,
                'arm_ids' => $armIds,
                'arm_names' => array_values(array_filter(array_map(fn ($id) => $arms[$id] ?? null, $armIds))),
                'source' => $group->contains('source', 'assigned') ? 'assigned' : 'class_teacher',
                'is_primary' => true,
            ];
        })->sortBy(['class_name', 'subject_name'])->values();

        return response()->json(['data' => $data]);
    }

    // ─── Class ↔ Subject Mapping (Compulsory vs Optional) ────────────

    public function setClassSubjects(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            'class_id'   => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'subjects'   => 'required|array|min:1',
            'subjects.*.subject_id'    => ['required', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'subjects.*.is_compulsory' => 'required|boolean',
            'subjects.*.max_students'  => 'nullable|integer|min:1',
            // Which streams take it (science, arts…); empty = every arm.
            'subjects.*.streams'       => 'nullable|array',
            'subjects.*.streams.*'     => [Rule::in(array_keys(SubjectOffering::STREAMS))],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Upsert each subject mapping for the class
        foreach ($request->subjects as $item) {
            DB::table('class_subjects')->updateOrInsert(
                [
                    'school_id'  => $schoolId,
                    'class_id'   => $request->class_id,
                    'subject_id' => $item['subject_id'],
                ],
                [
                    'is_compulsory' => $item['is_compulsory'],
                    'streams'       => empty($item['streams']) ? null : json_encode(array_values(array_unique($item['streams']))),
                    'max_students'  => $item['max_students'] ?? null,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]
            );
        }

        return response()->json([
            'message' => 'Class subjects configured successfully.',
            'count'   => count($request->subjects),
        ]);
    }

    public function getClassSubjects(Request $request, $classId)
    {
        $schoolId = $this->getSchoolId($request);

        $subjects = DB::table('class_subjects')
            ->join('subjects', 'class_subjects.subject_id', '=', 'subjects.id')
            ->where('class_subjects.school_id', $schoolId)
            ->where('class_subjects.class_id', $classId)
            ->select(
                'subjects.id', 'subjects.name', 'subjects.code',
                'subjects.category', 'subjects.department',
                'class_subjects.is_compulsory', 'class_subjects.max_students', 'class_subjects.streams'
            )
            ->orderByDesc('class_subjects.is_compulsory')
            ->orderBy('subjects.name')
            ->get()
            ->each(function ($s) {
                $s->streams = SubjectOffering::streamsOf($s);
                $s->is_compulsory = (bool) $s->is_compulsory;
            });

        // One arm's view: its stream decides what is compulsory, open or absent.
        if ($request->filled('arm_id')) {
            $offered = $this->offering->forArm($schoolId, (int) $classId, (int) $request->query('arm_id'));
            $subjects = $subjects->filter(fn ($s) => isset($offered[$s->id]))
                ->each(fn ($s) => $s->is_compulsory = $offered[$s->id] === 'compulsory')
                ->values();
        }

        $compulsory = $subjects->where('is_compulsory', true)->values();
        $elective   = $subjects->where('is_compulsory', false)->values();

        return response()->json([
            'class_id'    => (int) $classId,
            'compulsory'  => $compulsory,
            'elective'    => $elective,
            'total_count' => $subjects->count(),
        ]);
    }

    // ─── Student Subject Enrollment ──────────────────────────────────

    public function enrollStudentSubjects(Request $request, $studentId)
    {
        $schoolId = $this->getSchoolId($request);
        $student = Student::where('school_id', $schoolId)->findOrFail($studentId);

        $validator = Validator::make($request->all(), [
            'term_id'     => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'subject_ids' => 'required|array|min:1',
            'subject_ids.*' => ['integer', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // What the student's arm takes: compulsory for its stream, or open to choose.
        $offered = $this->offering->forArm($schoolId, (int) $student->class_id, $student->arm_id ? (int) $student->arm_id : null);
        $compulsorySubjectIds = array_keys(array_filter($offered, fn ($k) => $k === 'compulsory'));

        // Merge compulsory + selected electives (deduplicate)
        $allSubjectIds = array_unique(array_merge($compulsorySubjectIds, $request->subject_ids));

        // Validate that chosen electives are actually offered to this arm
        $validElectives = array_keys(array_filter($offered, fn ($k) => $k === 'elective'));

        $invalidElectives = array_diff($request->subject_ids, $compulsorySubjectIds, $validElectives);
        if (!empty($invalidElectives)) {
            return response()->json([
                'error' => 'Some selected subjects are not offered for this class.',
                'invalid_subject_ids' => array_values($invalidElectives),
            ], 422);
        }

        // Check elective capacity limits
        foreach ($request->subject_ids as $subjectId) {
            if (in_array($subjectId, $compulsorySubjectIds)) continue;

            $classSubject = DB::table('class_subjects')
                ->where('school_id', $schoolId)
                ->where('class_id', $student->class_id)
                ->where('subject_id', $subjectId)
                ->first();

            if ($classSubject && $classSubject->max_students) {
                $enrolled = DB::table('student_subjects')
                    ->where('school_id', $schoolId)
                    ->where('subject_id', $subjectId)
                    ->where('term_id', $request->term_id)
                    ->where('status', 'active')
                    ->count();

                if ($enrolled >= $classSubject->max_students) {
                    $subjectName = Subject::find($subjectId)->name ?? $subjectId;
                    return response()->json([
                        'error' => "Elective '{$subjectName}' has reached its capacity of {$classSubject->max_students} students.",
                    ], 409);
                }
            }
        }

        // Enroll student in all subjects
        $enrolledCount = 0;
        foreach ($allSubjectIds as $subjectId) {
            $type = in_array($subjectId, $compulsorySubjectIds) ? 'compulsory' : 'elective';

            DB::table('student_subjects')->updateOrInsert(
                [
                    'student_id' => $student->id,
                    'subject_id' => $subjectId,
                    'term_id'    => $request->term_id,
                ],
                [
                    'school_id'       => $schoolId,
                    'enrollment_type' => $type,
                    'status'          => 'active',
                    'enrolled_at'     => now(),
                    'dropped_at'      => null,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]
            );
            $enrolledCount++;
        }

        return response()->json([
            'message'          => "Student enrolled in {$enrolledCount} subjects.",
            'compulsory_count' => count($compulsorySubjectIds),
            'elective_count'   => $enrolledCount - count($compulsorySubjectIds),
        ]);
    }

    public function getStudentSubjects(Request $request, $studentId)
    {
        $schoolId = $this->getSchoolId($request);
        $student = Student::where('school_id', $schoolId)->findOrFail($studentId);
        // Whose subjects: the student, their guardians, or staff who may see them.
        $this->authorize('view', $student);

        $subjects = DB::table('student_subjects')
            ->join('subjects', 'student_subjects.subject_id', '=', 'subjects.id')
            ->where('student_subjects.school_id', $schoolId)
            ->where('student_subjects.student_id', $student->id)
            ->where('student_subjects.status', 'active')
            ->select(
                'subjects.id', 'subjects.name', 'subjects.code',
                'subjects.category', 'subjects.department',
                'student_subjects.enrollment_type', 'student_subjects.enrolled_at',
                'student_subjects.term_id'
            )
            ->orderBy('student_subjects.enrollment_type')
            ->orderBy('subjects.name')
            ->get();

        return response()->json([
            'student_id'  => $student->id,
            'compulsory'  => $subjects->where('enrollment_type', 'compulsory')->values(),
            'elective'    => $subjects->where('enrollment_type', 'elective')->values(),
            'total_count' => $subjects->count(),
        ]);
    }

    public function dropSubject(Request $request, $studentId, $subjectId)
    {
        $schoolId = $this->getSchoolId($request);
        $student = Student::where('school_id', $schoolId)->findOrFail($studentId);

        // Prevent dropping compulsory subjects
        $enrollment = DB::table('student_subjects')
            ->where('student_id', $student->id)
            ->where('subject_id', $subjectId)
            ->where('status', 'active')
            ->first();

        if (!$enrollment) {
            return response()->json(['error' => 'Student is not enrolled in this subject.'], 404);
        }

        if ($enrollment->enrollment_type === 'compulsory') {
            return response()->json(['error' => 'Compulsory subjects cannot be dropped.'], 403);
        }

        DB::table('student_subjects')
            ->where('student_id', $student->id)
            ->where('subject_id', $subjectId)
            ->where('status', 'active')
            ->update([
                'status'     => 'dropped',
                'dropped_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Elective subject dropped successfully.']);
    }

    /**
     * Auto-enroll all students in a class into their compulsory subjects for a term.
     */
    public function bulkAutoEnroll(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'term_id'  => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Each arm's stream decides its compulsory subjects.
        $students = Student::where('school_id', $schoolId)
            ->where('class_id', $request->class_id)
            ->pluck('arm_id', 'id');

        $byArm = [];
        $enrolled = 0;
        foreach ($students as $studentId => $armId) {
            $byArm[(int) $armId] ??= $this->offering->compulsoryFor($schoolId, (int) $request->class_id, $armId ? (int) $armId : null);

            foreach ($byArm[(int) $armId] as $subjectId) {
                DB::table('student_subjects')->updateOrInsert(
                    [
                        'student_id' => $studentId,
                        'subject_id' => $subjectId,
                        'term_id'    => $request->term_id,
                    ],
                    [
                        'school_id'       => $schoolId,
                        'enrollment_type' => 'compulsory',
                        'status'          => 'active',
                        'enrolled_at'     => now(),
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]
                );
                $enrolled++;
            }
        }

        $subjectCount = count(array_unique(array_merge([], ...array_values($byArm))));

        return response()->json([
            'message'        => "Auto-enrolled {$students->count()} students in their compulsory subjects.",
            'students_count' => $students->count(),
            'subjects_count' => $subjectCount,
            'total_enrollments' => $enrolled,
        ]);
    }
}
