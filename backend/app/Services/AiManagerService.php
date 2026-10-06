<?php

namespace App\Services;

use App\Models\School;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AiManagerService
{
    /**
     * Check if AI is globally enabled for a school, and optionally if a specific feature is enabled.
     */
    public function isAiEnabled(?int $schoolId, ?string $featureKey = null): bool
    {
        if (!$schoolId) {
            return false;
        }

        $school = School::find($schoolId);
        if (!$school) {
            return false;
        }

        // Global master AI toggle check
        if (isset($school->ai_enabled) && !$school->ai_enabled) {
            return false;
        }

        // Feature-specific granular toggle check
        if ($featureKey && isset($school->ai_feature_flags)) {
            $flags = $school->ai_feature_flags;
            if (isset($flags[$featureKey]) && !$flags[$featureKey]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get API credentials and parameters (BYO-API Key or System Default)
     */
    public function getApiConfig(?int $schoolId): array
    {
        $defaultKey = config('services.anthropic.api_key');
        $defaultModel = (string) config('services.anthropic.comment_model');

        if (!$schoolId) {
            return ['api_key' => $defaultKey, 'model' => $defaultModel, 'custom_provider' => false];
        }

        $school = School::find($schoolId);
        $customSettings = $school ? ($school->ai_api_settings ?? []) : [];

        return [
            'api_key'         => $customSettings['api_key'] ?? $defaultKey,
            'model'           => $customSettings['model'] ?? $defaultModel,
            'temperature'     => $customSettings['temperature'] ?? 0.7,
            'max_tokens'      => $customSettings['max_tokens'] ?? 300,
            'custom_provider' => !empty($customSettings['api_key']),
        ];
    }

    /**
     * Optional AI: Generate Report Card Comments
     */
    public function generateReportComment(int $schoolId, array $scoreData): string
    {
        if (!$this->isAiEnabled($schoolId, 'report_comments')) {
            throw new Exception("AI report card comment feature is currently disabled by school administration.");
        }

        $config = $this->getApiConfig($schoolId);
        $studentName = $scoreData['student_name'] ?? 'Student';
        $subject = $scoreData['subject'] ?? 'Subject';
        $score = $scoreData['total_score'] ?? 0;

        // Stub only when no key is available at all (BYO-key schools still hit
        // the real API). A missing key with stubbing off raises instead of
        // inventing a remark — see config/services.php → anthropic.stub.
        $hasKey = is_string($config['api_key']) && trim($config['api_key']) !== '';

        if (! $hasKey) {
            if (! config('services.anthropic.stub')) {
                throw new Exception('AI is enabled for this school but no Anthropic API key is configured.');
            }

            return "[STUB] {$studentName} scored {$score}% in {$subject}. This placeholder text was generated without calling the AI provider.";
        }

        // A school on its own BYO key still gets metered and capped: the cap
        // exists to stop a runaway loop, which costs the school just as much
        // when they are the one being billed.
        $ledger = app(AiSpendLedger::class);
        $ledger->assertWithinCap($schoolId);

        $response = Http::withHeaders([
            'x-api-key' => $config['api_key'],
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => $config['model'],
            'max_tokens' => $config['max_tokens'],
            'temperature' => $config['temperature'],
            'messages' => [
                ['role' => 'user', 'content' => "Write a 2-sentence teacher remark for {$studentName} in {$subject} scoring {$score}%."]
            ]
        ]);

        $ledger->record($schoolId, (string) $config['model'], (array) $response->json('usage', []));

        return $response->json('content.0.text') ?? "{$studentName} performed well in {$subject}.";
    }

    /*
     * AI Studio (doc §7.10). These used to return canned text — the same
     * "lesson plan" for every topic and a "strong performance" summary for
     * every child — labelled as AI output. They now ask Claude through
     * ClaudeService (key check, spend cap, ledger), and each result is a
     * draft for the teacher to edit before anything reaches a student.
     */

    public function generateLessonPlan(int $schoolId, string $subject, string $topic, string $classLevel): array
    {
        $this->assertFeature($schoolId, 'lesson_plans', 'lesson plan generation');

        return ['subject' => $subject, 'topic' => $topic, 'class_level' => $classLevel] + app(ClaudeService::class)->teachingJson(
            'Write one lesson plan. JSON keys: "duration_minutes" (number), "objectives" (array of strings, measurable, starting "Students will be able to"), '
            . '"materials" (array of strings, things a Nigerian classroom has), "introduction" (string), "activities" (array of {"title","minutes","description"}), '
            . '"assessment" (string), "homework" (string), "differentiation" (string: support for weaker learners and extension for stronger ones).',
            "Subject: {$subject}\nTopic: {$topic}\nClass: {$classLevel}",
            $schoolId,
            [
                'duration_minutes' => 40,
                'objectives' => ["Students will be able to explain {$topic}."],
                'materials' => ['Whiteboard', 'Exercise books'],
                'introduction' => 'Placeholder introduction.',
                'activities' => [['title' => 'Guided practice', 'minutes' => 20, 'description' => 'Placeholder activity.']],
                'assessment' => 'Placeholder assessment.',
                'homework' => 'Placeholder homework.',
                'differentiation' => 'Placeholder differentiation.',
            ],
        );
    }

    public function generateExamQuestions(int $schoolId, string $subject, string $topic, int $count = 5, ?string $classLevel = null, string $difficulty = 'medium'): array
    {
        $this->assertFeature($schoolId, 'exam_generation', 'question generation');

        $result = app(ClaudeService::class)->teachingJson(
            'Write multiple-choice questions for a CBT paper. Each has exactly four options A–D with one correct answer, plausible distractors, '
            . 'and no "all of the above". Use $…$ for any maths. JSON: {"questions": [{"question","options":{"A","B","C","D"},"correct_answer":"A|B|C|D","topic","difficulty":"easy|medium|hard","explanation"}]}.',
            "Subject: {$subject}\nTopic: {$topic}\nClass: " . ($classLevel ?: 'not given') . "\nDifficulty: {$difficulty}\nNumber of questions: {$count}",
            $schoolId,
            ['questions' => [[
                'question' => "Placeholder question on {$topic}?",
                'options' => ['A' => 'First', 'B' => 'Second', 'C' => 'Third', 'D' => 'Fourth'],
                'correct_answer' => 'A', 'topic' => $topic, 'difficulty' => $difficulty, 'explanation' => 'Placeholder.',
            ]]],
            min(16000, 600 * $count + 1000),
        );

        // Only well-formed questions go back: an answer key that is not one of
        // the options would be saved to the bank and mark every candidate wrong.
        $questions = collect($result['questions'] ?? [])->filter(fn ($q) => is_array($q)
            && ! empty($q['question']) && is_array($q['options'] ?? null) && count($q['options']) >= 2
            && isset($q['options'][$q['correct_answer'] ?? '']))->values()->all();

        return ['subject' => $subject, 'topic' => $topic, 'question_count' => count($questions), 'questions' => $questions, 'stub' => $result['stub'] ?? false];
    }

    public function generateHomeworkIdeas(int $schoolId, string $subject, string $topic, ?string $classLevel = null): array
    {
        $this->assertFeature($schoolId, 'homework_ideas', 'homework ideas');

        return ['subject' => $subject, 'topic' => $topic] + app(ClaudeService::class)->teachingJson(
            'Suggest five varied homework tasks a student can do at home without internet or special materials. '
            . 'JSON: {"ideas": [{"title","instructions","type":"practice|research|project|reflection","minutes":number,"marks":number}]}.',
            "Subject: {$subject}\nTopic: {$topic}\nClass: " . ($classLevel ?: 'not given'),
            $schoolId,
            ['ideas' => [['title' => "Practice on {$topic}", 'instructions' => 'Placeholder instructions.', 'type' => 'practice', 'minutes' => 30, 'marks' => 10]]],
        );
    }

    public function translateContent(int $schoolId, string $text, string $targetLanguage): array
    {
        $this->assertFeature($schoolId, 'translation', 'translation');

        $result = app(ClaudeService::class)->teachingJson(
            'Translate the teacher\'s text faithfully for pupils and parents, keeping numbers, names and any $…$ maths unchanged. '
            . 'Where a term has no common equivalent, keep the English term in brackets. JSON: {"translated_text": "…", "notes": "anything the teacher should check, or empty"}.',
            "Translate into {$targetLanguage}:\n\n{$text}",
            $schoolId,
            ['translated_text' => "[{$targetLanguage}] {$text}", 'notes' => ''],
            min(16000, (int) (mb_strlen($text) * 1.5) + 500),
        );

        return ['original_text' => $text, 'target_language' => $targetLanguage] + $result;
    }

    /**
     * A plain-language summary of one student's released results and
     * attendance, for a teacher preparing for a parent meeting.
     *
     * Data minimisation (NDPA, doc §12): the model is sent subject totals,
     * term averages and an attendance rate — never the child's name,
     * admission number, health record or guardians. Only released terms.
     */
    public function generatePerformanceSummary(int $schoolId, int $studentId): array
    {
        $this->assertFeature($schoolId, 'performance_summaries', 'performance summaries');

        $student = \App\Models\Student::where('school_id', $schoolId)->with('currentClass:id,name')->findOrFail($studentId);
        $terms = \App\Models\Term::where('school_id', $schoolId)->orderBy('start_date')->get()
            ->filter(fn ($t) => \App\Models\ResultRelease::isReleasedFor($schoolId, $t->id, $student->class_id))->values();
        $scores = \App\Models\ScoreEntry::where('student_id', $student->id)->whereIn('term_id', $terms->pluck('id'))->with('subject:id,name')->get()->groupBy('term_id');

        if ($scores->isEmpty()) {
            throw new Exception('This student has no released results to summarise yet.');
        }

        $lines = $terms->filter(fn ($t) => $scores->has($t->id))->map(fn ($t) => $t->name . ': '
            . $scores[$t->id]->map(fn ($s) => ($s->subject?->name ?? 'Subject') . ' ' . ($s->total_score ?? '—'))->implode(', '))->implode("\n");
        $records = \App\Models\AttendanceRecord::where('student_id', $student->id)->get(['status']);
        $attendance = $records->count() ? round($records->whereIn('status', ['present', 'late'])->count() / $records->count() * 100) . '% of ' . $records->count() . ' days' : 'not recorded';

        return ['student_id' => $student->id] + app(ClaudeService::class)->teachingJson(
            'Summarise a student\'s progress for their teacher, to discuss with the parent. Refer to them only as "the student". '
            . 'Be specific to the numbers, honest about weaknesses, and encouraging. Do not speculate about home life, health or causes. '
            . 'JSON: {"summary": "3–4 sentences", "strengths": [strings], "concerns": [strings], "recommendations": [strings, practical next steps]}.',
            "Class: " . ($student->currentClass?->name ?? 'unknown') . "\nScores by term (out of 100):\n{$lines}\nAttendance: {$attendance}",
            $schoolId,
            ['summary' => 'Placeholder summary.', 'strengths' => [], 'concerns' => [], 'recommendations' => []],
        );
    }

    private function assertFeature(int $schoolId, string $key, string $label): void
    {
        if (! $this->isAiEnabled($schoolId, $key)) {
            throw new Exception("AI {$label} is switched off by your school administrator.");
        }
    }
}
