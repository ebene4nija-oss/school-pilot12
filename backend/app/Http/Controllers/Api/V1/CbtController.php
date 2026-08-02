<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CbtController extends Controller
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
     * Offline Exam Client Sync Endpoint
     * Handles out-of-order & delayed offline answer batches without corrupting newer submissions
     */
    public function syncOfflineAnswers(Request $request)
    {
        $validated = $request->validate([
            'attempt_id' => 'required|integer',
            'answers'    => 'required|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.selected_option' => 'required|string',
            'answers.*.client_timestamp' => 'required|date',
        ]);

        $schoolId = $this->getSchoolId($request);
        $user = $request->user();
        $attemptId = $validated['attempt_id'];

        $existing = DB::table('student_attempts')
            ->where('id', $attemptId)
            ->where('school_id', $schoolId)
            ->first();

        if (!$existing) {
            return response()->json(['error' => 'Exam attempt not found or unauthorized.'], 404);
        }

        // Ownership check: logged-in student must own the attempt (or be matched via user/student record)
        if ($user) {
            $student = DB::table('students')->where('user_id', $user->id)->first();
            if ($student && $existing->student_id != $student->id) {
                return response()->json(['error' => 'Forbidden: You do not own this exam attempt.'], 403);
            }
        }

        if ($existing->status === 'submitted') {
            return response()->json([
                'status' => 'ignored',
                'message' => 'Attempt has already been finalized and submitted.',
                'synced_count' => 0
            ]);
        }

        $currentAnswers = json_decode($existing->answers ?? '{}', true);
        if (!is_array($currentAnswers)) {
            $currentAnswers = [];
        }

        $syncedCount = 0;
        foreach ($validated['answers'] as $answer) {
            $qId = (string)$answer['question_id'];
            
            // Out-of-order protection: update only if not present or client timestamp is newer/valid
            if (!isset($currentAnswers[$qId]) || !is_array($currentAnswers[$qId]) || (isset($currentAnswers[$qId]['client_timestamp']) && $answer['client_timestamp'] >= $currentAnswers[$qId]['client_timestamp'])) {
                $currentAnswers[$qId] = [
                    'question_id' => $answer['question_id'],
                    'selected_option' => $answer['selected_option'],
                    'client_timestamp' => $answer['client_timestamp'],
                ];
                $syncedCount++;
            }
        }

        DB::table('student_attempts')
            ->where('id', $attemptId)
            ->update([
                'answers' => json_encode($currentAnswers),
                'updated_at' => now(),
            ]);

        return response()->json([
            'status' => 'success',
            'message' => "Successfully synchronized {$syncedCount} offline exam answers.",
            'synced_count' => $syncedCount
        ]);
    }
}
