<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Exception;

class ClaudeService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-ant-mock-key'));
    }

    /**
     * Generate AI Report Card Comment using Claude Haiku (cost efficient)
     */
    public function generateReportCardComment(array $scoreData): string
    {
        $cacheKey = 'comment_' . md5(json_encode($scoreData));

        return Cache::remember($cacheKey, 86400, function () use ($scoreData) {
            if ($this->apiKey === 'sk-ant-mock-key') {
                return "{$scoreData['student_name']} has demonstrated commendable effort in {$scoreData['subject']} this term, scoring {$scoreData['total_score']}%. Continued focus on analytical problem-solving is recommended.";
            }

            $prompt = "Write a short, encouraging, 2-sentence teacher report card remark for student {$scoreData['student_name']} in {$scoreData['subject']} who scored {$scoreData['total_score']}%.";

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post($this->baseUrl, [
                'model' => 'claude-3-haiku-20240307',
                'max_tokens' => 150,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ]
            ]);

            if ($response->failed()) {
                throw new Exception('Claude API call failed: ' . $response->body());
            }

            return $response->json('content.0.text');
        });
    }

    /**
     * Generate Lesson Plan / Worksheet using Claude Sonnet with TTL Caching
     */
    public function generateLessonPlan(string $subject, string $topic, string $classLevel): array
    {
        $cacheKey = "lesson_plan_" . md5("{$subject}_{$topic}_{$classLevel}");

        return Cache::remember($cacheKey, 172800, function () use ($subject, $topic, $classLevel) {
            if ($this->apiKey === 'sk-ant-mock-key') {
                return [
                    'subject' => $subject,
                    'topic' => $topic,
                    'class_level' => $classLevel,
                    'objectives' => ["Understand core principles of {$topic}", "Apply {$topic} formulas to solve exercises"],
                    'activities' => ["10-min introduction", "20-min guided practice", "15-min group exercise"],
                    'assessment' => "5-question objective quiz at the end of class",
                ];
            }

            $prompt = "Create a structured JSON lesson plan for subject: {$subject}, topic: {$topic}, class level: {$classLevel}. Return only valid JSON with keys: objectives, activities, assessment.";

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post($this->baseUrl, [
                'model' => 'claude-3-sonnet-20240229',
                'max_tokens' => 500,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ]
            ]);

            if ($response->failed()) {
                throw new Exception('Claude API call failed: ' . $response->body());
            }

            return json_decode($response->json('content.0.text'), true) ?? [];
        });
    }
}
