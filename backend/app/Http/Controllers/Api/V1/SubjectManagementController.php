<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubjectManagementController extends Controller
{
    private function getSchoolId(Request $request)
    {
        if ($request->user() && $request->user()->userProfile && $request->user()->userProfile->school_id) {
            return $request->user()->userProfile->school_id;
        }
        $tenant = $request->attributes->get('tenant_school');
        return $tenant ? $tenant->id : $request->attributes->get('school_id');
    }

    // ─── Subject CRUD ────────────────────────────────────────────────

    public function listSubjects(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $query = Subject::where('school_id', $schoolId);

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
            'description'  => $request->description,
            'credit_units' => $request->credit_units ?? 1,
        ]);

        return response()->json(['message' => 'Subject created.', 'data' => $subject], 201);
    }

    // ─── Assign Teacher to Subject ───────────────────────────────────

    public function assignTeacher(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            'teacher_id' => ['required', Rule::exists('users', 'id')],
            'subject_id' => ['required', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'class_id'   => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'is_primary' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::table('teacher_subjects')->updateOrInsert(
            [
                'school_id'  => $schoolId,
                'teacher_id' => $request->teacher_id,
                'subject_id' => $request->subject_id,
                'class_id'   => $request->class_id,
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
        }

        $query->delete();

        return response()->json(['message' => 'Teacher removed from subject.']);
    }

    public function getTeacherSubjects(Request $request, $teacherId)
    {
        $schoolId = $this->getSchoolId($request);

        $subjects = DB::table('teacher_subjects')
            ->join('subjects', 'teacher_subjects.subject_id', '=', 'subjects.id')
            ->leftJoin('classes', 'teacher_subjects.class_id', '=', 'classes.id')
            ->where('teacher_subjects.school_id', $schoolId)
            ->where('teacher_subjects.teacher_id', $teacherId)
            ->select(
                'subjects.id as subject_id', 'subjects.name as subject_name', 'subjects.code',
                'classes.id as class_id', 'classes.name as class_name',
                'teacher_subjects.is_primary'
            )
            ->get();

        return response()->json(['data' => $subjects]);
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
                'class_subjects.is_compulsory', 'class_subjects.max_students'
            )
            ->orderByDesc('class_subjects.is_compulsory')
            ->orderBy('subjects.name')
            ->get();

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

        // Auto-enroll compulsory subjects for the student's class
        $compulsorySubjectIds = DB::table('class_subjects')
            ->where('school_id', $schoolId)
            ->where('class_id', $student->class_id)
            ->where('is_compulsory', true)
            ->pluck('subject_id')
            ->toArray();

        // Merge compulsory + selected electives (deduplicate)
        $allSubjectIds = array_unique(array_merge($compulsorySubjectIds, $request->subject_ids));

        // Validate that chosen electives are actually offered for this class
        $validElectives = DB::table('class_subjects')
            ->where('school_id', $schoolId)
            ->where('class_id', $student->class_id)
            ->where('is_compulsory', false)
            ->pluck('subject_id')
            ->toArray();

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

        $compulsorySubjectIds = DB::table('class_subjects')
            ->where('school_id', $schoolId)
            ->where('class_id', $request->class_id)
            ->where('is_compulsory', true)
            ->pluck('subject_id');

        $students = Student::where('school_id', $schoolId)
            ->where('class_id', $request->class_id)
            ->pluck('id');

        $enrolled = 0;
        foreach ($students as $studentId) {
            foreach ($compulsorySubjectIds as $subjectId) {
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

        return response()->json([
            'message'        => "Auto-enrolled {$students->count()} students in {$compulsorySubjectIds->count()} compulsory subjects.",
            'students_count' => $students->count(),
            'subjects_count' => $compulsorySubjectIds->count(),
            'total_enrollments' => $enrolled,
        ]);
    }
}
