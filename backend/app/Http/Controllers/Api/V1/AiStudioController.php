<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentTopicMastery;
use App\Models\Subject;
use App\Models\TutorConversation;
use App\Services\TutorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AiStudioController extends Controller
{
    /**
     * Manage School AI Settings (Master Toggle, Feature Flags, BYO API Credentials)
     */
    public function updateAiSettings(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $school = \App\Models\School::findOrFail($schoolId);

        $validator = Validator::make($request->all(), [
            'ai_enabled'       => 'nullable|boolean',
            'ai_feature_flags' => 'nullable|array',
            'ai_feature_flags.report_comments'   => 'nullable|boolean',
            'ai_feature_flags.lesson_plans'      => 'nullable|boolean',
            'ai_feature_flags.exam_generation'   => 'nullable|boolean',
            'ai_feature_flags.homework_ideas'    => 'nullable|boolean',
            'ai_feature_flags.translation'       => 'nullable|boolean',
            'ai_feature_flags.performance_summaries' => 'nullable|boolean',
            'ai_feature_flags.tutor'             => 'nullable|boolean',
            'ai_api_settings'  => 'nullable|array',
            'ai_api_settings.api_key'      => 'nullable|string',
            'ai_api_settings.model'        => 'nullable|string',
            'ai_api_settings.temperature'  => 'nullable|numeric|min:0|max:1',
            'ai_api_settings.max_tokens'   => 'nullable|integer|min:50|max:4000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $school->update([
            'ai_enabled' => $request->has('ai_enabled') ? $request->ai_enabled : $school->ai_enabled,
            'ai_feature_flags' => array_merge($school->ai_feature_flags ?? [
                'report_comments' => true,
                'lesson_plans' => true,
                'exam_generation' => true,
                'homework_ideas' => true,
                'translation' => true,
                'performance_summaries' => true,
            ], $request->input('ai_feature_flags', [])),
            'ai_api_settings' => array_merge($school->ai_api_settings ?? [], $request->input('ai_api_settings', [])),
        ]);

        return response()->json([
            'message' => 'School AI configuration and feature flags updated successfully.',
            'school_ai_config' => [
                'ai_enabled' => $school->ai_enabled,
                'ai_feature_flags' => $school->ai_feature_flags,
                'custom_api_configured' => !empty($school->ai_api_settings['api_key']),
            ]
        ]);
    }

    public function generateLessonPlan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string',
            'topic' => 'required|string',
            'class_level' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $plan = $aiManager->generateLessonPlan($schoolId, $request->subject, $request->topic, $request->class_level);
            return response()->json(['message' => 'Lesson plan generated', 'lesson_plan' => $plan]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function generateExams(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string',
            'topic'   => 'required|string',
            'count'   => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $exam = $aiManager->generateExamQuestions($schoolId, $request->subject, $request->topic, $request->get('count', 5));
            return response()->json(['message' => 'Exam questions generated', 'exam' => $exam]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function generateHomework(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string',
            'topic'   => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $ideas = $aiManager->generateHomeworkIdeas($schoolId, $request->subject, $request->topic);
            return response()->json(['message' => 'Homework ideas generated', 'homework_ideas' => $ideas]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function translate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'text'            => 'required|string',
            'target_language' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $translation = $aiManager->translateContent($schoolId, $request->text, $request->target_language);
            return response()->json(['message' => 'Content translated', 'translation' => $translation]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function summarizePerformance(Request $request, $studentId)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $summary = $aiManager->generatePerformanceSummary($schoolId, $studentId);
            return response()->json(['message' => 'Performance summary generated', 'summary' => $summary]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    /**
     * AI Learning Hub tutor (§7.11).
     *
     * Was a hardcoded reply returned to every student for every message, with
     * no LLM call and no persistence. Now runs through TutorService, which
     * grounds the prompt in this child's own subjects, homework and weak
     * topics — the "tied to what the student's own teacher actually assigned"
     * requirement — and keeps the transcript.
     */
    public function tutorChat(Request $request, TutorService $tutor)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|integer',
            'subject_id' => 'nullable|integer',
            'homework_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $student = Student::where('user_id', $request->user()->id)->first();

        if (! $student) {
            return response()->json(['error' => 'Only enrolled students can use the tutor.'], 403);
        }

        $conversation = $this->resolveConversation($request, $student);

        try {
            $result = $tutor->reply($conversation, $student, $request->input('message'));
        } catch (\RuntimeException $e) {
            // 503 rather than 500: the tutor being switched off or
            // unconfigured is an expected state, not a crash.
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'response' => $result['reply'],
            // Kept for the existing mobile/web clients that read this field.
            'is_guided' => true,
            'was_redirected' => $result['was_redirected'],
        ]);
    }

    /**
     * Continue a conversation the student owns, or open a new one. Ownership
     * is checked explicitly: a conversation id is guessable, and a transcript
     * is a record of a named child's schoolwork.
     */
    private function resolveConversation(Request $request, Student $student): TutorConversation
    {
        if ($request->filled('conversation_id')) {
            return TutorConversation::where('student_id', $student->id)
                ->findOrFail($request->integer('conversation_id'));
        }

        $subject = $request->filled('subject_id')
            ? Subject::where('school_id', $student->school_id)->find($request->integer('subject_id'))
            : null;

        return TutorConversation::create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'subject_id' => $subject?->id,
            'homework_id' => $request->integer('homework_id') ?: null,
            'title' => $subject?->name ?? Str::limit($request->input('message'), 60),
            'last_message_at' => now(),
            'message_count' => 0,
        ]);
    }

    /** The student's own conversation list. */
    public function tutorConversations(Request $request)
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        if (! $student) {
            return response()->json(['error' => 'Only enrolled students can use the tutor.'], 403);
        }

        return response()->json(
            TutorConversation::where('student_id', $student->id)
                ->with('subject:id,name')
                ->orderByDesc('last_message_at')
                ->paginate(min((int) $request->input('per_page', 20), 50))
        );
    }

    /** Full transcript of one conversation. */
    public function tutorConversation(Request $request, $id)
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        if (! $student) {
            return response()->json(['error' => 'Only enrolled students can use the tutor.'], 403);
        }

        $conversation = TutorConversation::where('student_id', $student->id)
            ->with(['messages', 'subject:id,name'])
            ->findOrFail($id);

        return response()->json(['conversation' => $conversation]);
    }

    /**
     * Topic mastery (§7.11) — what this student is strong and weak on,
     * measured from graded work rather than self-report.
     */
    public function tutorMastery(Request $request)
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        if (! $student) {
            return response()->json(['error' => 'Only enrolled students can use the tutor.'], 403);
        }

        $rows = StudentTopicMastery::where('student_id', $student->id)
            ->with('subject:id,name')
            ->orderByDesc('mastery_percentage')
            ->get()
            ->map(fn (StudentTopicMastery $row) => [
                'subject' => $row->subject?->name,
                'topic' => $row->topic,
                'questions_attempted' => $row->questions_attempted,
                'questions_correct' => $row->questions_correct,
                'mastery_percentage' => (float) $row->mastery_percentage,
                'tutor_sessions' => $row->tutor_sessions,
                'last_practised_at' => $row->last_practised_at?->toIso8601String(),
            ]);

        return response()->json([
            'mastered' => $rows->where('mastery_percentage', '>=', 75)->values(),
            'developing' => $rows->whereBetween('mastery_percentage', [50, 74.99])->values(),
            'needs_work' => $rows->where('mastery_percentage', '<', 50)->values(),
        ]);
    }
}
