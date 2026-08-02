<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ParentalConsent;
use App\Models\Student;
use App\Models\MessageThread;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use ZipArchive;

class AddedFeaturesController extends Controller
{
    private function getSchoolId(Request $request)
    {
        if ($request->user() && $request->user()->userProfile && $request->user()->userProfile->school_id) {
            return $request->user()->userProfile->school_id;
        }

        $tenant = $request->attributes->get('tenant_school');
        if ($tenant) {
            return $tenant->id;
        }

        return $request->attributes->get('school_id');
    }

    /**
     * Record NDPA Parental Consent
     */
    public function recordParentalConsent(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validated = $request->validate([
            'guardian_id' => ['required', \Illuminate\Validation\Rule::exists('guardians', 'id')->where('school_id', $schoolId)],
            'student_id'  => ['required', \Illuminate\Validation\Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'consent_given' => 'required|boolean',
            'ai_cross_border_consent_given' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        $consent = ParentalConsent::updateOrCreate(
            [
                'school_id' => $schoolId,
                'guardian_id' => $validated['guardian_id'],
                'student_id' => $validated['student_id'],
            ],
            [
                'consent_given' => $validated['consent_given'],
                'ai_cross_border_consent_given' => $validated['ai_cross_border_consent_given'] ?? true,
                'ip_address' => $request->ip(),
                'consented_at' => now(),
                'withdrawn_at' => $validated['consent_given'] ? null : now(),
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Parental consent recorded successfully under NDPA provisions',
            'data' => $consent
        ], 200);
    }

    /**
     * Withdraw NDPA Parental Consent
     */
    public function withdrawParentalConsent(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);

        $consent = ParentalConsent::where('school_id', $schoolId)->findOrFail($id);
        $consent->update([
            'consent_given' => false,
            'withdrawn_at' => now(),
        ]);

        return response()->json([
            'message' => 'Parental consent withdrawn successfully',
            'data' => $consent
        ]);
    }

    /**
     * One-Click School Data Export (Data Portability)
     */
    public function exportSchoolData(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $students = Student::where('school_id', $schoolId)->with(['user', 'class', 'arm'])->get();

        $data = [
            'export_timestamp' => now()->toIso8601String(),
            'school_id' => $schoolId,
            'total_students' => $students->count(),
            'students' => $students->toArray(),
        ];

        return response()->json([
            'status' => 'success',
            'message' => 'School data export archive created',
            'export' => $data
        ]);
    }

    /**
     * Teacher-Parent Direct In-App Messaging
     */
    public function getThreads(Request $request)
    {
        $schoolId = $this->getSchoolId($request);
        $userId = $request->user()->id;

        $threads = MessageThread::where('school_id', $schoolId)
            ->where(function ($query) use ($userId) {
                $query->where('teacher_id', $userId)
                      ->orWhere('parent_id', $userId);
            })
            ->with(['teacher', 'parent', 'student', 'messages' => function ($q) {
                $q->latest()->limit(1);
            }])
            ->latest('last_message_at')
            ->get();

        return response()->json(['data' => $threads]);
    }

    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'student_id'   => 'nullable|exists:students,id',
            'subject'      => 'nullable|string|max:255',
            'body'         => 'required|string',
            'thread_id'    => 'nullable|exists:message_threads,id',
        ]);

        $schoolId = $this->getSchoolId($request);
        $senderId = $request->user()->id;

        if (!empty($validated['thread_id'])) {
            $thread = MessageThread::where('school_id', $schoolId)->findOrFail($validated['thread_id']);
        } else {
            $thread = MessageThread::create([
                'school_id'       => $schoolId,
                'teacher_id'      => $senderId,
                'parent_id'       => $validated['recipient_id'],
                'student_id'      => $validated['student_id'] ?? null,
                'subject'         => $validated['subject'] ?? 'Direct Message',
                'last_message_at' => now(),
            ]);
        }

        $message = Message::create([
            'thread_id' => $thread->id,
            'sender_id' => $senderId,
            'body'      => $validated['body'],
        ]);

        $thread->update(['last_message_at' => now()]);

        return response()->json([
            'message' => 'Message sent successfully',
            'data'    => $message->load('sender')
        ], 201);
    }
}
