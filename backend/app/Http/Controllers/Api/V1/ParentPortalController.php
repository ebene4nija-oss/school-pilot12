<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\BehaviorReport;
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
     * Get 360-Degree Parent Feed / Dashboard for a Linked Child
     */
    public function getStudentFeed(Request $request, $studentId)
    {
        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $student = Student::where('school_id', $schoolId)->with(['user'])->findOrFail($studentId);

        // 1. Homework & Assignments
        $homework = Homework::where('school_id', $schoolId)
            ->where('class_id', $student->class_id)
            ->where('due_date', '>=', now()->toDateString())
            ->with(['subject', 'teacher'])
            ->orderBy('due_date', 'asc')
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

        // 4. Behavior & Conduct Log
        $behaviorReports = BehaviorReport::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->with(['recorder'])
            ->orderBy('incident_date', 'desc')
            ->get();

        // 5. Authorized Child Pickup Guardians
        $pickupAuthorizations = PickupAuthorization::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->where('is_active', true)
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
     * Create / Assign Homework (Teacher/Admin)
     */
    public function storeHomework(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        $validator = Validator::make($request->all(), [
            'class_id'   => ['required', \Illuminate\Validation\Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required', \Illuminate\Validation\Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'title'      => 'required|string|max:255',
            'description'=> 'required|string',
            'due_date'   => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $homework = Homework::create([
            'school_id' => $schoolId,
            'class_id' => $request->class_id,
            'subject_id' => $request->subject_id,
            'teacher_id' => $request->user()->id,
            'title' => $request->title,
            'description' => $request->description,
            'due_date' => $request->due_date,
        ]);

        return response()->json([
            'message' => 'Homework assigned successfully',
            'homework' => $homework->load(['subject', 'schoolClass']),
        ], 201);
    }

    /**
     * Register Child Pickup Authorization (Parent)
     */
    public function storePickupAuthorization(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

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
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

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
        if ($request->get('notify_parent', true)) {
            $student = Student::where('school_id', $schoolId)->with('user')->find($request->student_id);
            $parentPhone = ($student && $student->user && $student->user->userProfile) ? $student->user->userProfile->phone : '+2348000000000';
            $whatsApp = app(WhatsAppService::class);
            $whatsApp->sendMessage(
                $parentPhone,
                "Dear Parent,\nA new {$request->type} conduct entry has been logged for " . ($student->user ? $student->user->name : 'your child') . ": '{$request->title}'. Please log into parent portal for details."
            );
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
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

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
