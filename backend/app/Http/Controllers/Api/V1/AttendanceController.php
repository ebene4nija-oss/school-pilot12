<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
    public function generateQrToken(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Generate short-lived QR token (valid for 15 minutes)
        $tokenPayload = [
            'school_id' => $schoolId,
            'class_id' => $request->class_id,
            'term_id' => $request->term_id,
            'expires_at' => now()->addMinutes(15)->timestamp,
            'nonce' => bin2hex(random_bytes(8)),
        ];

        $token = base64_encode(json_encode($tokenPayload));

        return response()->json([
            'qr_token' => $token,
            'expires_in_seconds' => 900,
        ]);
    }

    public function markStudentAttendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'term_id' => 'required|exists:terms,id',
            'date' => 'required|date',
            'status' => 'required|in:present,absent,late,excused',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $record = AttendanceRecord::updateOrCreate(
            [
                'school_id' => $schoolId,
                'student_id' => $request->student_id,
                'date' => $request->date,
            ],
            [
                'term_id' => $request->term_id,
                'status' => $request->status,
            ]
        );

        return response()->json([
            'message' => 'Student attendance marked successfully',
            'attendance' => $record,
        ]);
    }

    public function staffGpsClockIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'staff_id' => 'required|exists:staff,id',
            'term_id' => 'required|exists:terms,id',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $record = AttendanceRecord::create([
            'school_id' => $schoolId,
            'staff_id' => $request->staff_id,
            'term_id' => $request->term_id,
            'date' => now()->toDateString(),
            'status' => 'present',
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'action' => 'staff.gps_clockin',
            'auditable_type' => AttendanceRecord::class,
            'auditable_id' => $record->id,
            'new_values' => ['lat' => $request->latitude, 'lng' => $request->longitude],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Staff GPS clock-in recorded successfully',
            'record' => $record,
        ]);
    }
}
