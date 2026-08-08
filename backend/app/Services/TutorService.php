<?php

namespace App\Services;

use App\Models\Homework;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\StudentTopicMastery;
use App\Models\TutorConversation;
use App\Models\TutorMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The AI Learning Hub tutor (doc §7.11).
 *
 * What this replaces: an endpoint that returned the string "Great question!
 * Let's break down key concepts together." to every student for every message,
 * never called an LLM, and stored nothing.
 *
 * The spec's distinguishing requirement is that the tutor is "tied to what the
 * student's own teacher actually assigned (not a generic public chatbot)".
 * That is what `buildContext()` is for: the prompt carries this child's
 * subjects, the homework their teacher actually set this week, their recent
 * marks and the topics they are measurably weak on. Without that, this is a
 * chatbot with a school logo on it.
 *
 * Two safety properties, both non-negotiable for a product used by minors:
 *
 * - **It does not hand over answers.** A request to just produce the answer to
 *   assigned work is redirected into a worked explanation. Handled in the
 *   system prompt *and* checked on the way in, because a prompt alone is not a
 *   control.
 * - **It never fabricates when misconfigured.** With no API key and stubbing
 *   off it raises, rather than returning something that reads like tutoring.
 */
class TutorService
{
    private const MAX_HISTORY_TURNS = 12;

    public function __construct(private AiManagerService $ai)
    {
    }

    /**
     * Answer a student's message inside a conversation.
     *
     * @return array{reply: string, was_redirected: bool, conversation: TutorConversation}
     */
    public function reply(TutorConversation $conversation, Student $student, string $message): array
    {
        if (! $this->ai->isAiEnabled($student->school_id, 'tutor')) {
            throw new RuntimeException('The AI tutor is currently switched off by your school.');
        }

        $redirected = $this->looksLikeAnswerHarvesting($message);

        TutorMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'student',
            'body' => $message,
            'was_redirected' => $redirected,
        ]);

        $reply = $this->callModel(
            $student,
            $conversation,
            $this->recentTurns($conversation),
            $message,
            $redirected
        );

        TutorMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'tutor',
            'body' => $reply,
            'was_redirected' => $redirected,
            'topic' => $conversation->subject?->name,
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'message_count' => $conversation->message_count + 2,
        ]);

        StudentTopicMastery::recordTutorSession(
            $student->school_id,
            $student->id,
            $conversation->subject_id,
            $conversation->title
        );

        return [
            'reply' => $reply,
            'was_redirected' => $redirected,
            'conversation' => $conversation->fresh(),
        ];
    }

    /**
     * Blunt pattern check for "do my homework for me".
     *
     * Not a content filter and not trying to be: it catches the direct phrasing
     * and tightens the instruction we send. The model still does the judging.
     */
    private function looksLikeAnswerHarvesting(string $message): bool
    {
        $normalised = strtolower($message);

        foreach ([
            'give me the answer',
            'just the answer',
            'what is the direct answer',
            'answer to question',
            'do my homework',
            'do my assignment',
            'solve it for me',
            'write my essay',
            'complete my assignment',
        ] as $needle) {
            if (str_contains($normalised, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The last few turns, oldest first. Capped because a term-long thread would
     * otherwise grow the prompt without bound — and cost per message is a real
     * constraint for a school paying in naira.
     *
     * @return array<int,array{role:string,content:string}>
     */
    private function recentTurns(TutorConversation $conversation): array
    {
        return TutorMessage::where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(self::MAX_HISTORY_TURNS)
            ->get()
            ->reverse()
            ->map(fn (TutorMessage $m) => [
                'role' => $m->role === 'student' ? 'user' : 'assistant',
                'content' => $m->body,
            ])
            ->values()
            ->all();
    }

    /**
     * What the tutor knows about this particular child.
     *
     * Deliberately narrow: subjects, current homework, recent marks, weak
     * topics. No medical data, no guardian details, no address — the prompt
     * leaves the school's tenancy and crosses a border to Anthropic, so NDPA
     * data-minimisation (doc §12) applies to every field added here.
     */
    public function buildContext(Student $student, TutorConversation $conversation): string
    {
        $lines = [];

        $lines[] = 'STUDENT CONTEXT';
        $lines[] = 'Class: ' . ($student->currentClass?->name ?? 'unknown');

        $homework = Homework::where('school_id', $student->school_id)
            ->where('class_id', $student->class_id)
            ->where('due_date', '>=', now()->subWeek()->toDateString())
            ->with('subject')
            ->orderBy('due_date')
            ->limit(8)
            ->get();

        if ($homework->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Homework their teacher has actually set (help them reason about these; never hand over completed answers):';
            foreach ($homework as $hw) {
                $lines[] = sprintf(
                    '- [%s] %s (due %s)',
                    $hw->subject?->name ?? 'General',
                    $hw->title,
                    optional($hw->due_date)->toDateString() ?? 'unspecified'
                );
            }
        }

        $recent = ScoreEntry::where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->with('subject')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        if ($recent->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Recent marks:';
            foreach ($recent as $score) {
                $lines[] = sprintf(
                    '- %s: %s%% (%s)',
                    $score->subject?->name ?? 'Subject',
                    $score->total_score,
                    $score->grade ?? 'ungraded'
                );
            }
        }

        $weak = StudentTopicMastery::where('student_id', $student->id)
            ->where('questions_attempted', '>=', 3)
            ->orderBy('mastery_percentage')
            ->limit(5)
            ->get();

        if ($weak->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Topics this student is measurably weak on — steer practice here when it fits:';
            foreach ($weak as $row) {
                $lines[] = sprintf('- %s (%s%% correct over %d questions)', $row->topic, $row->mastery_percentage, $row->questions_attempted);
            }
        }

        if ($conversation->subject) {
            $lines[] = '';
            $lines[] = 'This conversation is about: ' . $conversation->subject->name;
        }

        return implode("\n", $lines);
    }

    private function systemPrompt(Student $student, TutorConversation $conversation, bool $redirected): string
    {
        $prompt = <<<'TXT'
You are a patient secondary-school tutor for a student in Nigeria, working inside their school's own learning platform.

Rules:
- Teach, do not complete. Never give a finished answer to assigned homework or an exam question. Walk the student through the reasoning one step at a time and let them do each step.
- Follow the Nigerian curriculum and its vocabulary: terms, continuous assessment, WAEC/NECO/JAMB, JSS/SS class names. Do not use American grade levels or GPA language.
- Money is in naira (₦).
- Answer in clear English. Keep replies short enough to read on a phone — a few short paragraphs at most.
- If asked about something outside schoolwork, redirect gently back to their studies.
- If you do not know, say so. Never invent a fact about this student's school, marks, or timetable.
TXT;

        if ($redirected) {
            $prompt .= "\n- The student has just asked you to hand over an answer outright. Do not. Acknowledge it warmly, then take them through the first step and ask them to try it.";
        }

        return $prompt . "\n\n" . $this->buildContext($student, $conversation);
    }

    /**
     * @param array<int,array{role:string,content:string}> $history
     */
    private function callModel(
        Student $student,
        TutorConversation $conversation,
        array $history,
        string $message,
        bool $redirected
    ): string {
        $config = $this->ai->getApiConfig($student->school_id);
        $hasKey = is_string($config['api_key']) && trim($config['api_key']) !== '';

        if (! $hasKey) {
            if (! config('services.anthropic.stub')) {
                // Never degrade into invented tutoring — the failure mode the
                // old hardcoded reply had, dressed up as a working feature.
                throw new RuntimeException('The AI tutor is unavailable: no Anthropic API key is configured.');
            }

            return $redirected
                ? '[STUB] I can see you want the answer outright — let us work through the first step together instead. What do you think we should try first?'
                : '[STUB] Tutor reply generated without calling the AI provider.';
        }

        // History already ends with the student's newest message because
        // reply() persists it before this runs.
        $messages = $history !== [] ? $history : [['role' => 'user', 'content' => $message]];

        $response = Http::withHeaders([
            'x-api-key' => $config['api_key'],
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(2, 500, throw: false)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $config['model'] ?: config('services.anthropic.comment_model'),
                'max_tokens' => 800,
                'system' => $this->systemPrompt($student, $conversation, $redirected),
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('The tutor could not be reached right now. Please try again shortly.');
        }

        $text = $response->json('content.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('The tutor returned an empty response. Please try again.');
        }

        return $text;
    }
}
