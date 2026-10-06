<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\BehaviorReport;
use App\Models\Guardian;
use App\Models\Homework;
use App\Models\PickupAuthorization;
use App\Models\SchoolCalendarEvent;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ParentPortalController extends Controller
{
    /**
     * The caller's own children (gap G11).
     *
     * `student_guardian` has existed since the first migration and nothing ever
     * read it from the guardian's side. Every other parent endpoint —
     * `/parent/feed/{studentId}`, `/analytics/parent/{studentId}`,
     * `/result-checker/{studentId}/{termId}`, `/finance/students/{studentId}/statement`
     * — takes a student id the app had no way to learn, and `GET /students` is
     * staff-only. That made the parent role non-functional rather than merely
     * incomplete: a signed-in parent had no first request to make.
     *
     * Scoped to the guardian record belonging to this user, so it is the pivot
     * that decides what comes back, not school membership. A parent with no
     * guardian row gets an empty list and a reason, which is the state the app
     * already renders.
     */
    public function children(Request $request)
    {
        $user = $request->user();
        $schoolId = $this->schoolId();

        $guardian = Guardian::where('school_id', $schoolId)
            ->where('user_id', $user->id)
            ->first();

        if (! $guardian) {
            return response()->json([
                'data' => [],
                'message' => 'No children are linked to this account. Ask the school to link your child to your profile.',
            ]);
        }

        /*
         * `select` rather than the whole model. Student carries four encrypted
         * health columns; they are in `$hidden`, but a list this small has no
         * business loading and decrypting them at all just to render a name and
         * a class on a home screen (NDPA data minimisation, doc §12).
         */
        $children = $guardian->students()
            ->withPivot('is_primary')
            ->with(['user:id,name', 'currentClass:id,name', 'currentArm:id,name'])
            ->select([
                'students.id',
                'students.user_id',
                'students.class_id',
                'students.arm_id',
                'students.admission_number',
                'students.passport_photo_path',
                'students.status',
            ])
            ->orderBy('students.id')
            ->get()
            ->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => $student->user?->name,
                'admission_number' => $student->admission_number,
                'class_id' => $student->class_id,
                'class_name' => $student->currentClass?->name,
                'arm_name' => $student->currentArm?->name,
                'photo_url' => $student->passport_photo_path,
                'status' => $student->status,
                'is_primary_guardian' => (bool) $student->pivot->is_primary,
            ]);

        return response()->json(['data' => $children]);
    }

    /**
     * Get 360-Degree Parent Feed / Dashboard for a Linked Child
     */
    /**
     * Who a parent can message about this child: the form teacher and the
     * teachers of the child's subjects. Names and roles only.
     *
     * Messages need a recipient, and a parent had no way to learn who teaches
     * their child, so on the web they could only reply to a thread a teacher
     * had started.
     */
    public function contacts(Request $request, $studentId)
    {
        $schoolId = $this->schoolId();
        $student = Student::where('school_id', $schoolId)->findOrFail($studentId);

        $this->authorize('view', $student);

        $contacts = [];
        $add = function ($user, string $label) use (&$contacts) {
            if (! $user) {
                return;
            }
            $contacts[$user->id] ??= ['user_id' => $user->id, 'name' => $user->name, 'roles' => []];
            $contacts[$user->id]['roles'][] = $label;
        };

        $sessionId = \App\Support\TeacherScope::currentSessionId($schoolId);

        if ($sessionId && $student->class_id) {
            $add(\App\Models\ClassTeacherAssignment::formTeacherFor($schoolId, $sessionId, (int) $student->class_id, $student->arm_id ? (int) $student->arm_id : null), 'Form teacher');
        }

        \Illuminate\Support\Facades\DB::table('teacher_subjects')
            ->join('users', 'users.id', '=', 'teacher_subjects.teacher_id')
            ->join('subjects', 'subjects.id', '=', 'teacher_subjects.subject_id')
            ->where('teacher_subjects.school_id', $schoolId)
            ->where(fn ($q) => $q->where('teacher_subjects.class_id', $student->class_id)->orWhereNull('teacher_subjects.class_id'))
            ->orderBy('subjects.name')
            ->get(['users.id', 'users.name', 'subjects.name as subject'])
            ->each(fn ($row) => $add((object) ['id' => $row->id, 'name' => $row->name], $row->subject.' teacher'));

        return response()->json([
            'data' => array_values(array_map(fn ($c) => $c + ['role' => implode(', ', array_unique($c['roles']))], $contacts)),
        ]);
    }

    public function getStudentFeed(Request $request, $studentId)
    {
        $user = $request->user();
        $schoolId = $this->schoolId();

        $student = Student::where('school_id', $schoolId)->with(['user'])->findOrFail($studentId);

        // School membership is not authorization: without this a parent could
        // read any child's records by changing the id in the URL.
        $this->authorize('view', $student);

        // 1. Homework & Assignments
        $homework = Homework::where('school_id', $schoolId)
            ->setFor($student)
            ->where('due_date', '>=', now()->toDateString())
            ->with(['subject:id,name', 'teacher:id,name'])
            ->orderBy('due_date', 'asc')
            ->limit(20)
            ->get();

        // 2. Latest Academic Scores & Teacher Comments
        $latestScores = ScoreEntry::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->with(['subject', 'term'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // 3. Attendance Summary (Recent 30 days)
        $recentAttendance = AttendanceRecord::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->orderBy('date', 'desc')
            ->get();

        /*
         * 4. Behaviour & conduct log.
         *
         * Was unbounded — every behaviour report ever written about this child,
         * on the parent app's home screen, on every open. For a child in SS3
         * that is nine years of rows, re-downloaded over a metered connection
         * each time the app opens. Capped like the other panels; the full
         * history is a separate, paginated view.
         */
        $behaviorReports = BehaviorReport::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->with(['recorder:id,name'])
            ->orderBy('incident_date', 'desc')
            ->limit(10)
            ->get();

        // 5. Authorized Child Pickup Guardians
        $pickupAuthorizations = PickupAuthorization::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->where('is_active', true)
            ->limit(20)
            ->get();

        // 6. Upcoming School Calendar Events & Exams
        $calendarEvents = SchoolCalendarEvent::where('school_id', $schoolId)
            ->where('is_public', true)
            ->where('start_date', '>=', now()->toDateString())
            ->orderBy('start_date', 'asc')
            ->limit(10)
            ->get();

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->user ? $student->user->name : 'Child',
                'admission_number' => $student->admission_number,
            ],
            'homework' => $homework,
            'latest_scores' => $latestScores,
            'recent_attendance' => $recentAttendance,
            'behavior_reports' => $behaviorReports,
            'pickup_authorizations' => $pickupAuthorizations,
            'calendar_events' => $calendarEvents,
        ]);
    }

    /**
     * The full behaviour history, paginated.
     *
     * The feed above now shows the ten most recent rather than every report
     * ever filed. This is where the rest lives, fetched only when a parent
     * actually asks for it instead of on every app open.
     */
    public function behaviorHistory(Request $request, $studentId)
    {
        $user = $request->user();
        $schoolId = $this->schoolId();

        $student = Student::where('school_id', $schoolId)->findOrFail($studentId);

        $this->authorize('view', $student);

        return response()->json(
            BehaviorReport::where('school_id', $schoolId)
                ->where('student_id', $student->id)
                ->with('recorder:id,name')
                ->orderByDesc('incident_date')
                ->paginate(min((int) $request->input('per_page', 25), 100))
        );
    }

    /**
     * Create / Assign Homework (Teacher/Admin)
     */
    public function storeHomework(Request $request)
    {
        $schoolId = $this->schoolId();

        $validator = Validator::make($request->all(), [
            'class_id'   => ['required', \Illuminate\Validation\Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required', \Illuminate\Validation\Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'title'      => 'required|string|max:255',
            'description'=> 'required|string',
            'due_date'   => 'required|date',
            // TASK 14 §6.1; all optional so existing clients keep working.
            'arm_id' => ['nullable', \Illuminate\Validation\Rule::exists('arms', 'id')->where('class_id', $request->input('class_id'))],
            'max_score' => 'nullable|numeric|min:1|max:1000',
            'submission_type' => 'nullable|in:text,file,text_or_file',
            'allow_late' => 'nullable|boolean',
            'allow_parent_assist' => 'nullable|boolean',
            'draft' => 'nullable|boolean',
            'lesson_plan_id' => ['nullable', \Illuminate\Validation\Rule::exists('lesson_plans', 'id')->where('school_id', $schoolId)],
            'student_ids' => 'nullable|array|max:200',
            'student_ids.*' => [\Illuminate\Validation\Rule::exists('students', 'id')->where('school_id', $schoolId)->where('class_id', $request->input('class_id'))],
            'material_ids' => 'nullable|array|max:20',
            'material_ids.*' => [\Illuminate\Validation\Rule::exists('learning_materials', 'id')->where('school_id', $schoolId)],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $draft = $request->boolean('draft');
        $homework = Homework::create([
            'school_id' => $schoolId,
            'class_id' => $request->class_id,
            'subject_id' => $request->subject_id,
            'teacher_id' => $request->user()->id,
            'title' => $request->title,
            'description' => $request->description,
            'due_date' => $request->due_date,
            'arm_id' => $request->input('arm_id'),
            'max_score' => $request->input('max_score'),
            'submission_type' => $request->input('submission_type', 'text'),
            'allow_late' => $request->boolean('allow_late', true),
            'allow_parent_assist' => $request->boolean('allow_parent_assist'),
            'lesson_plan_id' => $request->input('lesson_plan_id'),
            'status' => $draft ? 'draft' : 'published',
            'published_at' => $draft ? null : now(),
        ]);

        foreach (array_unique($request->input('student_ids', [])) as $studentId) {
            \Illuminate\Support\Facades\DB::table('homework_targets')->insert(['homework_id' => $homework->id, 'student_id' => $studentId]);
        }
        foreach (array_unique($request->input('material_ids', [])) as $materialId) {
            \Illuminate\Support\Facades\DB::table('homework_materials')->insert(['homework_id' => $homework->id, 'material_id' => $materialId]);
        }

        if (! $draft) {
            $this->announceHomework($homework);
        }

        return response()->json([
            'message' => $draft ? 'Saved as a draft.' : 'Homework assigned successfully',
            'homework' => $homework->load(['subject', 'schoolClass']),
        ], 201);
    }

    /** Publish a draft assignment. */
    public function publishHomework(Request $request, $homeworkId)
    {
        $homework = Homework::where('school_id', $this->schoolId())->where('status', 'draft')->findOrFail($homeworkId);
        abort_unless((int) $homework->teacher_id === $request->user()->id || in_array($request->user()->userProfile?->role, ['school_admin', 'super_admin'], true), 403);
        $homework->update(['status' => 'published', 'published_at' => now()]);
        $this->announceHomework($homework);

        return response()->json(['message' => 'Published. Students have been told.', 'homework' => $homework->fresh()]);
    }

    /** Students are told; so are parents in sections where the school involves them closely. */
    private function announceHomework(Homework $homework): void
    {
        $lms = app(\App\Services\Lms\LmsService::class);
        $students = $lms->studentsOf((int) $homework->school_id, (int) $homework->class_id, $homework->arm_id, $homework->targetStudentIds());
        $due = $homework->due_date?->format('D j M');
        \App\Support\InAppNotifier::send((int) $homework->school_id, $students->pluck('user_id')->map(fn ($id) => (int) $id)->all(), 'lms', 'New assignment', "{$homework->title}, due {$due}.");
        \App\Support\InAppNotifier::send((int) $homework->school_id, $lms->guardianUserIds($students->pluck('id')->all()), 'lms', 'New assignment for your child', "{$homework->title}, due {$due}.");
    }

    /**
     * Register Child Pickup Authorization (Parent)
     */
    public function storePickupAuthorization(Request $request)
    {
        $schoolId = $this->schoolId();

        $validator = Validator::make($request->all(), [
            'student_id'              => ['required', \Illuminate\Validation\Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'authorized_person_name'  => 'required|string|max:255',
            'authorized_person_phone' => 'required|string',
            'relationship'            => 'required|string',
            'photo_path'              => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $pickup = PickupAuthorization::create([
            'school_id' => $schoolId,
            'student_id' => $request->student_id,
            'parent_id' => $request->user()->id,
            'authorized_person_name' => $request->authorized_person_name,
            'authorized_person_phone' => $request->authorized_person_phone,
            'relationship' => $request->relationship,
            'photo_path' => $request->photo_path,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Child pickup authorization registered successfully under NDPA safety protocol',
            'pickup_authorization' => $pickup,
        ], 201);
    }

    /**
     * Record Student Behavior & Conduct Report
     */
    public function storeBehaviorReport(Request $request)
    {
        $schoolId = $this->schoolId();

        $validator = Validator::make($request->all(), [
            'student_id'    => ['required', \Illuminate\Validation\Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'type'          => 'required|in:positive,warning,incident',
            'title'         => 'required|string|max:255',
            'description'   => 'required|string',
            'incident_date' => 'required|date',
            'notify_parent' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Only a child this member of staff may see (StudentPolicy): a teacher
        // records conduct for the classes they teach.
        $this->authorize('view', Student::where('school_id', $schoolId)->findOrFail($request->student_id));

        $report = BehaviorReport::create([
            'school_id' => $schoolId,
            'student_id' => $request->student_id,
            'recorded_by' => $request->user()->id,
            'type' => $request->type,
            'title' => $request->title,
            'description' => $request->description,
            'incident_date' => $request->incident_date,
        ]);

        // Optional Automated WhatsApp Alert to Parent
        /*
         * Tell the child's guardians of record — not the child's own account,
         * and never a placeholder number. This used to WhatsApp the student's
         * phone, or "+2348000000000" when there was none: a note about a
         * child's conduct sent to a stranger. The notice carries no detail,
         * since it may show on a lock screen; the parent reads it in the app.
         */
        if ($request->boolean('notify_parent', true)) {
            $student = Student::where('school_id', $schoolId)->with('user:id,name')->find($request->student_id);
            $guardians = app(\App\Services\Lms\LmsService::class)->guardianUserIds([(int) $request->student_id]);
            $first = $student?->user?->name ? explode(' ', $student->user->name)[0] : 'your child';
            \App\Support\InAppNotifier::send($schoolId, $guardians, 'behaviour', 'New conduct note',
                ($request->type === 'positive' ? "A commendation has been recorded for {$first}." : "A conduct note has been recorded for {$first}.").' Open SchoolPilot to read it.', true);
        }

        return response()->json([
            'message' => 'Behavior report logged successfully',
            'behavior_report' => $report,
        ], 201);
    }

    /**
     * Create School Calendar Event (Exams, Holidays, PTM, Sports)
     */
    public function storeCalendarEvent(Request $request)
    {
        $schoolId = $this->schoolId();

        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'category'    => 'required|in:academic,exam,holiday,sports,ptm',
            'start_date'  => 'required|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'is_public'   => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $event = SchoolCalendarEvent::create(array_merge($validator->validated(), ['school_id' => $schoolId]));

        return response()->json([
            'message' => 'School calendar event published successfully',
            'calendar_event' => $event,
        ], 201);
    }
}
