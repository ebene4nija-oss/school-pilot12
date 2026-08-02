<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Expense;
use App\Models\Homework;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /**
     * 1. Principal Dashboard Analytics
     */
    public function getPrincipalDashboard(Request $request)
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

        // Best Performing Classes (Ranked by class average)
        $classAverages = ScoreEntry::where('school_id', $schoolId)
            ->join('students', 'score_entries.student_id', '=', 'students.id')
            ->join('classes', 'students.class_id', '=', 'classes.id')
            ->selectRaw('classes.name as class_name, AVG(score_entries.total_score) as average_score')
            ->groupBy('classes.name')
            ->orderBy('average_score', 'desc')
            ->limit(5)
            ->get();

        // Teacher Workload (Assignments created & score entries entered per teacher)
        $teacherWorkload = Homework::where('school_id', $schoolId)
            ->join('users', 'homework.teacher_id', '=', 'users.id')
            ->selectRaw('users.name as teacher_name, COUNT(homework.id) as homework_assigned')
            ->groupBy('users.name')
            ->orderBy('homework_assigned', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'role' => 'principal',
            'revenue' => [
                'total_collected' => round($totalCollectedRevenue, 2),
                'total_invoiced' => round($totalInvoicedAmount, 2),
                'fee_recovery_rate_percentage' => $feeRecoveryRate,
            ],
            'attendance_today' => [
                'present_count' => $totalPresentToday,
                'marked_count' => $totalMarkedToday,
                'rate_percentage' => $overallAttendanceRate,
            ],
            'best_performing_classes' => $classAverages,
            'teacher_workload' => $teacherWorkload,
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

        // Weak Topics / Lowest Performing Subjects
        $subjectPerformance = ScoreEntry::where('school_id', $schoolId)
            ->join('subjects', 'score_entries.subject_id', '=', 'subjects.id')
            ->selectRaw('subjects.name as subject_name, AVG(score_entries.total_score) as average_score')
            ->groupBy('subjects.name')
            ->orderBy('average_score', 'asc')
            ->limit(5)
            ->get();

        // Student Improvement Trend (Top improving students)
        $topImprovingStudents = ScoreEntry::where('school_id', $schoolId)
            ->join('students', 'score_entries.student_id', '=', 'students.id')
            ->join('users', 'students.user_id', '=', 'users.id')
            ->selectRaw('users.name as student_name, AVG(score_entries.total_score) as average_score')
            ->groupBy('users.name')
            ->orderBy('average_score', 'desc')
            ->limit(5)
            ->get();

        // Marking Progress (Count of pending AI comments requiring teacher review vs approved)
        $pendingComments = ScoreEntry::where('school_id', $schoolId)->where('ai_comment_status', 'pending_approval')->count();
        $approvedComments = ScoreEntry::where('school_id', $schoolId)->where('ai_comment_status', 'approved')->count();

        return response()->json([
            'role' => 'teacher',
            'weak_subjects_and_topics' => $subjectPerformance,
            'top_improving_students' => $topImprovingStudents,
            'marking_progress' => [
                'pending_reviews' => $pendingComments,
                'approved_comments' => $approvedComments,
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

        return response()->json([
            'role' => 'student',
            'overall_average' => round($averageScore, 2),
            'exam_readiness' => [
                'status' => $readinessLevel,
                'readiness_percentage' => round($averageScore, 2),
            ],
            'academic_strengths' => $strengths,
            'areas_for_improvement' => $weaknesses,
            'score_breakdown' => $scores,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
