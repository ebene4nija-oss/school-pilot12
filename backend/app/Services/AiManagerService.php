<?php

namespace App\Services;

use App\Models\School;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiUnavailableException;
use Exception;

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
     * The provider, key and model a school's AI requests use (AiGateway):
     * its own key if it has one, else its chosen provider, else SchoolPilot's
     * default.
     */
    public function getApiConfig(?int $schoolId): array
    {
        $config = app(AiGateway::class)->resolve($schoolId);
        $own = $schoolId ? (array) (School::find($schoolId)?->ai_api_settings ?? []) : [];

        return [
            'provider'        => $config['provider'],
            'api_key'         => $config['api_key'],
            'model'           => $config['model'],
            'temperature'     => $own['temperature'] ?? 0.7,
            'max_tokens'      => $own['max_tokens'] ?? 300,
            'custom_provider' => $config['own_key'],
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

        $studentName = $scoreData['student_name'] ?? 'Student';
        $subject = $scoreData['subject'] ?? 'Subject';
        $score = $scoreData['total_score'] ?? 0;
        $gateway = app(AiGateway::class);

        // Stub only when no key is available at all. A missing key with
        // stubbing off raises instead of inventing a remark.
        if ($gateway->shouldStub($schoolId)) {
            return "[STUB] {$studentName} scored {$score}% in {$subject}. This placeholder text was generated without calling the AI provider.";
        }

        try {
            // The name stays here; the provider gets subject and score only.
            return ClaudeService::withName($gateway->complete($schoolId, 'comment', null, [
                ['role' => 'user', 'content' => ClaudeService::remarkPrompt($subject, $score)],
            ], (int) ($this->getApiConfig($schoolId)['max_tokens'] ?? 300))->text, ClaudeService::firstName($studentName));
        } catch (AiUnavailableException $e) {
            throw new Exception("AI is enabled for this school but no {$e->vendor()} API key is configured.");
        }
    }

    /*
     * AI Studio (doc §7.10). These used to return canned text — the same
     * "lesson plan" for every topic and a "strong performance" summary for
     * every child — labelled as AI output. They now ask Claude through
     * ClaudeService (key check, spend cap, ledger), and each result is a
     * draft for the teacher to edit before anything reaches a student. The
     * provider behind it may be Claude, ChatGPT or Gemini (AiGateway).
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

    /**
     * Questions about a picture from the school's CBT media library (a
     * diagram, map, graph, photograph). Each comes back with that picture
     * attached in the stem, as a draft: the teacher reviews before anything
     * enters the bank.
     */
    public function questionsFromImage(int $schoolId, \App\Models\CbtMediaAsset $asset, string $subject, ?string $topic = null, int $count = 5, ?string $classLevel = null, string $difficulty = 'medium'): array
    {
        $this->assertFeature($schoolId, 'exam_generation', 'question generation');

        $bytes = \Illuminate\Support\Facades\Storage::disk($asset->disk ?: 'public')->get($asset->path);
        if (! $bytes) {
            throw new Exception('That image could not be read from the media library.');
        }

        $result = app(ClaudeService::class)->visionJson(
            'Write multiple-choice questions that can only be answered by looking at the image provided (read values, labels, parts, trends from it). '
            . 'Each has exactly four options A–D with one correct answer, plausible distractors, no "all of the above". Refer to it as "the diagram" or "the image". Use $…$ for any maths. '
            . 'If the image is unsuitable for questions, return {"questions": [], "reason": "…"}. '
            . 'JSON: {"questions": [{"question","options":{"A","B","C","D"},"correct_answer":"A|B|C|D","topic","difficulty":"easy|medium|hard","explanation"}]}.',
            "Subject: {$subject}\nTopic: " . ($topic ?: 'whatever the image shows') . "\nClass: " . ($classLevel ?: 'not given')
                . "\nDifficulty: {$difficulty}\nNumber of questions: {$count}\nThe image's description: {$asset->alt_text}",
            [['mime' => $asset->mime_type, 'bytes' => $bytes]],
            $schoolId,
            ['questions' => [[
                'question' => 'Placeholder: what does the diagram show?',
                'options' => ['A' => 'First', 'B' => 'Second', 'C' => 'Third', 'D' => 'Fourth'],
                'correct_answer' => 'A', 'topic' => $topic ?: $subject, 'difficulty' => $difficulty, 'explanation' => 'Placeholder.',
            ]]],
            min(16000, 700 * $count + 1500),
        );

        $questions = collect($result['questions'] ?? [])->filter(fn ($q) => $this->wellFormedObjective($q))
            ->map(fn ($q) => $q + ['question_type' => 'multiple_choice', 'media' => [['asset_id' => $asset->id, 'role' => 'stem']]])
            ->values()->all();

        return [
            'subject' => $subject, 'question_count' => count($questions), 'questions' => $questions,
            'image' => ['asset_id' => $asset->id, 'alt_text' => $asset->alt_text],
            'reason' => $result['reason'] ?? null, 'stub' => $result['stub'] ?? false,
        ];
    }

    /**
     * A printed question paper, photographed: its questions read out as drafts
     * for the teacher to check. Objective questions keep their options and,
     * only where the paper itself shows it, the answer; everything else is a
     * theory question. Nothing is invented and nothing enters the bank here.
     *
     * @param  array<int, array{mime: string, bytes: string}>  $pages
     */
    public function scanQuestionPaper(int $schoolId, array $pages, string $subject, ?string $classLevel = null): array
    {
        $this->assertFeature($schoolId, 'exam_generation', 'question generation');

        $result = app(ClaudeService::class)->visionJson(
            'These are photographs of a printed question paper, in page order. Transcribe its questions faithfully, in order, correcting only obvious OCR-style misreads. '
            . 'Do not invent questions, options or answers. For an objective question give its options keyed by their letters; give "correct_answer" only if the paper itself marks it, otherwise null. '
            . 'A question with no options is a theory question. Skip instructions, headers and page numbers. If a question depends on a figure on the paper, say so in "needs_figure". '
            . 'Use $…$ for maths. JSON: {"questions": [{"number","question","question_type":"multiple_choice|theory","options":{"A":…}|null,"correct_answer":"A"|null,"needs_figure":true|false}], "unreadable": "what could not be read, or null"}.',
            "Subject: {$subject}\nClass: " . ($classLevel ?: 'not given'),
            $pages,
            $schoolId,
            ['questions' => [[
                'number' => 1, 'question' => 'Placeholder transcribed question?', 'question_type' => 'multiple_choice',
                'options' => ['A' => 'First', 'B' => 'Second', 'C' => 'Third', 'D' => 'Fourth'], 'correct_answer' => null, 'needs_figure' => false,
            ]]],
            16000,
        );

        $questions = collect($result['questions'] ?? [])->filter(fn ($q) => is_array($q) && trim((string) ($q['question'] ?? '')) !== '')
            ->map(function (array $q) {
                $options = is_array($q['options'] ?? null) && count($q['options']) >= 2 ? $q['options'] : null;
                $answer = $options && isset($options[$q['correct_answer'] ?? '']) ? $q['correct_answer'] : null;

                return [
                    'number' => $q['number'] ?? null,
                    'question' => trim((string) $q['question']),
                    'question_type' => $options ? 'multiple_choice' : 'theory',
                    'options' => $options,
                    'correct_answer' => $answer,
                    // The teacher must supply the key before an objective question can be saved.
                    'needs_answer' => $options !== null && $answer === null,
                    'needs_figure' => (bool) ($q['needs_figure'] ?? false),
                ];
            })->values()->all();

        return ['subject' => $subject, 'question_count' => count($questions), 'questions' => $questions, 'unreadable' => $result['unreadable'] ?? null, 'stub' => $result['stub'] ?? false];
    }

    /**
     * A suggested alt text and caption for an image about to go into the CBT
     * library, which the teacher checks. Alt text is what a candidate using a
     * screen reader hears, so it describes, it does not give answers away.
     */
    public function describeImage(int $schoolId, string $bytes, string $mime, ?string $subject = null): array
    {
        $this->assertFeature($schoolId, 'exam_generation', 'question generation');

        $result = app(ClaudeService::class)->visionJson(
            'Describe this image for use in an exam question. "alt_text": one or two plain sentences saying what is shown (labels, axes, parts), for a candidate using a screen reader, '
            . 'without giving away any answer. "caption": a short title of at most eight words. JSON: {"alt_text","caption"}.',
            'Subject: ' . ($subject ?: 'not given'),
            [['mime' => $mime, 'bytes' => $bytes]],
            $schoolId,
            ['alt_text' => 'Placeholder description of the image.', 'caption' => 'Placeholder caption'],
            600,
        );

        return [
            'alt_text' => mb_substr(trim((string) ($result['alt_text'] ?? '')), 0, 255),
            'caption' => mb_substr(trim((string) ($result['caption'] ?? '')), 0, 255),
            'stub' => $result['stub'] ?? false,
        ];
    }

    /** One correct answer that is actually one of the options. */
    private function wellFormedObjective(mixed $q): bool
    {
        return is_array($q) && ! empty($q['question']) && is_array($q['options'] ?? null)
            && count($q['options']) >= 2 && isset($q['options'][$q['correct_answer'] ?? '']);
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
