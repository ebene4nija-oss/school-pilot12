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
        $schoolId = $this->schoolId();
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
            'ai_api_settings.provider'     => 'nullable|in:anthropic,openai,gemini',
            'ai_api_settings.model'        => 'nullable|string',
            'ai_api_settings.temperature'  => 'nullable|numeric|min:0|max:1',
            'ai_api_settings.max_tokens'   => 'nullable|integer|min:50|max:4000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        /*
         * `ai_enabled` is SchoolPilot's switch (PlatformSettingsController::
         * setSchoolAi). A school admin could set it here too, which let a
         * school turn back on AI the operator had switched off. Schools choose
         * individual features instead (PUT /school/ai-features).
         */
        if ($request->has('ai_enabled') && $request->user()?->userProfile?->role !== 'super_admin') {
            return response()->json([
                'message' => 'Only SchoolPilot can switch AI on or off for a school. Choose individual AI features instead.',
                'errors' => ['ai_enabled' => ['Only SchoolPilot can switch AI on or off for a school.']],
            ], 422);
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

        $schoolId = $this->schoolId();
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
            'subject' => 'required|string|max:100',
            'topic'   => 'required|string|max:255',
            'count'   => 'nullable|integer|min:1|max:20',
            'class_level' => 'nullable|string|max:50',
            'difficulty' => 'nullable|in:easy,medium,hard',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->schoolId();
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $exam = $aiManager->generateExamQuestions($schoolId, $request->subject, $request->topic, (int) $request->get('count', 5), $request->input('class_level'), $request->input('difficulty', 'medium'));
            return response()->json(['message' => 'Exam questions generated', 'exam' => $exam]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function generateHomework(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:100',
            'topic'   => 'required|string|max:255',
            'class_level' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->schoolId();
        $aiManager = app(\App\Services\AiManagerService::class);

        try {
            $ideas = $aiManager->generateHomeworkIdeas($schoolId, $request->subject, $request->topic, $request->input('class_level'));
            return response()->json(['message' => 'Homework ideas generated', 'homework_ideas' => $ideas]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function translate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'text'            => 'required|string|max:8000',
            'target_language' => 'required|string|in:Yoruba,Igbo,Hausa,Pidgin,French,English',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->schoolId();
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
        $schoolId = $this->schoolId();
        $aiManager = app(\App\Services\AiManagerService::class);

        // Only a student this teacher may see (StudentPolicy): a teacher sees
        // the classes they teach, and this sends the child's marks to the AI.
        $student = \App\Models\Student::where('school_id', $schoolId)->findOrFail((int) $studentId);
        $this->authorize('view', $student);

        try {
            $summary = $aiManager->generatePerformanceSummary($schoolId, (int) $studentId);
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
            // "practice": the tutor sets questions to try rather than explaining (TASK 15).
            'mode' => 'nullable|in:chat,practice',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $student = Student::where('user_id', $request->user()->id)->first();

        if (! $student) {
            return response()->json(['error' => 'Only enrolled students can use the tutor.'], 403);
        }

        // The school's rules first, before a conversation exists or a token is spent.
        $subjectId = $request->filled('conversation_id')
            ? TutorConversation::where('student_id', $student->id)->whereKey($request->integer('conversation_id'))->value('subject_id')
            : ($request->filled('subject_id') ? $request->integer('subject_id') : null);
        try {
            app(\App\Services\TutorPolicy::class)->assertMayAsk($student, $subjectId ? (int) $subjectId : null);
        } catch (\App\Exceptions\DomainRefusal $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        $conversation = $this->resolveConversation($request, $student);

        $message = $request->input('mode') === 'practice'
            ? 'Set me five practice questions on this, one at a time. Wait for my answer to each before giving feedback, and do not show the answers first: '.$request->input('message')
            : $request->input('message');

        try {
            $result = $tutor->reply($conversation, $student, $message);
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

    // ------------------------------------------------------------------
    // AI with pictures, for CBT. Drafts only: the teacher reviews, and
    // saving goes through the question bank's own checks.
    // ------------------------------------------------------------------

    /** Questions about a picture already in this school's CBT media library. */
    public function questionsFromImage(Request $request)
    {
        $data = $request->validate([
            'asset_id' => 'required|integer',
            'subject' => 'required|string|max:100',
            'topic' => 'nullable|string|max:255',
            'count' => 'nullable|integer|min:1|max:10',
            'class_level' => 'nullable|string|max:50',
            'difficulty' => 'nullable|in:easy,medium,hard',
        ]);
        $asset = \App\Models\CbtMediaAsset::where('school_id', $this->schoolId())->findOrFail($data['asset_id']);

        return $this->aiCall(fn ($ai) => $ai->questionsFromImage(
            $this->schoolId(), $asset, $data['subject'], $data['topic'] ?? null, (int) ($data['count'] ?? 5), $data['class_level'] ?? null, $data['difficulty'] ?? 'medium'
        ));
    }

    /** Photographs of a printed paper (up to six pages), read into draft questions. */
    public function scanQuestionPaper(Request $request)
    {
        $request->validate([
            'pages' => 'required|array|min:1|max:6',
            'pages.*' => 'file|mimes:jpg,jpeg,png,webp|max:6144',
            'subject' => 'required|string|max:100',
            'class_level' => 'nullable|string|max:50',
        ]);
        $pages = array_map(fn ($f) => ['mime' => $f->getMimeType(), 'bytes' => (string) file_get_contents($f->getRealPath())], $request->file('pages'));

        return $this->aiCall(fn ($ai) => $ai->scanQuestionPaper($this->schoolId(), $pages, $request->input('subject'), $request->input('class_level')));
    }

    /** A suggested alt text and caption for an image before it is uploaded. */
    public function describeImage(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:5120',
            'subject' => 'nullable|string|max:100',
        ]);
        $file = $request->file('image');

        return $this->aiCall(fn ($ai) => $ai->describeImage($this->schoolId(), (string) file_get_contents($file->getRealPath()), (string) $file->getMimeType(), $request->input('subject')));
    }

    /** Runs an AI call, turning "switched off" and provider failures into a plain message. */
    private function aiCall(callable $call)
    {
        try {
            return response()->json($call(app(\App\Services\AiManagerService::class)));
        } catch (\App\Services\Ai\AiUnavailableException $e) {
            return response()->json(['message' => 'AI is not set up for this school yet. An administrator adds an AI key in Settings.'], 503);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], str_contains($e->getMessage(), 'switched off') ? 403 : 422);
        }
    }
}

