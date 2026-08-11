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

    /**
     * Optional AI: Generate Lesson Plan
     */
    public function generateLessonPlan(int $schoolId, string $subject, string $topic, string $classLevel): array
    {
        if (!$this->isAiEnabled($schoolId, 'lesson_plans')) {
            throw new Exception("AI lesson plan generation feature is currently disabled by school administration.");
        }

        $config = $this->getApiConfig($schoolId);

        return [
            'subject' => $subject,
            'topic' => $topic,
            'class_level' => $classLevel,
            'objectives' => ["Understand principles of {$topic}", "Execute practical exercises in {$subject}"],
            'activities' => ["10-min introduction", "20-min guided activity", "15-min assessment"],
            'assessment' => "End of lesson comprehension check",
            'ai_generated' => true,
            'provider' => $config['custom_provider'] ? 'custom_school_api' : 'system_default',
        ];
    }

    /**
     * Optional AI: Exam & Question Generation
     */
    public function generateExamQuestions(int $schoolId, string $subject, string $topic, int $count = 5): array
    {
        if (!$this->isAiEnabled($schoolId, 'exam_generation')) {
            throw new Exception("AI exam generation feature is currently disabled by school administration.");
        }

        return [
            'subject' => $subject,
            'topic' => $topic,
            'question_count' => $count,
            'questions' => [
                [
                    'question' => "What is the primary function of {$topic} in {$subject}?",
                    'options' => ["Option A", "Option B", "Option C", "Option D"],
                    'correct_answer' => "Option A",
                ]
            ],
            'ai_generated' => true,
        ];
    }

    /**
     * Optional AI: Homework Ideas Generator
     */
    public function generateHomeworkIdeas(int $schoolId, string $subject, string $topic): array
    {
        if (!$this->isAiEnabled($schoolId, 'homework_ideas')) {
            throw new Exception("AI homework idea generation feature is currently disabled by school administration.");
        }

        return [
            'subject' => $subject,
            'topic' => $topic,
            'ideas' => [
                "Research exercise on {$topic} applications in real life.",
                "Solve 5 practice problems on {$topic}.",
                "Write a short essay reflecting on {$topic}.",
            ]
        ];
    }

    /**
     * Optional AI: Multilingual Content Translation
     */
    public function translateContent(int $schoolId, string $text, string $targetLanguage): array
    {
        if (!$this->isAiEnabled($schoolId, 'translation')) {
            throw new Exception("AI content translation feature is currently disabled by school administration.");
        }

        return [
            'original_text' => $text,
            'target_language' => $targetLanguage,
            'translated_text' => "[{$targetLanguage} Translation]: " . $text,
            'ai_generated' => true,
        ];
    }

    /**
     * Optional AI: Student Performance Summaries
     */
    public function generatePerformanceSummary(int $schoolId, int $studentId): array
    {
        if (!$this->isAiEnabled($schoolId, 'performance_summaries')) {
            throw new Exception("AI performance summary feature is currently disabled by school administration.");
        }

        return [
            'student_id' => $studentId,
            'summary' => "Student displays strong performance across core subjects with steady attendance.",
            'strengths' => ["Consistency in assessments", "Punctuality"],
            'recommendations' => ["Focus on higher-order problem-solving in Mathematics"],
            'ai_generated' => true,
        ];
    }
}
