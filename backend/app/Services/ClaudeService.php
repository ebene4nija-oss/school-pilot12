<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClaudeService
{
    protected ?string $apiKey;
    protected bool $stub;
    protected string $baseUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key');
        $this->stub = (bool) config('services.anthropic.stub');
    }

    /**
     * Generate an AI report card comment.
     *
     * The result always lands in `pending_approval` upstream — a teacher
     * approves or edits before it reaches a report card (see CLAUDE.md).
     */
    public function generateReportCardComment(array $scoreData): string
    {
        // Scoped per school so two schools with a same-named student on the same
        // score do not share a cached remark.
        $tenant = $scoreData['school_id'] ?? 'shared';
        $cacheKey = 'comment_' . $tenant . '_' . md5(json_encode($scoreData));

        return Cache::remember($cacheKey, 86400, function () use ($scoreData) {
            if ($this->shouldStub()) {
                return "[STUB] {$scoreData['student_name']} scored {$scoreData['total_score']}% in {$scoreData['subject']}. This placeholder text was generated without calling the AI provider.";
            }

            $prompt = "Write a short, encouraging, 2-sentence teacher report card remark for student {$scoreData['student_name']} in {$scoreData['subject']} who scored {$scoreData['total_score']}%.";

            $response = $this->request(
                (string) config('services.anthropic.comment_model'),
                $prompt,
                150,
                isset($scoreData['school_id']) ? (int) $scoreData['school_id'] : null
            );

            return (string) $response->json('content.0.text');
        });
    }

    /**
     * Generate a lesson plan / worksheet skeleton.
     */
    public function generateLessonPlan(string $subject, string $topic, string $classLevel, ?int $schoolId = null): array
    {
        $cacheKey = 'lesson_plan_' . md5("{$subject}_{$topic}_{$classLevel}");

        return Cache::remember($cacheKey, 172800, function () use ($subject, $topic, $classLevel, $schoolId) {
            if ($this->shouldStub()) {
                return [
                    'subject' => $subject,
                    'topic' => $topic,
                    'class_level' => $classLevel,
                    'stub' => true,
                    'objectives' => ["Understand core principles of {$topic}", "Apply {$topic} to solve exercises"],
                    'activities' => ['10-min introduction', '20-min guided practice', '15-min group exercise'],
                    'assessment' => '5-question objective quiz at the end of class',
                ];
            }

            $prompt = "Create a structured JSON lesson plan for subject: {$subject}, topic: {$topic}, class level: {$classLevel}. Return only valid JSON with keys: objectives, activities, assessment.";

            $response = $this->request(
                (string) config('services.anthropic.document_model'),
                $prompt,
                1500,
                $schoolId
            );

            return json_decode((string) $response->json('content.0.text'), true) ?? [];
        });
    }

    private function shouldStub(): bool
    {
        return $this->stub && ! $this->hasKey();
    }

    private function hasKey(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    private function request(string $model, string $prompt, int $maxTokens, ?int $schoolId = null)
    {
        if (! $this->hasKey()) {
            // Loud failure. The caller queues or surfaces this; it must never
            // degrade into invented content that looks like a real remark.
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured, so AI generation is unavailable.');
        }

        // Checked before the call, not enforced mid-flight: a school can end
        // fractionally over its cap rather than have a half-written report-card
        // comment truncated. See AiSpendLedger.
        app(AiSpendLedger::class)->assertWithinCap($schoolId);

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            // Without a timeout a stalled call pins a PHP-FPM worker
            // indefinitely, which matters on the connectivity these schools have.
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(2, 500, throw: false)
            ->post($this->baseUrl, [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Claude API call failed: ' . $response->body());
        }

        // Metered after the fact from the response's own usage object, rather
        // than estimated from the prompt — an estimate drifts from the invoice.
        app(AiSpendLedger::class)->record($schoolId, $model, (array) $response->json('usage', []));

        return $response;
    }
}
