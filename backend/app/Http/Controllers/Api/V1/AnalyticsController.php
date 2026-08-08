<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\Expense;
use App\Models\Homework;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\StudentGamification;
use App\Models\StudentTopicMastery;
use App\Models\Subject;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Services\SchoolInsightsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * 1. Principal Dashboard Analytics
     */
    public function getPrincipalDashboard(Request $request, SchoolInsightsService $insights)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        // Revenue & Financial Metrics
        $totalCollectedRevenue = Payment::where('school_id', $schoolId)->where('status', 'successful')->sum('amount');
        $totalInvoicedAmount = Invoice::where('school_id', $schoolId)->sum('total_amount');
        $feeRecoveryRate = $totalInvoicedAmount > 0 ? round(($totalCollectedRevenue / $totalInvoicedAmount) * 100, 2) : 0.00;

        // School-Wide Attendance (Today)
        $today = now()->toDateString();
        $totalPresentToday = AttendanceRecord::where('school_id', $schoolId)->where('date', $today)->where('status', 'present')->count();
        $totalMarkedToday = AttendanceRecord::where('school_id', $schoolId)->where('date', $today)->count();
        $overallAttendanceRate = $totalMarkedToday > 0 ? round(($totalPresentToday / $totalMarkedToday) * 100, 2) : 100.00;

        /*
         * Best performing classes.
         *
         * Every `school_id` below is table-qualified. The BelongsToTenant
         * global scope appends a bare `where school_id = ?`, and `students` and
         * `classes` both carry that column — so the unqualified version threw
         * "ambiguous column name: school_id" and this endpoint returned 500 on
         * any school with score data. It had no test.
         */
        $classAverages = ScoreEntry::where('score_entries.school_id', $schoolId)
            ->join('students', 'score_entries.student_id', '=', 'students.id')
            ->join('classes', 'students.class_id', '=', 'classes.id')
            ->selectRaw('classes.name as class_name, AVG(score_entries.total_score) as average_score')
            ->groupBy('classes.name')
            ->orderBy('average_score', 'desc')
            ->limit(5)
            ->get();

        // Teacher Workload (Assignments created & score entries entered per teacher)
        $teacherWorkload = Homework::where('homework.school_id', $schoolId)
            ->join('users', 'homework.teacher_id', '=', 'users.id')
            ->selectRaw('users.name as teacher_name, COUNT(homework.id) as homework_assigned')
            ->groupBy('users.name')
            ->orderBy('homework_assigned', 'desc')
            ->limit(10)
            ->get();

        // Student population — doc §6 lists it first among the admin tiles and
        // it was the one figure the dashboard did not report.
        $population = Student::where('school_id', $schoolId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // CBT activity (§6). Nothing counted this before because the engine
        // had no routes.
        $cbtStats = [
            'exams_published' => CbtExam::where('school_id', $schoolId)->where('status', 'published')->count(),
            'attempts_sat' => CbtAttempt::where('school_id', $schoolId)->whereIn('status', ['submitted', 'graded'])->count(),
            'awaiting_marking' => CbtAttempt::where('school_id', $schoolId)->where('requires_manual_grading', true)->count(),
            'average_percentage' => round((float) CbtAttempt::where('school_id', $schoolId)
                ->whereIn('status', ['submitted', 'graded'])
                ->avg('percentage'), 2),
        ];

        // Pending approvals (§6). An AI remark sitting unreviewed blocks the
        // whole report card, so this is the queue that stalls a term end.
        $pendingApprovals = [
            'ai_comments_awaiting_review' => ScoreEntry::where('school_id', $schoolId)
                ->where('ai_comment_status', 'pending_approval')
                ->count(),
            'cbt_attempts_awaiting_marking' => $cbtStats['awaiting_marking'],
        ];

        return response()->json([
            'role' => 'principal',
            'population' => [
                'active' => (int) ($population['active'] ?? 0),
                'by_status' => $population,
                'total' => (int) $population->sum(),
            ],
            'revenue' => [
                'total_collected' => round($totalCollectedRevenue, 2),
                'total_invoiced' => round($totalInvoicedAmount, 2),
                'fee_recovery_rate_percentage' => $feeRecoveryRate,
                'currency' => 'NGN',
            ],
            'attendance_today' => [
                'present_count' => $totalPresentToday,
                'marked_count' => $totalMarkedToday,
                'rate_percentage' => $overallAttendanceRate,
            ],
            'cbt' => $cbtStats,
            'pending_approvals' => $pendingApprovals,
            'best_performing_classes' => $classAverages,
            'teacher_workload' => $teacherWorkload,
            // §7.14 rule-based insight alerts — previously absent entirely.
            'insights' => [
                'grade_drops' => $insights->gradeDropAlerts($schoolId),
                'revenue_forecast' => $insights->revenueForecast($schoolId),
                'attrition_risk' => $insights->attritionRisk($schoolId),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * 2. Teacher Dashboard Analytics
     */
    public function getTeacherDashboard(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $teacherId = $request->user()->id;

        /*
         * Everything below is scoped to what this teacher actually teaches.
         *
         * It previously was not: `$teacherId` was assigned and never used, so
         * every teacher in the school opened the dashboard and saw identical
         * school-wide aggregates, including subjects they do not teach.
         */
        $assignments = DB::table('teacher_subjects')
            ->where('school_id', $schoolId)
            ->where('teacher_id', $teacherId)
            ->get();

        $mySubjectIds = $assignments->pluck('subject_id')->filter()->unique()->values();
        $myClassIds = $assignments->pluck('class_id')->filter()->unique()->values();

        $scopedScores = fn () => ScoreEntry::where('score_entries.school_id', $schoolId)
            ->when($mySubjectIds->isNotEmpty(), fn ($q) => $q->whereIn('score_entries.subject_id', $mySubjectIds));

        // Weakest of *my* subjects.
        $subjectPerformance = $scopedScores()
            ->join('subjects', 'score_entries.subject_id', '=', 'subjects.id')
            ->selectRaw('subjects.name as subject_name, AVG(score_entries.total_score) as average_score, COUNT(*) as entries')
            ->groupBy('subjects.name')
            ->orderBy('average_score', 'asc')
            ->limit(5)
            ->get();

        /*
         * Genuine improvement: this term's average against the previous one,
         * per student.
         *
         * The old query was labelled "top improving students" but ordered by
         * `AVG(total_score) DESC` — it listed the highest scorers, who are
         * frequently the students improving least because they start near the
         * ceiling. A mislabelled panel is worse than an absent one.
         */
        $perStudentTerm = $scopedScores()
            ->selectRaw('score_entries.student_id, score_entries.term_id, AVG(score_entries.total_score) as average')
            ->groupBy('score_entries.student_id', 'score_entries.term_id')
            ->orderBy('score_entries.student_id')
            ->orderByDesc('score_entries.term_id')
            ->get()
            ->groupBy('student_id');

        $improvements = [];
        foreach ($perStudentTerm as $studentId => $terms) {
            if ($terms->count() < 2) {
                continue;
            }

            $improvements[] = [
                'student_id' => (int) $studentId,
                'previous_average' => round((float) $terms[1]->average, 2),
                'current_average' => round((float) $terms[0]->average, 2),
                'change' => round((float) $terms[0]->average - (float) $terms[1]->average, 2),
            ];
        }

        usort($improvements, fn ($a, $b) => $b['change'] <=> $a['change']);
        $improvements = array_slice($improvements, 0, 5);

        $names = Student::where('school_id', $schoolId)
            ->whereIn('id', array_column($improvements, 'student_id'))
            ->with('user:id,name')
            ->get()
            ->keyBy('id');

        foreach ($improvements as $i => $row) {
            $improvements[$i]['student_name'] = $names->get($row['student_id'])?->user?->name;
        }

        // Today's classes (§6) — from the published timetable, this teacher only.
        $todaysClasses = TimetableEntry::where('teacher_id', $teacherId)
            ->where('day_of_week', now()->format('l'))
            ->where('is_break', false)
            ->whereHas('version', fn ($q) => $q->where('school_id', $schoolId)->where('status', 'published'))
            ->orderBy('start_time')
            ->get(['school_class_id', 'subject_name', 'slot_name', 'start_time', 'end_time']);

        // Assignments due (§6).
        $homeworkDue = Homework::where('school_id', $schoolId)
            ->where('teacher_id', $teacherId)
            ->where('due_date', '>=', now()->toDateString())
            ->with('subject:id,name')
            ->orderBy('due_date')
            ->limit(10)
            ->get(['id', 'title', 'subject_id', 'class_id', 'due_date']);

        // Upcoming exams (§6).
        $upcomingExams = CbtExam::where('school_id', $schoolId)
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>=', now()))
            ->when($mySubjectIds->isNotEmpty(), fn ($q) => $q->whereIn('subject_id', $mySubjectIds))
            ->orderBy('opens_at')
            ->limit(10)
            ->get(['id', 'title', 'subject_id', 'class_id', 'opens_at', 'closes_at']);

        // Marking progress, again scoped to this teacher's subjects.
        $pendingComments = $scopedScores()->where('ai_comment_status', 'pending_approval')->count();
        $approvedComments = $scopedScores()->where('ai_comment_status', 'approved')->count();

        $awaitingMarking = CbtAttempt::where('school_id', $schoolId)
            ->where('requires_manual_grading', true)
            ->when($mySubjectIds->isNotEmpty(), fn ($q) => $q->whereHas('exam', fn ($e) => $e->whereIn('subject_id', $mySubjectIds)))
            ->count();

        // Unread messages (§6).
        $unreadMessages = DB::table('messages')
            ->join('message_threads', 'messages.thread_id', '=', 'message_threads.id')
            ->where('message_threads.school_id', $schoolId)
            ->where(fn ($q) => $q->where('message_threads.teacher_id', $teacherId)->orWhere('message_threads.parent_id', $teacherId))
            ->where('messages.sender_id', '!=', $teacherId)
            ->whereNull('messages.read_at')
            ->count();

        return response()->json([
            'role' => 'teacher',
            'my_subjects' => Subject::whereIn('id', $mySubjectIds)->pluck('name'),
            'my_class_count' => $myClassIds->count(),
            'todays_classes' => $todaysClasses,
            'homework_due' => $homeworkDue,
            'upcoming_exams' => $upcomingExams,
            'unread_messages' => $unreadMessages,
            'weak_subjects_and_topics' => $subjectPerformance,
            'top_improving_students' => $improvements,
            'marking_progress' => [
                'pending_reviews' => $pendingComments,
                'approved_comments' => $approvedComments,
                'cbt_attempts_awaiting_marking' => $awaitingMarking,
                'completion_rate_percentage' => ($pendingComments + $approvedComments) > 0 ? round(($approvedComments / ($pendingComments + $approvedComments)) * 100, 2) : 100.00,
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * 3. Parent Dashboard Analytics
     */
    public function getParentDashboard(Request $request, $studentId)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        $student = Student::where('school_id', $schoolId)->with('user')->findOrFail($studentId);

        $this->authorize('view', $student);

        // Attendance Trend over last 3 months
        $attendanceRecords = AttendanceRecord::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();

        $totalDays = $attendanceRecords->count();
        $presentDays = $attendanceRecords->where('status', 'present')->count();
        $attendanceTrendPercentage = $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 2) : 100.00;

        // Grades Over Time
        $gradesOverTime = ScoreEntry::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->with(['subject', 'term'])
            ->orderBy('created_at', 'asc')
            ->get();

        // Fee Payment History & Outstanding Invoices
        $invoices = Invoice::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->with(['payments'])
            ->get();

        $totalFees = $invoices->sum('total_amount');
        $totalPaid = $invoices->sum('amount_paid');
        $outstandingBalance = $totalFees - $totalPaid;

        return response()->json([
            'role' => 'parent',
            'student_name' => $student->user ? $student->user->name : 'Child',
            'attendance_trend' => [
                'total_tracked_days' => $totalDays,
                'present_days' => $presentDays,
                'attendance_percentage' => $attendanceTrendPercentage,
                'recent_logs' => $attendanceRecords,
            ],
            'grades_over_time' => $gradesOverTime,
            'fee_history' => [
                'total_invoiced' => round($totalFees, 2),
                'total_paid' => round($totalPaid, 2),
                'outstanding_balance' => round($outstandingBalance, 2),
                'invoices' => $invoices,
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * 4. Student Dashboard Analytics
     */
    public function getStudentDashboard(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        $student = Student::where('school_id', $schoolId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$student) {
            return response()->json(['error' => 'Student record not found.'], 404);
        }

        $scores = ScoreEntry::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->with('subject')
            ->get();

        $strengths = $scores->where('total_score', '>=', 70)->pluck('subject.name')->unique()->values();
        $weaknesses = $scores->where('total_score', '<', 50)->pluck('subject.name')->unique()->values();

        $averageScore = $scores->avg('total_score') ?? 0;
        $readinessLevel = 'Needs Focus';
        if ($averageScore >= 75) $readinessLevel = 'Highly Prepared';
        elseif ($averageScore >= 60) $readinessLevel = 'On Track';

        /*
         * Doc §6 specifies timetable, homework, attendance, subjects,
         * performance, badges and upcoming tests on this dashboard. It
         * previously returned marks and a derived "readiness" label only —
         * every other panel below existed elsewhere in the API but was never
         * surfaced to the student whose data it is.
         */
        $todaysTimetable = TimetableEntry::where('school_class_id', $student->class_id)
            ->where('day_of_week', now()->format('l'))
            ->whereHas('version', fn ($q) => $q->where('school_id', $schoolId)->where('status', 'published'))
            ->orderBy('start_time')
            ->get(['subject_name', 'slot_name', 'start_time', 'end_time', 'is_break']);

        $homework = Homework::where('school_id', $schoolId)
            ->where('class_id', $student->class_id)
            ->where('due_date', '>=', now()->toDateString())
            ->with('subject:id,name')
            ->orderBy('due_date')
            ->limit(10)
            ->get(['id', 'title', 'subject_id', 'due_date']);

        $attendanceRecords = AttendanceRecord::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->get(['date', 'status']);

        $presentCount = $attendanceRecords->where('status', 'present')->count();

        $upcomingTests = CbtExam::where('school_id', $schoolId)
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('class_id')->orWhere('class_id', $student->class_id))
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>=', now()))
            ->with('subject:id,name')
            ->orderBy('opens_at')
            ->limit(10)
            ->get(['id', 'title', 'subject_id', 'duration_minutes', 'opens_at', 'closes_at']);

        $gamification = StudentGamification::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->first();

        // Measured from graded CBT items, not self-reported.
        $mastery = StudentTopicMastery::where('student_id', $student->id)
            ->orderBy('mastery_percentage')
            ->limit(5)
            ->get(['topic', 'mastery_percentage', 'questions_attempted']);

        return response()->json([
            'role' => 'student',
            'overall_average' => round($averageScore, 2),
            'exam_readiness' => [
                'status' => $readinessLevel,
                'readiness_percentage' => round($averageScore, 2),
            ],
            'todays_timetable' => $todaysTimetable,
            'homework_due' => $homework,
            'attendance_last_30_days' => [
                'days_marked' => $attendanceRecords->count(),
                'days_present' => $presentCount,
                'percentage' => $attendanceRecords->count() > 0
                    ? round(($presentCount / $attendanceRecords->count()) * 100, 2)
                    : null,
            ],
            'upcoming_tests' => $upcomingTests,
            'badges' => $gamification?->badges ?? [],
            'points' => $gamification?->points ?? 0,
            'current_streak' => $gamification?->current_streak ?? 0,
            'topics_to_work_on' => $mastery,
            'academic_strengths' => $strengths,
            'areas_for_improvement' => $weaknesses,
            'score_breakdown' => $scores,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
