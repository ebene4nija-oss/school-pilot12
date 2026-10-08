<?php

namespace App\Services;

use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The product's AI writing tasks. The name predates other providers: requests
 * now go through AiGateway, which sends them to Claude, ChatGPT or Gemini
 * depending on what SchoolPilot and the school have chosen.
 */
class ClaudeService
{
    public function __construct(private AiGateway $gateway)
    {
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
        $name = self::firstName((string) ($scoreData['student_name'] ?? ''));

        return Cache::remember($cacheKey, 86400, function () use ($scoreData, $name) {
            if ($this->gateway->shouldStub(isset($scoreData['school_id']) ? (int) $scoreData['school_id'] : null)) {
                return "[STUB] {$name} scored {$scoreData['total_score']}% in {$scoreData['subject']}. This placeholder text was generated without calling the AI provider.";
            }

            return self::withName($this->request(
                'comment',
                self::remarkPrompt((string) $scoreData['subject'], $scoreData['total_score']),
                150,
                isset($scoreData['school_id']) ? (int) $scoreData['school_id'] : null
            )->text, $name);
        });
    }

    /**
     * The remark prompt. Data minimisation (NDPA, doc §12): the provider is
     * outside Nigeria and gets the subject and score only — never the
     * child's name. The model writes [NAME] and the name goes in here.
     */
    public static function remarkPrompt(string $subject, mixed $score): string
    {
        return "Write a short, encouraging, 2-sentence teacher report card remark for a student in {$subject} who scored {$score}%. "
            . 'Refer to the student as [NAME] — do not invent a name, and use [NAME] at most once.';
    }

    /** Put the child's first name where the model wrote [NAME]. */
    public static function withName(string $remark, string $firstName): string
    {
        return trim(str_ireplace(['[NAME]', '[name]'], $firstName !== '' ? $firstName : 'This student', $remark));
    }

    public static function firstName(string $fullName): string
    {
        return (string) (preg_split('/\s+/', trim($fullName))[0] ?? '');
    }

    /**
     * Generate a lesson plan / worksheet skeleton.
     */
    public function generateLessonPlan(string $subject, string $topic, string $classLevel, ?int $schoolId = null): array
    {
        $cacheKey = 'lesson_plan_' . ($schoolId ?? 'shared') . '_' . md5("{$subject}_{$topic}_{$classLevel}");

        return Cache::remember($cacheKey, 172800, function () use ($subject, $topic, $classLevel, $schoolId) {
            if ($this->gateway->shouldStub($schoolId)) {
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

            return json_decode($this->request('document', $prompt, 1500, $schoolId)->text, true) ?? [];
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
        if ($this->gateway->shouldStub($schoolId)) {
            return $stub + ['stub' => true];
        }

        $system = "You are an experienced teacher in a Nigerian private school (Nursery, Primary, JSS, SS), "
            . "working to the Nigerian curriculum with WAEC/NECO and BECE in view. Use British English and Naira (₦) for money. "
            . "Write for the class level given, plainly, with no filler. {$instructions} "
            . 'Reply with a single JSON object only — no prose before or after it, no Markdown fences.';

        $reply = $this->request('document', $prompt, $maxTokens, $schoolId, $system);

        if ($reply->stop === 'max_tokens') {
            throw new RuntimeException('The AI reply was cut short. Ask for less at once and try again.');
        }

        $text = trim($reply->text);
        // Tolerate a fenced reply rather than fail a teacher over formatting.
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The AI reply could not be read. Please try again.');
        }

        return $decoded;
    }

    private function request(string $purpose, string $prompt, int $maxTokens, ?int $schoolId = null, ?string $system = null): Ai\AiReply
    {
        return $this->gateway->complete($schoolId, $purpose, $system, [['role' => 'user', 'content' => $prompt]], $maxTokens);
    }
}
