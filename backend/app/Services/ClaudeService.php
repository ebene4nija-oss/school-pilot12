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

    /**
     * One teaching-resource request whose answer is a JSON object (AI Studio,
     * doc §7.10): lesson plans, question drafts, homework ideas, translations,
     * performance summaries.
     *
     * Everything here is a draft for a teacher to read, edit and choose to
     * use. In stub mode (tests, local work without a key) it returns the
     * caller's `$stub`, flagged `stub: true`, so a placeholder can never pass
     * for the model's work; with no key and no stub mode it fails loudly, as
     * `request()` does.
     *
     * @param  array<string, mixed>  $stub
     * @return array<string, mixed>
     */
    public function teachingJson(string $instructions, string $prompt, ?int $schoolId, array $stub, int $maxTokens = 4000): array
    {
        if ($this->shouldStub()) {
            return $stub + ['stub' => true];
        }

        $system = "You are an experienced teacher in a Nigerian private school (Nursery, Primary, JSS, SS), "
            . "working to the Nigerian curriculum with WAEC/NECO and BECE in view. Use British English and Naira (₦) for money. "
            . "Write for the class level given, plainly, with no filler. {$instructions} "
            . 'Reply with a single JSON object only — no prose before or after it, no Markdown fences.';

        $response = $this->request((string) config('services.anthropic.document_model'), $prompt, $maxTokens, $schoolId, $system);
        $text = trim((string) $response->json('content.0.text'));
        // Tolerate a fenced reply rather than fail a teacher over formatting.
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The AI reply could not be read. Please try again.');
        }

        if ($response->json('stop_reason') === 'max_tokens') {
            throw new RuntimeException('The AI reply was cut short. Ask for less at once and try again.');
        }

        return $decoded;
    }

    private function shouldStub(): bool
    {
        return $this->stub && ! $this->hasKey();
    }

    private function hasKey(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    private function request(string $model, string $prompt, int $maxTokens, ?int $schoolId = null, ?string $system = null)
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
            ->post($this->baseUrl, array_filter([
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ], fn ($v) => $v !== null));

        if ($response->failed()) {
            throw new RuntimeException('Claude API call failed: ' . $response->body());
        }

        // A declined request is a 200 with no usable text; say so rather than
        // hand an empty string to a teacher as if it were the answer.
        if ($response->json('stop_reason') === 'refusal') {
            throw new RuntimeException('The AI declined this request. Rephrase it and try again.');
        }

        // Metered after the fact from the response's own usage object, rather
        // than estimated from the prompt — an estimate drifts from the invoice.
        app(AiSpendLedger::class)->record($schoolId, $model, (array) $response->json('usage', []));

        return $response;
    }
}
