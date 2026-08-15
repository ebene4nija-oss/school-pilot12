<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CbtAttempt;
use App\Models\CbtAttemptAnswer;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtMediaAsset;
use App\Models\QuestionBankItem;
use App\Models\Student;
use App\Services\CbtExamService;
use App\Services\CbtMediaService;
use App\Services\MathContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The CBT surface.
 *
 * The engine underneath this (CbtExamService, CbtGradingService) was already
 * written and is deliberately left untouched — everything here is transport:
 * validation, authorization, and shaping. Three rules the endpoints enforce
 * that are easy to lose in a controller:
 *
 * 1. **The server owns the clock and the marking scheme.** Nothing a candidate
 *    POSTs can extend a deadline or reveal a correct answer.
 * 2. **The offline client uses the same code path as the live one.** There is
 *    no second, laxer way in.
 * 3. **Results are released on the school's terms**, not whenever the client
 *    asks — `show_results_immediately` gates what a candidate sees.
 */
class CbtController extends Controller
{
    public function __construct(
        private CbtExamService $exams,
        private CbtMediaService $media
    ) {
    }

    // ==================================================================
    // Image library
    // ==================================================================

    /**
     * Upload an image for use in questions.
     *
     * Alt text is required, not optional. A labelled biology diagram with no
     * description is a question a candidate using a screen reader cannot
     * answer, and retrofitting alt text across a bank of 4,000 questions never
     * happens — so the only moment it can be collected is this one.
     *
     * Everything else the pipeline does (sniffing the real type from the
     * bytes, refusing SVG, stripping EXIF, deduplicating by checksum) lives in
     * CbtMediaService.
     */
    public function uploadMedia(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|max:5120',
            'alt_text' => 'required|string|min:3|max:255',
            'caption' => 'nullable|string|max:255',
        ]);

        try {
            $asset = $this->media->store(
                $validated['file'],
                $this->schoolId($request),
                $request->user()->id,
                $validated['alt_text'],
                $validated['caption'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Image stored',
            'asset' => $asset->toManifestEntry(),
        ], 201);
    }

    public function listMedia(Request $request)
    {
        $assets = CbtMediaAsset::where('school_id', $this->schoolId($request))
            ->orderByDesc('id')
            ->paginate(min((int) $request->input('per_page', 30), 100));

        $assets->getCollection()->transform(fn (CbtMediaAsset $asset) => $asset->toManifestEntry());

        return response()->json($assets);
    }

    /**
     * Delete an image. Refused while any question still references it —
     * removing the diagram out from under a live question would leave
     * candidates staring at "Label the parts shown below" and nothing else.
     */
    public function destroyMedia(Request $request, $id)
    {
        $schoolId = $this->schoolId($request);
        $asset = CbtMediaAsset::where('school_id', $schoolId)->findOrFail($id);

        $inUse = QuestionBankItem::where('school_id', $schoolId)
            ->whereNotNull('media')
            ->get()
            ->filter(fn (QuestionBankItem $q) => in_array($asset->id, $q->mediaAssetIds(), true));

        if ($inUse->isNotEmpty()) {
            return response()->json([
                'error' => 'This image is used by ' . $inUse->count() . ' question(s). Detach it from them first.',
                'question_ids' => $inUse->pluck('id')->values(),
            ], 409);
        }

        Storage::disk($asset->disk)->delete(array_filter([$asset->path, $asset->thumbnail_path]));
        $asset->delete();

        return response()->json(['message' => 'Image deleted']);
    }

    private function schoolId(Request $request): ?int
    {
        $profile = $request->user()?->userProfile;

        if ($profile && $profile->school_id) {
            return (int) $profile->school_id;
        }

        $tenant = $request->attributes->get('tenant_school');

        return $tenant ? (int) $tenant->id : null;
    }

    /** The Student row behind an authenticated student user. */
    private function currentStudent(Request $request): ?Student
    {
        return Student::where('user_id', $request->user()->id)->first();
    }

    // ==================================================================
    // Question bank (§7.9)
    // ==================================================================

    public function listQuestions(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $query = QuestionBankItem::where('school_id', $schoolId)->with(['subject:id,name', 'group:id,title']);

        // `group_id=null` is a real filter, not a missing one: "show me the
        // standalone questions" is how an author finds what is not yet
        // attached to a passage.
        if ($request->has('group_id')) {
            $groupId = $request->input('group_id');
            $query->when(
                $groupId === null || $groupId === '' || $groupId === 'null',
                fn ($q) => $q->whereNull('group_id'),
                fn ($q) => $q->where('group_id', $groupId)
            );
        }

        foreach (['subject_id', 'topic', 'difficulty', 'question_type', 'exam_body', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('search')) {
            $query->where('question', 'like', '%' . $request->input('search') . '%');
        }

        $questions = $query->orderByDesc('id')->paginate(min((int) $request->input('per_page', 25), 100));

        // Never ship the marking scheme in a list a client caches.
        $questions->getCollection()->transform(function (QuestionBankItem $item) {
            return [
                'id' => $item->id,
                'subject' => $item->subject?->name,
                'subject_id' => $item->subject_id,
                'group_id' => $item->group_id,
                'group_title' => $item->group?->title,
                'group_sequence' => $item->group_sequence,
                'answer_mode' => $item->answerMode(),
                'topic' => $item->topic,
                'question' => $item->question,
                'question_type' => $item->question_type,
                'content_format' => $item->content_format,
                'difficulty' => $item->difficulty,
                'exam_body' => $item->exam_body,
                'status' => $item->status,
                'marks' => (float) $item->marks,
                'times_answered' => $item->times_answered,
                'difficulty_index' => $item->difficultyIndex(),
            ];
        });

        return response()->json($questions);
    }

    public function storeQuestion(Request $request, MathContentService $maths)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'subject_id' => ['required', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'group_id' => ['nullable', Rule::exists('cbt_question_groups', 'id')->where('school_id', $schoolId)],
            'group_sequence' => 'nullable|integer|min:1|max:99',
            'topic' => 'nullable|string|max:255',
            'question' => 'required|string',
            'question_type' => ['required', Rule::in(QuestionBankItem::TYPES)],
            'options' => 'nullable|array',
            'correct_answer' => 'nullable',
            'answer_schema' => 'nullable|array',
            'explanation' => 'nullable|string',
            'difficulty' => 'nullable|in:easy,medium,hard',
            'exam_body' => 'nullable|string|max:32',
            'marks' => 'nullable|numeric|min:0|max:100',
            'negative_marks' => 'nullable|numeric|min:0|max:100',
            'content_format' => 'nullable|in:plain,latex',
            'media' => 'nullable|array',
            'status' => 'nullable|in:draft,approved,retired',
        ]);

        // A question that is auto-gradable but carries no marking scheme is a
        // question that will silently award zero to the whole class.
        $type = $validated['question_type'];
        if (in_array($type, QuestionBankItem::AUTO_GRADED_TYPES, true)
            && ($validated['correct_answer'] ?? null) === null
            && empty($validated['answer_schema'])) {
            return response()->json([
                'errors' => ['correct_answer' => ["A {$type} question needs a correct_answer or an answer_schema."]],
            ], 422);
        }

        if ($error = $this->validateTheorySchema($type, $validated['answer_schema'] ?? null, $validated['marks'] ?? 1)) {
            return response()->json(['errors' => ['answer_schema' => [$error]]], 422);
        }

        // LaTeX and image references are validated together: both are content
        // the client will render, and both are rejected at authoring time
        // rather than discovered by a candidate mid-paper.
        try {
            $format = $this->validateContent($maths, $validated);
            $media = $this->media->normaliseMediaReferences($validated['media'] ?? null, $schoolId, $type);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $question = QuestionBankItem::create(array_merge($validated, [
            'school_id' => $schoolId,
            'content_format' => $format,
            'media' => $media ?: null,
            'created_by' => $request->user()->id,
            'status' => $validated['status'] ?? 'approved',
            'marks' => $validated['marks'] ?? 1,
        ]));

        return response()->json(['message' => 'Question added to bank', 'question' => $question], 201);
    }

    public function updateQuestion(Request $request, $id, MathContentService $maths)
    {
        $schoolId = $this->schoolId($request);
        $question = QuestionBankItem::where('school_id', $schoolId)->findOrFail($id);

        $validated = $request->validate([
            'group_id' => ['nullable', Rule::exists('cbt_question_groups', 'id')->where('school_id', $schoolId)],
            'group_sequence' => 'nullable|integer|min:1|max:99',
            'topic' => 'nullable|string|max:255',
            'question' => 'sometimes|string',
            'options' => 'nullable|array',
            'correct_answer' => 'nullable',
            'answer_schema' => 'nullable|array',
            'explanation' => 'nullable|string',
            'difficulty' => 'nullable|in:easy,medium,hard',
            'exam_body' => 'nullable|string|max:32',
            'marks' => 'nullable|numeric|min:0|max:100',
            'negative_marks' => 'nullable|numeric|min:0|max:100',
            'content_format' => 'nullable|in:plain,latex',
            'media' => 'nullable|array',
            'status' => 'nullable|in:draft,approved,retired',
        ]);

        if (array_key_exists('answer_schema', $validated)) {
            $error = $this->validateTheorySchema(
                $question->question_type,
                $validated['answer_schema'],
                $validated['marks'] ?? $question->marks
            );

            if ($error) {
                return response()->json(['errors' => ['answer_schema' => [$error]]], 422);
            }
        }

        // Editing a question mid-exam would change the paper under candidates
        // who are sitting it right now. Swapping its diagram counts as editing
        // it — a labelling question with a different image is a new question.
        $live = CbtExamQuestion::where('question_id', $question->id)
            ->whereHas('exam', fn ($q) => $q->where('status', 'published'))
            ->exists();

        // Moving a question into or out of a group counts as editing it too:
        // it changes which stimulus the candidate is reading it against, and
        // it moves the question to a different place in the paper.
        if ($live && array_intersect(array_keys($validated), ['question', 'options', 'correct_answer', 'answer_schema', 'media', 'group_id', 'group_sequence'])) {
            return response()->json([
                'error' => 'This question is attached to a published exam. Retire it and add a replacement instead of editing it in place.',
            ], 409);
        }

        try {
            // Re-validate against the merged result: changing only the options
            // of a LaTeX question still has to leave the whole item valid.
            $merged = array_merge([
                'question' => $question->question,
                'options' => $question->options,
                'explanation' => $question->explanation,
                'content_format' => $question->content_format,
            ], $validated);

            $validated['content_format'] = $this->validateContent($maths, $merged);

            if (array_key_exists('media', $validated)) {
                $validated['media'] = $this->media->normaliseMediaReferences(
                    $validated['media'],
                    $schoolId,
                    $validated['question_type'] ?? $question->question_type
                ) ?: null;
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $question->update($validated);

        return response()->json(['message' => 'Question updated', 'question' => $question->fresh()]);
    }

    /**
     * Validate every field a client will hand to KaTeX and resolve the stored
     * content format.
     *
     * Options are checked as well as the stem, which matters more than it
     * sounds: in a maths paper the stem is often plain prose ("Simplify the
     * following") and every bit of LaTeX lives in the options.
     *
     * @throws \InvalidArgumentException
     */
    private function validateContent(MathContentService $maths, array $input): string
    {
        $optionTexts = collect($input['options'] ?? [])
            ->map(fn ($option) => is_array($option) ? (string) ($option['text'] ?? $option['label'] ?? '') : (string) $option)
            ->all();

        $format = $maths->resolveFormat(
            $input['content_format'] ?? null,
            (string) ($input['question'] ?? ''),
            (string) ($input['explanation'] ?? ''),
            ...$optionTexts
        );

        if ($format !== 'latex') {
            return $format;
        }

        $maths->assertValid($input['question'] ?? null, 'the question text');
        $maths->assertValid($input['explanation'] ?? null, 'the explanation');

        // Option keys are usually letters ('A', 'B'), sometimes list indexes.
        // Name whichever one the author will recognise in the error message.
        foreach ($optionTexts as $key => $text) {
            $label = is_int($key) ? 'option ' . ($key + 1) : 'option ' . $key;
            $maths->assertValid($text, $label);
        }

        return $format;
    }

    /**
     * Validate the theory half of `answer_schema` (§6.3).
     *
     * Theory is the manually-graded *family*, not one question type, and its
     * shape lives in `answer_schema` alongside every other type's grading
     * configuration rather than in new columns. That keeps the migration
     * surface small but means nothing validates it unless this does — and a
     * mistyped `answer_mode` would quietly become "on screen" for a paper the
     * school printed booklets for.
     *
     * @return string|null the complaint, or null if the schema is fine
     */
    private function validateTheorySchema(?string $questionType, ?array $schema, $marks): ?string
    {
        if ($questionType !== 'theory' || empty($schema)) {
            return null;
        }

        if (isset($schema['response_format']) && ! in_array($schema['response_format'], QuestionBankItem::RESPONSE_FORMATS, true)) {
            return 'response_format must be one of: ' . implode(', ', QuestionBankItem::RESPONSE_FORMATS) . '.';
        }

        if (isset($schema['answer_mode']) && ! in_array($schema['answer_mode'], QuestionBankItem::ANSWER_MODES, true)) {
            return 'answer_mode must be on_screen or on_paper.';
        }

        $expected = isset($schema['expected_words']) ? (int) $schema['expected_words'] : null;
        $max = isset($schema['max_words']) ? (int) $schema['max_words'] : null;

        if ($max !== null && $max < 1) {
            return 'max_words must be at least 1.';
        }

        if ($expected !== null && $max !== null && $expected > $max) {
            // The candidate is shown `expected_words` as a guide and stopped
            // at `max_words`. Guidance a candidate cannot follow is worse than
            // no guidance.
            return "expected_words ({$expected}) cannot exceed max_words ({$max}).";
        }

        if (($schema['answer_mode'] ?? 'on_screen') === 'on_paper' && ! empty($schema['max_words'])) {
            return 'max_words has no meaning for an on_paper answer — nothing on the client counts words in a booklet.';
        }

        if (! array_key_exists('rubric', $schema)) {
            return null;
        }

        if (! is_array($schema['rubric'])) {
            return 'rubric must be a list of { criterion, marks } entries.';
        }

        $rubricTotal = 0.0;

        foreach ($schema['rubric'] as $index => $criterion) {
            if (! is_array($criterion) || empty($criterion['criterion'])) {
                return "rubric entry {$index} needs a 'criterion'.";
            }

            if (! isset($criterion['marks']) || ! is_numeric($criterion['marks']) || $criterion['marks'] < 0) {
                return "rubric entry {$index} needs a non-negative 'marks' value.";
            }

            $rubricTotal += (float) $criterion['marks'];
        }

        // A rubric adding to more than the question is worth is a marking
        // dispute waiting to happen: the teacher awards against the criteria,
        // the total exceeds the question, and the paper no longer sums to
        // `total_marks`.
        if ($marks !== null && $rubricTotal > (float) $marks + 0.001) {
            return sprintf(
                'The rubric awards %s mark(s) but the question is worth %s.',
                rtrim(rtrim(number_format($rubricTotal, 2), '0'), '.'),
                rtrim(rtrim(number_format((float) $marks, 2), '0'), '.')
            );
        }

        return null;
    }

    public function destroyQuestion(Request $request, $id)
    {
        $schoolId = $this->schoolId($request);
        $question = QuestionBankItem::where('school_id', $schoolId)->findOrFail($id);

        if (CbtExamQuestion::where('question_id', $question->id)->exists()) {
            // Attempts reference it for item analysis; deleting would orphan
            // the history. Retiring keeps it out of new papers.
            $question->update(['status' => 'retired']);

            return response()->json(['message' => 'Question is in use by an exam and has been retired rather than deleted.']);
        }

        $question->delete();

        return response()->json(['message' => 'Question deleted']);
    }

    /** Bulk import — the realistic way a bank of 500 WAEC questions arrives. */
    public function importQuestions(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'subject_id' => ['required', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'questions' => 'required|array|min:1|max:500',
            'questions.*.question' => 'required|string',
            'questions.*.question_type' => ['nullable', Rule::in(QuestionBankItem::TYPES)],
            'questions.*.options' => 'nullable|array',
            'questions.*.correct_answer' => 'nullable',
            'questions.*.topic' => 'nullable|string|max:255',
            'questions.*.difficulty' => 'nullable|in:easy,medium,hard',
            'questions.*.marks' => 'nullable|numeric|min:0|max:100',
        ]);

        $imported = 0;
        $skipped = [];

        DB::transaction(function () use ($validated, $schoolId, $request, &$imported, &$skipped) {
            foreach ($validated['questions'] as $index => $row) {
                $type = $row['question_type'] ?? 'multiple_choice';

                if (in_array($type, QuestionBankItem::AUTO_GRADED_TYPES, true) && ($row['correct_answer'] ?? null) === null) {
                    $skipped[] = "Row {$index}: auto-graded question with no correct_answer.";
                    continue;
                }

                QuestionBankItem::create([
                    'school_id' => $schoolId,
                    'subject_id' => $validated['subject_id'],
                    'topic' => $row['topic'] ?? null,
                    'question' => $row['question'],
                    'question_type' => $type,
                    'options' => $row['options'] ?? null,
                    'correct_answer' => $row['correct_answer'] ?? null,
                    'difficulty' => $row['difficulty'] ?? 'medium',
                    'marks' => $row['marks'] ?? 1,
                    'created_by' => $request->user()->id,
                    'status' => 'approved',
                ]);

                $imported++;
            }
        });

        return response()->json([
            'message' => "Imported {$imported} question(s).",
            'imported' => $imported,
            'skipped' => $skipped,
        ], 201);
    }

    // ==================================================================
    // Exam authoring (§7.8)
    // ==================================================================

    public function listExams(Request $request)
    {
        $schoolId = $this->schoolId($request);
        $profile = $request->user()->userProfile;

        $query = CbtExam::where('school_id', $schoolId)
            ->with(['subject:id,name'])
            ->withCount(['examQuestions', 'attempts']);

        // A teacher's list defaults to their own papers; an admin sees all.
        if ($profile && $profile->role === 'teacher' && ! $request->boolean('all')) {
            $query->where('created_by', $request->user()->id);
        }

        foreach (['status', 'class_id', 'subject_id', 'term_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return response()->json(
            $query->orderByDesc('id')->paginate(min((int) $request->input('per_page', 25), 100))
        );
    }

    public function storeExam(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'subject_id' => ['required', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'title' => 'required|string|max:255',
            'instructions' => 'nullable|string',
            'exam_body' => 'nullable|string|max:32',
            'duration_minutes' => 'required|integer|min:1|max:600',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date|after:opens_at',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'shuffle_within_group' => 'boolean',
            'questions_per_attempt' => 'nullable|integer|min:1',
            'max_attempts' => 'nullable|integer|min:1|max:10',
            'negative_marking' => 'boolean',
            'pass_mark' => 'nullable|numeric|min:0|max:100',
            'show_results_immediately' => 'boolean',
            'allow_offline' => 'boolean',
            'integrity_settings' => 'nullable|array',
        ]);

        $exam = CbtExam::create(array_merge($validated, [
            'school_id' => $schoolId,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ], $this->offlineResultPolicy($validated)));

        return response()->json([
            'message' => 'Exam created as draft',
            'exam' => $exam,
            'notice' => $exam->allow_offline ? self::OFFLINE_RESULTS_NOTICE : null,
        ], 201);
    }

    public function showExam(Request $request, $id)
    {
        $exam = CbtExam::where('school_id', $this->schoolId($request))->findOrFail($id);
        $this->authorize('view', $exam);

        $exam->load(['subject:id,name', 'examQuestions.question:id,question,question_type,topic,difficulty,marks']);

        return response()->json([
            'exam' => $exam,
            'total_marks' => $this->recalculateTotalMarks($exam),
            'attempt_count' => CbtAttempt::where('exam_id', $exam->id)->count(),
        ]);
    }

    public function updateExam(Request $request, $id)
    {
        $exam = CbtExam::where('school_id', $this->schoolId($request))->findOrFail($id);
        $this->authorize('manage', $exam);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'instructions' => 'nullable|string',
            'duration_minutes' => 'sometimes|integer|min:1|max:600',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'shuffle_within_group' => 'boolean',
            'questions_per_attempt' => 'nullable|integer|min:1',
            'max_attempts' => 'nullable|integer|min:1|max:10',
            'negative_marking' => 'boolean',
            'pass_mark' => 'nullable|numeric|min:0|max:100',
            'show_results_immediately' => 'boolean',
            'allow_offline' => 'boolean',
            'integrity_settings' => 'nullable|array',
        ]);

        // Once a paper is live, changing its duration or marking rules
        // retroactively alters attempts already in flight. Only the window and
        // release settings stay adjustable.
        if ($exam->status === 'published') {
            $allowed = ['closes_at', 'show_results_immediately', 'integrity_settings'];
            $blocked = array_diff(array_keys($validated), $allowed);

            if ($blocked) {
                return response()->json([
                    'error' => 'This exam is published. Only ' . implode(', ', $allowed) . ' may be changed while candidates can sit it.',
                    'blocked_fields' => array_values($blocked),
                ], 409);
            }
        }

        $exam->update(array_merge($validated, $this->offlineResultPolicy($validated, $exam)));

        return response()->json([
            'message' => 'Exam updated',
            'exam' => $exam->fresh(),
            'notice' => $exam->fresh()->allow_offline ? self::OFFLINE_RESULTS_NOTICE : null,
        ]);
    }

    private const OFFLINE_RESULTS_NOTICE = 'Offline papers do not show results in the exam room. Marks are released by the school after the relay syncs.';

    /**
     * An offline paper never shows a score in the room (§5.4).
     *
     * This is a trade, not an oversight. Instant grading would need answer
     * keys and marking rubrics inside the bundle — on a laptop, in the room
     * where the exam is being sat, which is the highest-value secret in the
     * system sitting in the worst possible place. Deferring results is what
     * lets the bundle contain no correct answers at all, and that removes an
     * entire class of attack rather than mitigating it.
     *
     * Forced rather than validated, because a school that ticks both boxes has
     * not made a choice we should refuse — they have made one we should
     * quietly correct and then explain.
     */
    private function offlineResultPolicy(array $validated, ?CbtExam $exam = null): array
    {
        $offline = array_key_exists('allow_offline', $validated)
            ? (bool) $validated['allow_offline']
            : (bool) ($exam?->allow_offline);

        return $offline ? ['show_results_immediately' => false] : [];
    }

    public function attachQuestions(Request $request, $id)
    {
        $exam = CbtExam::where('school_id', $this->schoolId($request))->findOrFail($id);
        $this->authorize('manage', $exam);

        if ($exam->status === 'published') {
            return response()->json(['error' => 'Cannot change the questions on a published exam.'], 409);
        }

        $validated = $request->validate([
            'questions' => 'required|array|min:1',
            'questions.*.question_id' => ['required', Rule::exists('question_bank', 'id')->where('school_id', $exam->school_id)],
            'questions.*.marks' => 'nullable|numeric|min:0|max:100',
            'questions.*.negative_marks' => 'nullable|numeric|min:0|max:100',
            'questions.*.section' => 'nullable|string|max:100',
        ]);

        $nextIndex = (int) CbtExamQuestion::where('exam_id', $exam->id)->max('order_index');

        DB::transaction(function () use ($validated, $exam, &$nextIndex) {
            foreach ($validated['questions'] as $row) {
                CbtExamQuestion::updateOrCreate(
                    ['exam_id' => $exam->id, 'question_id' => $row['question_id']],
                    [
                        'order_index' => ++$nextIndex,
                        'marks' => $row['marks'] ?? null,
                        'negative_marks' => $row['negative_marks'] ?? null,
                        'section' => $row['section'] ?? null,
                    ]
                );
            }
        });

        return response()->json([
            'message' => 'Questions attached',
            'question_count' => CbtExamQuestion::where('exam_id', $exam->id)->count(),
            'total_marks' => $this->recalculateTotalMarks($exam),
        ]);
    }

    public function detachQuestion(Request $request, $id, $questionId)
    {
        $exam = CbtExam::where('school_id', $this->schoolId($request))->findOrFail($id);
        $this->authorize('manage', $exam);

        if ($exam->status === 'published') {
            return response()->json(['error' => 'Cannot change the questions on a published exam.'], 409);
        }

        CbtExamQuestion::where('exam_id', $exam->id)->where('question_id', $questionId)->delete();

        return response()->json([
            'message' => 'Question removed from exam',
            'total_marks' => $this->recalculateTotalMarks($exam),
        ]);
    }

    public function publishExam(Request $request, $id)
    {
        $exam = CbtExam::where('school_id', $this->schoolId($request))->findOrFail($id);
        $this->authorize('manage', $exam);

        $attached = CbtExamQuestion::where('exam_id', $exam->id)->count();

        if ($attached === 0) {
            return response()->json(['error' => 'Attach at least one question before publishing.'], 422);
        }

        if ($exam->questions_per_attempt && $exam->questions_per_attempt > $attached) {
            return response()->json([
                'error' => "questions_per_attempt ({$exam->questions_per_attempt}) exceeds the {$attached} question(s) attached.",
            ], 422);
        }

        if ($exam->allow_offline && ! $exam->opens_at) {
            return response()->json([
                'error' => 'An offline paper needs an opening time before it is published, so the relay can carry a real deadline into a room with no clock it can trust.',
            ], 422);
        }

        $exam->update(['status' => 'published', 'total_marks' => $this->recalculateTotalMarks($exam)]);

        // A subset draw selects whole groups, never individual sub-questions
        // (§6.6). Where N cannot be composed exactly out of whole groups the
        // engine overshoots to the nearest boundary — said here, at publish,
        // rather than discovered by a candidate sitting 21 questions on a
        // paper the author set to 20.
        $effective = $this->exams->effectiveQuestionCount($exam->fresh());
        $notice = null;

        if ($exam->questions_per_attempt && $effective !== (int) $exam->questions_per_attempt) {
            $notice = sprintf(
                'Each candidate will sit %d questions rather than %d: a subset draw takes whole groups, and %d rounds up to the nearest group boundary.',
                $effective,
                $exam->questions_per_attempt,
                $exam->questions_per_attempt
            );
        }

        return response()->json(array_filter([
            'message' => 'Exam published',
            'exam' => $exam->fresh(),
            'questions_per_candidate' => $effective,
            'notice' => $notice,
        ], fn ($value) => $value !== null));
    }

    public function closeExam(Request $request, $id)
    {
        $exam = CbtExam::where('school_id', $this->schoolId($request))->findOrFail($id);
        $this->authorize('manage', $exam);

        // Anyone still sitting is submitted and graded rather than left
        // stranded in `in_progress` forever.
        $closed = 0;
        foreach (CbtAttempt::where('exam_id', $exam->id)->where('status', 'in_progress')->get() as $attempt) {
            $this->exams->submitAttempt($attempt, 'auto_submit');
            $closed++;
        }

        $exam->update(['status' => 'closed']);

        return response()->json([
            'message' => 'Exam closed',
            'attempts_auto_submitted' => $closed,
        ]);
    }

    /**
     * Marks a paper is actually worth, from its per-exam overrides. Kept in
     * sync here rather than trusted from the client.
     */
    private function recalculateTotalMarks(CbtExam $exam): float
    {
        $links = CbtExamQuestion::where('exam_id', $exam->id)->with('question')->get();

        $perQuestion = $links
            ->map(fn ($link) => $link->question ? $link->effectiveMarks($link->question) : 0.0)
            ->sort()
            ->values();

        // With a subset draw, the paper is worth the N highest-value questions
        // at most — quoting the full bank total would overstate it.
        $total = $exam->questions_per_attempt
            ? $perQuestion->reverse()->take($exam->questions_per_attempt)->sum()
            : $perQuestion->sum();

        $total = round((float) $total, 2);

        if ((float) $exam->total_marks !== $total) {
            $exam->forceFill(['total_marks' => $total])->save();
        }

        return $total;
    }

    // ==================================================================
    // Sitting the paper
    // ==================================================================

    /** What this candidate can sit right now. */
    public function availableExams(Request $request)
    {
        $student = $this->currentStudent($request);

        if (! $student) {
            return response()->json(['error' => 'Student record not found.'], 404);
        }

        $now = now();

        $exams = CbtExam::where('school_id', $student->school_id)
            ->where('status', 'published')
            ->where(function ($q) use ($student) {
                $q->whereNull('class_id')->orWhere('class_id', $student->class_id);
            })
            ->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>=', $now))
            ->with('subject:id,name')
            ->orderBy('closes_at')
            ->get();

        $used = CbtAttempt::where('student_id', $student->id)
            ->whereIn('exam_id', $exams->pluck('id'))
            ->get()
            ->groupBy('exam_id');

        return response()->json([
            'data' => $exams->map(function (CbtExam $exam) use ($used) {
                $attempts = $used->get($exam->id, collect());
                $spent = $attempts->whereIn('status', ['submitted', 'graded', 'expired'])->count();
                $open = $attempts->firstWhere('status', 'in_progress');

                return [
                    'exam_id' => $exam->id,
                    'title' => $exam->title,
                    'subject' => $exam->subject?->name,
                    'duration_minutes' => $exam->duration_minutes,
                    'total_marks' => (float) $exam->total_marks,
                    'closes_at' => $exam->closes_at?->toIso8601String(),
                    'attempts_used' => $spent,
                    'attempts_allowed' => $exam->max_attempts,
                    'can_start' => $open !== null || $spent < $exam->max_attempts,
                    'resumable_attempt_id' => $open?->id,
                ];
            })->values(),
        ]);
    }

    public function startAttempt(Request $request, $examId)
    {
        $student = $this->currentStudent($request);

        if (! $student) {
            return response()->json(['error' => 'Student record not found.'], 404);
        }

        $exam = CbtExam::where('school_id', $student->school_id)->findOrFail($examId);

        // Class binding: an SS3 mock is not openable by a JSS1 pupil who
        // guessed the id.
        if (! $request->user()->can('sit', [$exam, $student])) {
            return response()->json(['error' => 'This exam was not set for your class.'], 403);
        }

        try {
            $attempt = $this->exams->startAttempt($exam, $student->id, [
                'device_fingerprint' => $request->header('X-Device-Fingerprint'),
                'ip_address' => $request->ip(),
                'extra_time_minutes' => 0,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->paperPayload($attempt, $exam), 201);
    }

    /** Resume: same paper, same order, whatever time is actually left. */
    public function showAttempt(Request $request, $attemptId)
    {
        $attempt = CbtAttempt::findOrFail($attemptId);
        $this->authorize('sit', $attempt);

        $exam = CbtExam::withoutGlobalScopes()->findOrFail($attempt->exam_id);

        if ($attempt->isFinalised()) {
            return response()->json(['error' => 'This attempt has been submitted.'], 409);
        }

        if ($attempt->hasExpired()) {
            $this->exams->submitAttempt($attempt, 'auto_submit');

            return response()->json(['error' => 'Your time for this exam has elapsed; the attempt was submitted.'], 409);
        }

        $this->exams->logEvent($attempt, 'resumed', ['ip' => $request->ip()]);

        return response()->json($this->paperPayload($attempt->fresh(), $exam));
    }

    private function paperPayload(CbtAttempt $attempt, CbtExam $exam): array
    {
        return [
            'attempt' => [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status,
                'started_at' => $attempt->started_at?->toIso8601String(),
                // The only clock the client should trust.
                'server_deadline_at' => $attempt->server_deadline_at?->toIso8601String(),
                'seconds_remaining' => $attempt->secondsRemaining(),
            ],
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'instructions' => $exam->instructions,
                'duration_minutes' => $exam->duration_minutes,
                'total_marks' => (float) $exam->total_marks,
                'negative_marking' => (bool) $exam->negative_marking,
                'integrity_settings' => $exam->integrity_settings,
            ],
            'questions' => $this->exams->buildCandidatePaper($attempt, $exam),
        ];
    }

    /** Autosave. Called constantly, so it stays cheap and idempotent. */
    public function saveAnswers(Request $request, $attemptId)
    {
        $attempt = CbtAttempt::findOrFail($attemptId);
        $this->authorize('sit', $attempt);

        $validated = $request->validate([
            'answers' => 'required|array|min:1',
            'answers.*.question_id' => 'required|integer',
            'answers.*.response' => 'nullable',
            'answers.*.client_timestamp' => 'nullable|date',
            'answers.*.client_sequence' => 'nullable|integer|min:0',
            'answers.*.time_spent_seconds' => 'nullable|integer|min:0',
            'answers.*.flagged_for_review' => 'nullable|boolean',
        ]);

        if ($attempt->hasExpired()) {
            $attempt = $this->exams->submitAttempt($attempt, 'auto_submit');

            return response()->json([
                'error' => 'Time elapsed. The attempt was submitted and these answers were not saved.',
                'attempt_status' => $attempt->status,
            ], 409);
        }

        $result = $this->exams->recordAnswers($attempt, $validated['answers']);

        return response()->json([
            'saved' => $result['saved'],
            'ignored' => $result['ignored'],
            'rejected' => $result['rejected'],
            'seconds_remaining' => $attempt->secondsRemaining(),
        ]);
    }

    public function submitAttempt(Request $request, $attemptId)
    {
        $attempt = CbtAttempt::findOrFail($attemptId);
        $this->authorize('sit', $attempt);

        $exam = CbtExam::withoutGlobalScopes()->findOrFail($attempt->exam_id);
        $attempt = $this->exams->submitAttempt($attempt, 'manual_submit');

        return response()->json([
            'message' => 'Attempt submitted',
            'result' => $this->resultPayload($attempt, $exam, forStaff: false),
        ]);
    }

    /**
     * Hardware-free invigilation: the client reports focus loss, paste,
     * fullscreen exit. We log it and let a human read it — no proctoring
     * camera, no biometrics (doc §3).
     */
    public function logAttemptEvent(Request $request, $attemptId)
    {
        $attempt = CbtAttempt::findOrFail($attemptId);
        $this->authorize('sit', $attempt);

        $validated = $request->validate([
            'event_type' => ['required', Rule::in(\App\Models\CbtAttemptEvent::CLIENT_REPORTABLE_TYPES)],
            'metadata' => 'nullable|array',
        ]);

        $this->exams->logEvent($attempt, $validated['event_type'], $validated['metadata'] ?? []);

        // A running count is what an invigilator actually looks at.
        $flags = $attempt->integrity_flags ?? [];
        $flags[$validated['event_type']] = ($flags[$validated['event_type']] ?? 0) + 1;
        $attempt->update(['integrity_flags' => $flags]);

        return response()->json(['logged' => true, 'integrity_flags' => $flags]);
    }

    // ==================================================================
    // Results and marking
    // ==================================================================

    public function attemptResult(Request $request, $attemptId)
    {
        $attempt = CbtAttempt::findOrFail($attemptId);
        $this->authorize('view', $attempt);

        if (! $attempt->isFinalised()) {
            return response()->json(['error' => 'This attempt has not been submitted yet.'], 409);
        }

        $exam = CbtExam::withoutGlobalScopes()->findOrFail($attempt->exam_id);
        $role = $request->user()->userProfile?->role;
        $isStaff = in_array($role, ['super_admin', 'school_admin', 'teacher'], true);

        if (! $isStaff && ! $exam->show_results_immediately) {
            return response()->json([
                'message' => 'Your paper was submitted. Results will be released by your school.',
                'released' => false,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            ]);
        }

        return response()->json([
            'released' => true,
            'result' => $this->resultPayload($attempt, $exam, $isStaff),
        ]);
    }

    /**
     * Per-question review is staff-only, or candidate-facing only once the
     * school has released results — otherwise the first candidate out of the
     * hall hands the marking scheme to the queue outside.
     */
    private function resultPayload(CbtAttempt $attempt, CbtExam $exam, bool $forStaff): array
    {
        $released = $forStaff || $exam->show_results_immediately;

        $payload = [
            'attempt_id' => $attempt->id,
            'exam_title' => $exam->title,
            'status' => $attempt->status,
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'requires_manual_grading' => (bool) $attempt->requires_manual_grading,
        ];

        if (! $released) {
            $payload['message'] = 'Submitted. Results will be released by your school.';

            return $payload;
        }

        $payload += [
            'raw_score' => (float) $attempt->raw_score,
            'max_score' => (float) $attempt->max_score,
            'percentage' => (float) $attempt->percentage,
            'grade' => $attempt->grade,
            'passed' => (float) $attempt->percentage >= (float) $exam->pass_mark,
            'time_spent_seconds' => $attempt->time_spent_seconds,
        ];

        $answers = CbtAttemptAnswer::where('attempt_id', $attempt->id)->get();
        $questions = QuestionBankItem::withoutGlobalScopes()
            ->whereIn('id', $answers->pluck('question_id'))
            ->get()
            ->keyBy('id');

        $payload['topic_breakdown'] = $answers
            ->groupBy(fn ($a) => $questions->get($a->question_id)?->topic ?? 'Untagged')
            ->map(fn ($group, $topic) => [
                'topic' => $topic,
                'answered' => $group->count(),
                'correct' => $group->where('is_correct', true)->count(),
                'mastery_percentage' => $group->count() > 0
                    ? round(($group->where('is_correct', true)->count() / $group->count()) * 100, 2)
                    : 0.0,
            ])->values();

        $payload['questions'] = $answers->map(function (CbtAttemptAnswer $answer) use ($questions, $forStaff) {
            $question = $questions->get($answer->question_id);

            $row = [
                'question_id' => $answer->question_id,
                'question' => $question?->question,
                'your_response' => $answer->response,
                'is_correct' => $answer->is_correct,
                'awarded_marks' => (float) $answer->awarded_marks,
                'explanation' => $question?->explanation,
                'feedback' => $answer->feedback,
            ];

            // The marking scheme itself is staff-only even after release.
            if ($forStaff) {
                $row['correct_answer'] = $question?->correct_answer;
            }

            return $row;
        })->values();

        return $payload;
    }

    /** Teacher marking for theory questions the grader left open. */
    public function gradeAttempt(Request $request, $attemptId)
    {
        $attempt = CbtAttempt::findOrFail($attemptId);
        $this->authorize('grade', $attempt);

        $validated = $request->validate([
            'marks' => 'required|array|min:1',
            'marks.*.question_id' => 'required|integer',
            'marks.*.awarded_marks' => 'required|numeric|min:0',
            'marks.*.feedback' => 'nullable|string|max:2000',
        ]);

        $exam = CbtExam::withoutGlobalScopes()->findOrFail($attempt->exam_id);

        DB::transaction(function () use ($validated, $attempt, $request) {
            foreach ($validated['marks'] as $row) {
                CbtAttemptAnswer::where('attempt_id', $attempt->id)
                    ->where('question_id', $row['question_id'])
                    ->update([
                        'awarded_marks' => $row['awarded_marks'],
                        'is_correct' => $row['awarded_marks'] > 0,
                        'feedback' => $row['feedback'] ?? null,
                        'graded_by' => (string) $request->user()->id,
                    ]);
            }

            // Re-total from the answer rows rather than adding a delta, so a
            // corrected mark cannot double-count.
            $raw = max(0.0, (float) CbtAttemptAnswer::where('attempt_id', $attempt->id)->sum('awarded_marks'));
            $max = (float) $attempt->max_score;
            $percentage = $max > 0 ? round(($raw / $max) * 100, 2) : 0.0;

            $outstanding = CbtAttemptAnswer::where('attempt_id', $attempt->id)
                ->whereNull('graded_by')
                ->exists();

            $attempt->update([
                'raw_score' => round($raw, 2),
                'percentage' => $percentage,
                'grade' => $this->exams->waecGrade($percentage),
                'requires_manual_grading' => $outstanding,
                'status' => $outstanding ? 'submitted' : 'graded',
                'graded_at' => $outstanding ? null : now(),
            ]);
        });

        $this->exams->logEvent($attempt, 'manually_graded', ['by' => $request->user()->id]);

        return response()->json([
            'message' => 'Marks recorded',
            'result' => $this->resultPayload($attempt->fresh(), $exam, forStaff: true),
        ]);
    }

    /** Whole-cohort view: how the class did, and which questions misfired. */
    public function examResults(Request $request, $id)
    {
        $exam = CbtExam::where('school_id', $this->schoolId($request))->findOrFail($id);
        $this->authorize('view', $exam);

        $attempts = CbtAttempt::where('exam_id', $exam->id)
            ->whereIn('status', ['submitted', 'graded'])
            ->with(['student.user:id,name'])
            ->get();

        $percentages = $attempts->pluck('percentage')->filter()->map(fn ($p) => (float) $p);

        $itemAnalysis = CbtAttemptAnswer::whereIn('attempt_id', $attempts->pluck('id'))
            ->select('question_id')
            ->selectRaw('COUNT(*) as answered')
            // `is_correct` is a boolean column. Comparing it to the integer 1
            // works on sqlite (which stores booleans as 0/1) and throws
            // "operator does not exist: boolean = integer" on Postgres, which
            // is what production runs. Testing the column directly is correct
            // on both — and COUNT skips the NULLs that ungraded theory answers
            // leave behind, which SUM(... ELSE 0) counted as wrong.
            ->selectRaw('COUNT(CASE WHEN is_correct THEN 1 END) as correct')
            ->groupBy('question_id')
            ->get()
            ->map(fn ($row) => [
                'question_id' => $row->question_id,
                'answered' => (int) $row->answered,
                'correct' => (int) $row->correct,
                // Classical p-value. A very low p on taught material usually
                // means the question is broken, not the class.
                'facility_index' => $row->answered > 0 ? round($row->correct / $row->answered, 4) : null,
            ]);

        return response()->json([
            'exam' => ['id' => $exam->id, 'title' => $exam->title, 'total_marks' => (float) $exam->total_marks],
            'summary' => [
                'attempts' => $attempts->count(),
                'awaiting_marking' => $attempts->where('requires_manual_grading', true)->count(),
                'average_percentage' => $percentages->count() ? round($percentages->avg(), 2) : null,
                'highest' => $percentages->max(),
                'lowest' => $percentages->min(),
                'pass_rate' => $percentages->count()
                    ? round(($percentages->filter(fn ($p) => $p >= (float) $exam->pass_mark)->count() / $percentages->count()) * 100, 2)
                    : null,
            ],
            'candidates' => $attempts->map(fn (CbtAttempt $a) => [
                'attempt_id' => $a->id,
                'student_id' => $a->student_id,
                'student_name' => $a->student?->user?->name,
                'percentage' => (float) $a->percentage,
                'grade' => $a->grade,
                'requires_manual_grading' => (bool) $a->requires_manual_grading,
                'integrity_flags' => $a->integrity_flags,
            ])->values(),
            'item_analysis' => $itemAnalysis,
        ]);
    }

    // ==================================================================
    // Offline exam client (§7.8)
    // ==================================================================

    /**
     * Sync a batch from the offline lab client.
     *
     * This used to write to a `student_attempts` table that no migration ever
     * created, with its own hand-rolled ordering logic. It now runs through
     * the same `recordAnswers` path as the live client — one code path, so an
     * offline batch cannot take a shortcut the online path forbids.
     */
    public function syncOfflineAnswers(Request $request)
    {
        $validated = $request->validate([
            'attempt_id' => 'required|integer',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.response' => 'nullable',
            'answers.*.client_timestamp' => 'nullable|date',
            'answers.*.client_sequence' => 'nullable|integer|min:0',
            'submit' => 'nullable|boolean',
        ]);

        $attempt = CbtAttempt::find($validated['attempt_id']);

        if (! $attempt) {
            return response()->json(['error' => 'Exam attempt not found.'], 404);
        }

        $this->authorize('sit', $attempt);

        if ($attempt->isFinalised()) {
            return response()->json([
                'status' => 'ignored',
                'message' => 'Attempt has already been finalised and submitted.',
                'synced_count' => 0,
            ]);
        }

        $result = $this->exams->recordAnswers($attempt, $validated['answers'], fromOfflineClient: true);

        // The lab client submits explicitly once it reconnects; a batch that
        // arrives after the deadline is still saved, then closed out.
        if (($validated['submit'] ?? false) || $attempt->hasExpired()) {
            $attempt = $this->exams->submitAttempt(
                $attempt,
                ($validated['submit'] ?? false) ? 'manual_submit' : 'auto_submit'
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => "Successfully synchronized {$result['saved']} offline exam answers.",
            'synced_count' => $result['saved'],
            'ignored_count' => $result['ignored'],
            'rejected' => $result['rejected'],
            'attempt_status' => $attempt->status,
        ]);
    }

    /**
     * Everything the offline lab client needs to run a paper with no network:
     * questions, media manifest, and the deadline it must honour.
     */
    /**
     * What to cache before exam day.
     *
     * Two callers with the same need and very different rights, so this splits
     * on role rather than opening the staff payload to candidates (gap G4).
     * Staff are provisioning a room of lab machines and see the paper's shape;
     * a candidate is pre-caching their own paper's images over the school's
     * wifi so the exam does not die on the diagrams when the signal does.
     */
    public function offlinePackage(Request $request, $examId)
    {
        $profile = $request->user()->userProfile;

        if ($profile && $profile->role === 'student') {
            return $this->candidateOfflinePackage($request, $examId);
        }

        $exam = CbtExam::where('school_id', $this->schoolId($request))->findOrFail($examId);
        $this->authorize('view', $exam);

        if (! $exam->allow_offline) {
            return response()->json(['error' => 'This exam is not marked as available offline.'], 403);
        }

        $questions = $this->examQuestions($exam);

        return response()->json([
            'exam' => $exam->only(['id', 'title', 'instructions', 'duration_minutes', 'max_attempts', 'pass_mark']),
            'question_count' => $questions->count(),
            'media_manifest' => $this->media->buildOfflineManifest($questions),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * The candidate's half of the same thing.
     *
     * Deliberately **media only**. The manifest is checksums, byte sizes and
     * asset URLs — it carries no question text, no options and no correct
     * answers, which is what makes it safe to hand a candidate before the paper
     * opens. The questions themselves still arrive only from `startAttempt`,
     * through `buildCandidatePaper`, after the exam has opened and the attempt
     * exists. There is no second, laxer way into a paper.
     *
     * Available before `opens_at` on purpose: downloading fourteen megabytes of
     * diagrams over the school's wifi the day before is the entire point, and a
     * candidate who can only fetch it once the exam has started has gained
     * nothing.
     */
    private function candidateOfflinePackage(Request $request, $examId)
    {
        $student = $this->currentStudent($request);

        if (! $student) {
            return response()->json(['error' => 'Student record not found.'], 404);
        }

        $exam = CbtExam::where('school_id', $student->school_id)->findOrFail($examId);

        // Class binding, exactly as when sitting: an SS3 mock is not
        // pre-downloadable by a JSS1 pupil who guessed the id.
        if (! $request->user()->can('sit', [$exam, $student])) {
            return response()->json(['error' => 'This exam was not set for your class.'], 403);
        }

        // A draft paper is not a paper yet. Its media would give away what is
        // coming, and it may still change before it is published.
        if ($exam->status !== 'published') {
            return response()->json(['error' => 'This exam is not available yet.'], 403);
        }

        if ($exam->closes_at && $exam->closes_at->isPast()) {
            return response()->json(['error' => 'This exam has closed.'], 403);
        }

        if (! $exam->allow_offline) {
            return response()->json([
                'error' => 'This exam must be sat online. Ask your school if you expect to be offline.',
            ], 403);
        }

        $questions = $this->examQuestions($exam);

        return response()->json([
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'instructions' => $exam->instructions,
                'duration_minutes' => $exam->duration_minutes,
                'opens_at' => $exam->opens_at?->toIso8601String(),
                'closes_at' => $exam->closes_at?->toIso8601String(),
            ],
            'question_count' => $questions->count(),
            'media_manifest' => $this->media->buildOfflineManifest($questions),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function examQuestions(CbtExam $exam)
    {
        return QuestionBankItem::withoutGlobalScopes()
            ->whereIn('id', CbtExamQuestion::where('exam_id', $exam->id)->pluck('question_id'))
            ->get();
    }
}
