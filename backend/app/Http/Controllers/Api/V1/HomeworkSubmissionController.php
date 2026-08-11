<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The return half of homework.
 *
 * Teachers could set work; nothing could be handed back. This closes that
 * loop: a student submits, the teacher sees who has and has not, and marks it.
 *
 * Authorization is object-level throughout. Class membership decides who may
 * submit, and authorship decides who may mark — role middleware alone would
 * let any teacher in the school mark any other teacher's assignment.
 */
class HomeworkSubmissionController extends Controller
{
    private function schoolId(Request $request): ?int
    {
        return $request->user()?->userProfile?->school_id;
    }

    private function currentStudent(Request $request): ?Student
    {
        return Student::where('user_id', $request->user()->id)->first();
    }

    /**
     * What homework exists, for the person who has to do it (gap G5).
     *
     * Until this, the only way to reach an assignment was to already hold its
     * id: a student could submit to `/homework/{id}/submit` and read back
     * `/homework/{id}/my-submission`, but nothing anywhere told them an
     * assignment had been set. The parent feed carried a homework panel, which
     * meant a guardian could see work their own child could not.
     *
     * One route, three scopings, because "what homework is there" is the same
     * question for all three and splitting it into three endpoints would mean
     * three chances for them to disagree about what counts as overdue.
     */
    public function feed(Request $request)
    {
        $user = $request->user();
        $role = $user->userProfile?->role;
        $schoolId = $this->schoolId($request);

        $query = Homework::where('school_id', $schoolId)
            ->with(['subject:id,name', 'teacher:id,name', 'schoolClass:id,name']);

        // Whose submissions to fold in, if any. Staff see the assignment, not
        // a single child's answer to it.
        $student = null;

        switch ($role) {
            case 'student':
                $student = $this->currentStudent($request);

                if (! $student) {
                    return response()->json(['error' => 'Student record not found.'], 403);
                }

                $query->where('class_id', $student->class_id);
                break;

            case 'parent':
                $student = $this->guardedStudent($request);

                if (! $student) {
                    return response()->json([
                        'data' => [],
                        'message' => 'No children are linked to this account. Ask the school to link your child to your profile.',
                    ]);
                }

                $query->where('class_id', $student->class_id);
                break;

            case 'teacher':
                /*
                 * A teacher's own assignments by default. `class_id` widens it
                 * to a class they are covering — a colleague's work is
                 * legitimately visible to staff, unlike a child's answer to it.
                 */
                if ($request->filled('class_id')) {
                    $query->where('class_id', (int) $request->input('class_id'));
                } else {
                    $query->where('teacher_id', $user->id);
                }
                break;

            default: // super_admin, school_admin
                if ($request->filled('class_id')) {
                    $query->where('class_id', (int) $request->input('class_id'));
                }
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', (int) $request->input('subject_id'));
        }

        /*
         * Open work only, by default.
         *
         * A pupil in SS3 has nine years of assignments behind them, and the
         * question they are asking is "what do I owe this week", not "what have
         * I ever been set". `include_past=1` is there for the parent who wants
         * the whole term.
         */
        if (! $request->boolean('include_past')) {
            $query->whereDate('due_date', '>=', now()->subDays(7)->toDateString());
        }

        $page = $query->orderBy('due_date')->paginate(
            min((int) $request->input('per_page', 25), 100)
        );

        // One query for the whole page's submissions rather than one per row.
        $submissions = $student
            ? HomeworkSubmission::where('student_id', $student->id)
                ->whereIn('homework_id', $page->pluck('id'))
                ->get()
                ->keyBy('homework_id')
            : collect();

        $today = now()->startOfDay();

        $page->getCollection()->transform(function (Homework $homework) use ($submissions, $student, $today) {
            $submission = $submissions->get($homework->id);

            $row = [
                'id' => $homework->id,
                'title' => $homework->title,
                'description' => $homework->description,
                'due_date' => $homework->due_date?->toDateString(),
                'class_id' => $homework->class_id,
                'class_name' => $homework->schoolClass?->name,
                'subject' => $homework->subject?->name,
                'teacher' => $homework->teacher?->name,
            ];

            if (! $student) {
                return $row;
            }

            /*
             * `overdue` is computed here rather than left to each client.
             * Three of them would otherwise each decide whether a due date of
             * today counts, and a parent seeing "overdue" on work due this
             * afternoon is a phone call to the school.
             */
            $submitted = $submission !== null;

            return $row + [
                'submitted' => $submitted,
                'submitted_at' => $submission?->submitted_at?->toIso8601String(),
                'status' => $submission->status ?? 'not_submitted',
                'overdue' => ! $submitted
                    && $homework->due_date !== null
                    && $homework->due_date->lt($today),
                'marks_awarded' => $submission?->marks_awarded !== null
                    ? (float) $submission->marks_awarded
                    : null,
                'marks_available' => $submission?->marks_available !== null
                    ? (float) $submission->marks_available
                    : null,
                'teacher_feedback' => $submission?->teacher_feedback,
            ];
        });

        // Flat `data` plus a sibling `meta`, matching the inbox and the thread
        // list. A raw paginator would put the rows at `data.data.N`, which is
        // the one shape none of the three clients reads the same way.
        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * The child a guardian is asking about.
     *
     * `student_id` is required once a guardian has more than one child,
     * because guessing which one they meant and silently showing the wrong
     * child's homework is worse than asking. The pivot decides what is
     * allowed either way — school membership never does.
     */
    private function guardedStudent(Request $request): ?Student
    {
        $user = $request->user();

        $guardian = \App\Models\Guardian::where('school_id', $this->schoolId($request))
            ->where('user_id', $user->id)
            ->first();

        if (! $guardian) {
            return null;
        }

        if ($request->filled('student_id')) {
            $student = Student::where('id', (int) $request->input('student_id'))->first();

            return $student && $student->isGuardedBy($user) ? $student : null;
        }

        return $guardian->students()->first();
    }

    /**
     * Submit or update an answer.
     *
     * Re-submitting before the teacher marks it replaces the previous attempt —
     * a child who spots a mistake five minutes later should not have to ask
     * their teacher to ignore the first one. Once marked, it is closed.
     */
    public function store(Request $request, $homeworkId)
    {
        $student = $this->currentStudent($request);

        if (! $student) {
            return response()->json(['error' => 'Only enrolled students can submit homework.'], 403);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:20000',
        ]);

        $homework = Homework::where('school_id', $student->school_id)->findOrFail($homeworkId);

        // Class binding: an assignment set for JSS1 is not submittable by SS3.
        if ((int) $homework->class_id !== (int) $student->class_id) {
            return response()->json(['error' => 'This homework was not set for your class.'], 403);
        }

        $existing = HomeworkSubmission::where('homework_id', $homework->id)
            ->where('student_id', $student->id)
            ->first();

        if ($existing && $existing->isGraded()) {
            return response()->json([
                'error' => 'This homework has already been marked and can no longer be changed.',
            ], 409);
        }

        $now = now();

        // Lateness is judged once, at submission. Re-reading the due date at
        // display time would let a teacher who extends a deadline retroactively
        // clear (or create) late marks.
        $isLate = $existing
            ? $existing->is_late
            : ($homework->due_date && $now->gt($homework->due_date->endOfDay()));

        $submission = HomeworkSubmission::updateOrCreate(
            ['homework_id' => $homework->id, 'student_id' => $student->id],
            [
                'school_id' => $student->school_id,
                'body' => $validated['body'],
                'submitted_at' => $now,
                'is_late' => $isLate,
                'status' => 'submitted',
            ]
        );

        return response()->json([
            'message' => $isLate ? 'Submitted after the due date.' : 'Submitted.',
            'submission' => $submission,
        ], $existing ? 200 : 201);
    }

    /** A student's own submission for one assignment. */
    public function mine(Request $request, $homeworkId)
    {
        $student = $this->currentStudent($request);

        if (! $student) {
            return response()->json(['error' => 'Only enrolled students can view submissions.'], 403);
        }

        $submission = HomeworkSubmission::where('homework_id', $homeworkId)
            ->where('student_id', $student->id)
            ->first();

        return response()->json([
            'submitted' => $submission !== null,
            'submission' => $submission,
        ]);
    }

    /**
     * The teacher's marking list: every child in the class, submitted or not.
     *
     * Listing only the submissions received would answer the wrong question —
     * what a teacher needs on the morning work is due is who has *not* handed
     * in.
     */
    public function index(Request $request, $homeworkId)
    {
        $schoolId = $this->schoolId($request);
        $homework = Homework::where('school_id', $schoolId)->findOrFail($homeworkId);

        $this->authorizeMarking($request, $homework);

        $students = Student::where('school_id', $schoolId)
            ->where('class_id', $homework->class_id)
            ->with('user:id,name')
            ->orderBy('admission_number')
            ->get();

        $submissions = HomeworkSubmission::where('homework_id', $homework->id)
            ->get()
            ->keyBy('student_id');

        $rows = $students->map(function (Student $student) use ($submissions) {
            $submission = $submissions->get($student->id);

            return [
                'student_id' => $student->id,
                'student_name' => $student->user?->name,
                'admission_number' => $student->admission_number,
                'submitted' => $submission !== null,
                'submitted_at' => $submission?->submitted_at?->toIso8601String(),
                'is_late' => (bool) $submission?->is_late,
                'status' => $submission->status ?? 'not_submitted',
                'marks_awarded' => $submission?->marks_awarded !== null ? (float) $submission->marks_awarded : null,
                'submission_id' => $submission?->id,
                'body' => $submission?->body,
            ];
        });

        return response()->json([
            'homework' => $homework->only(['id', 'title', 'due_date', 'class_id', 'subject_id']),
            'summary' => [
                'class_size' => $students->count(),
                'submitted' => $rows->where('submitted', true)->count(),
                'outstanding' => $rows->where('submitted', false)->count(),
                'late' => $rows->where('is_late', true)->count(),
                'marked' => $rows->where('status', 'graded')->count(),
            ],
            'submissions' => $rows->values(),
        ]);
    }

    /** Mark one submission. */
    public function grade(Request $request, $submissionId)
    {
        $schoolId = $this->schoolId($request);

        $submission = HomeworkSubmission::where('school_id', $schoolId)->findOrFail($submissionId);
        $homework = Homework::where('school_id', $schoolId)->findOrFail($submission->homework_id);

        $this->authorizeMarking($request, $homework);

        $validated = $request->validate([
            'marks_awarded' => 'required|numeric|min:0',
            'marks_available' => 'nullable|numeric|min:1',
            'teacher_feedback' => 'nullable|string|max:5000',
        ]);

        $available = $validated['marks_available'] ?? $submission->marks_available ?? 10;

        if ($validated['marks_awarded'] > $available) {
            return response()->json([
                'errors' => ['marks_awarded' => ["Cannot award more than the {$available} marks available."]],
            ], 422);
        }

        $submission->update([
            'marks_awarded' => $validated['marks_awarded'],
            'marks_available' => $available,
            'teacher_feedback' => $validated['teacher_feedback'] ?? null,
            'graded_by' => $request->user()->id,
            'graded_at' => now(),
            'status' => 'graded',
        ]);

        return response()->json(['message' => 'Marked.', 'submission' => $submission->fresh()]);
    }

    /** Mark a whole class in one request — the realistic teacher workflow. */
    public function bulkGrade(Request $request, $homeworkId)
    {
        $schoolId = $this->schoolId($request);
        $homework = Homework::where('school_id', $schoolId)->findOrFail($homeworkId);

        $this->authorizeMarking($request, $homework);

        $validated = $request->validate([
            'marks_available' => 'required|numeric|min:1',
            'marks' => 'required|array|min:1',
            'marks.*.submission_id' => 'required|integer',
            'marks.*.marks_awarded' => 'required|numeric|min:0',
            'marks.*.teacher_feedback' => 'nullable|string|max:5000',
        ]);

        $graded = 0;
        $rejected = [];

        DB::transaction(function () use ($validated, $homework, $request, &$graded, &$rejected) {
            foreach ($validated['marks'] as $row) {
                $submission = HomeworkSubmission::where('homework_id', $homework->id)
                    ->find($row['submission_id']);

                if (! $submission) {
                    $rejected[] = "Submission {$row['submission_id']} does not belong to this assignment.";
                    continue;
                }

                if ($row['marks_awarded'] > $validated['marks_available']) {
                    $rejected[] = "Submission {$row['submission_id']}: awarded more than the marks available.";
                    continue;
                }

                $submission->update([
                    'marks_awarded' => $row['marks_awarded'],
                    'marks_available' => $validated['marks_available'],
                    'teacher_feedback' => $row['teacher_feedback'] ?? null,
                    'graded_by' => $request->user()->id,
                    'graded_at' => now(),
                    'status' => 'graded',
                ]);

                $graded++;
            }
        });

        return response()->json([
            'message' => "Marked {$graded} submission(s).",
            'graded' => $graded,
            'rejected' => $rejected,
        ]);
    }

    /**
     * Admins mark anything; a teacher marks the work they set.
     *
     * Without this, `role:teacher` alone would let any teacher in the school
     * open and mark a colleague's class.
     */
    private function authorizeMarking(Request $request, Homework $homework): void
    {
        $role = $request->user()->userProfile?->role;

        if (in_array($role, ['super_admin', 'school_admin'], true)) {
            return;
        }

        abort_unless(
            $role === 'teacher' && (int) $homework->teacher_id === (int) $request->user()->id,
            403,
            'You can only mark homework you set.'
        );
    }
}
