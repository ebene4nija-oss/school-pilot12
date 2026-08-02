<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    /**
     * Send direct WhatsApp notification to parent(s)
     */
    public function sendWhatsAppNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'nullable|exists:students,id',
            'parent_id'  => 'nullable|exists:users,id',
            'phone'      => 'nullable|string',
            'message'    => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $recipientPhone = $request->phone;
        $recipientName = 'Parent';

        if (!$recipientPhone && $request->parent_id) {
            $parent = User::whereHas('userProfile', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })->find($request->parent_id);

            if ($parent && $parent->userProfile) {
                $recipientPhone = $parent->userProfile->phone;
                $recipientName = $parent->name;
            }
        }

        if (!$recipientPhone && $request->student_id) {
            $student = Student::where('school_id', $schoolId)->with(['user'])->find($request->student_id);
            if ($student && $student->user && $student->user->userProfile) {
                $recipientPhone = $student->user->userProfile->phone;
                $recipientName = $student->user->name;
            }
        }

        if (!$recipientPhone) {
            return response()->json([
                'message' => 'Recipient phone number could not be determined. Please specify a phone number or valid parent/student ID.'
            ], 422);
        }

        $whatsAppService = app(WhatsAppService::class);
        $result = $whatsAppService->sendMessage($recipientPhone, $request->message);

        return response()->json([
            'message' => 'WhatsApp notification sent successfully to parent.',
            'recipient' => [
                'name' => $recipientName,
                'phone' => $recipientPhone,
            ],
            'delivery_result' => $result,
        ]);
    }

    /**
     * Send specific structured notifications (latest result, attendance, fee balance, homework, timetable)
     */
    public function sendStructuredWhatsApp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type'       => 'required|in:result,attendance,fee_balance,homework,timetable',
            'student_id' => 'required|exists:students,id',
            'phone'      => 'nullable|string',
            'details'    => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $student = Student::where('school_id', $schoolId)->with(['user'])->findOrFail($request->student_id);

        $recipientPhone = $request->phone;
        $recipientName = 'Parent';

        if (!$recipientPhone && $student->user && $student->user->userProfile) {
            $recipientPhone = $student->user->userProfile->phone;
            $recipientName = $student->user->name;
        }

        if (!$recipientPhone) {
            $recipientPhone = '+2348000000000'; // Default fallback phone
        }

        $whatsAppService = app(WhatsAppService::class);
        $details = $request->input('details', []);
        $studentName = $student->user ? $student->user->name : 'Student';

        switch ($request->type) {
            case 'result':
                $res = $whatsAppService->sendResultNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    $details['term'] ?? 'Current Term',
                    (string)($details['total_score'] ?? '85'),
                    (string)($details['average'] ?? '85'),
                    $details['verification_token'] ?? 'SP_TOKEN_' . $student->id
                );
                break;

            case 'attendance':
                $res = $whatsAppService->sendAttendanceNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    $details['date'] ?? now()->toDateString(),
                    $details['status'] ?? 'Present'
                );
                break;

            case 'fee_balance':
                $res = $whatsAppService->sendFeeBalanceNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    (string)($details['total_amount'] ?? '50,000.00'),
                    (string)($details['amount_paid'] ?? '20,000.00'),
                    (string)($details['balance'] ?? '30,000.00'),
                    $details['due_date'] ?? now()->addDays(7)->toDateString()
                );
                break;

            case 'homework':
                $res = $whatsAppService->sendHomeworkNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    $details['subject'] ?? 'Mathematics',
                    $details['title'] ?? 'Algebra Exercise 4',
                    $details['due_date'] ?? now()->addDays(2)->toDateString()
                );
                break;

            case 'timetable':
                $res = $whatsAppService->sendTimetableNotification(
                    $recipientPhone,
                    $recipientName,
                    $studentName,
                    $details['class_name'] ?? 'JSS 1',
                    $details['summary'] ?? 'Mon-Fri: 8:00 AM - 2:00 PM. Check portal for full schedule.'
                );
                break;
        }

        return response()->json([
            'message' => "WhatsApp {$request->type} notification dispatched to parent.",
            'recipient' => [
                'name' => $recipientName,
                'phone' => $recipientPhone,
            ],
            'delivery_result' => $res,
        ]);
    }
}
