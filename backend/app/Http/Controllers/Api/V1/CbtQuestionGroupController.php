<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CbtExamQuestion;
use App\Models\CbtQuestionGroup;
use App\Models\QuestionBankItem;
use App\Services\CbtMediaService;
use App\Services\MathContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Grouped questions (docs/offline-cbt-client.md §6.2, §9.4).
 *
 * A comprehension passage with six sub-questions, a data table with four
 * calculations, a diagram with three labelling tasks. The bank had no concept
 * of this: every item stood alone, which is fine for objectives and wrong for
 * most of a Nigerian English or Physics paper.
 *
 * This is platform work, not offline work — the web runner and the mobile app
 * want it too. It is being built now because a flat shuffle scatters a
 * passage's sub-questions across a paper, and a bundle carried into a room
 * with no internet is a bad place to discover that.
 */
class CbtQuestionGroupController extends Controller
{
    public function __construct(private CbtMediaService $media)
    {
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

    public function index(Request $request)
    {
        $query = CbtQuestionGroup::where('school_id', $this->schoolId($request))
            ->with('subject:id,name')
            ->withCount('questions');

        foreach (['subject_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->input('search') . '%');
        }

        return response()->json(
            $query->orderByDesc('id')->paginate(min((int) $request->input('per_page', 25), 100))
        );
    }

    public function show(Request $request, $id)
    {
        $group = CbtQuestionGroup::where('school_id', $this->schoolId($request))
            ->with('subject:id,name')
            ->findOrFail($id);

        return response()->json([
            'group' => $group,
            'questions' => $group->questions()->get()->map(fn (QuestionBankItem $q) => [
                'id' => $q->id,
                'question' => $q->question,
                'question_type' => $q->question_type,
                'group_sequence' => $q->group_sequence,
                'marks' => (float) $q->marks,
            ])->values(),
        ]);
    }

    public function store(Request $request, MathContentService $maths)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'subject_id' => ['nullable', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'title' => 'required|string|max:255',
            'stimulus' => 'nullable|string',
            'instructions' => 'nullable|string|max:1000',
            'content_format' => 'nullable|in:plain,latex',
            'media' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        try {
            $validated['content_format'] = $this->resolveFormat($maths, $validated);
            $validated['media'] = $this->normaliseMedia($validated['media'] ?? null, $schoolId);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $group = CbtQuestionGroup::create(array_merge($validated, [
            'school_id' => $schoolId,
            'created_by' => $request->user()->id,
        ]));

        return response()->json(['message' => 'Question group created', 'group' => $group], 201);
    }

    public function update(Request $request, $id, MathContentService $maths)
    {
        $schoolId = $this->schoolId($request);
        $group = CbtQuestionGroup::where('school_id', $schoolId)->findOrFail($id);

        $validated = $request->validate([
            'subject_id' => ['nullable', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'title' => 'sometimes|string|max:255',
            'stimulus' => 'nullable|string',
            'instructions' => 'nullable|string|max:1000',
            'content_format' => 'nullable|in:plain,latex',
            'media' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        // Editing the passage under a candidate who is reading it is the same
        // offence as editing a question mid-exam, and `updateQuestion` already
        // refuses that. A stimulus is arguably worse: the six sub-questions
        // stay valid-looking while the text they refer to has changed.
        if ($this->isLive($group) && array_intersect(array_keys($validated), ['stimulus', 'media', 'content_format'])) {
            return response()->json([
                'error' => 'This group is attached to a published exam. Retire its questions and add a replacement rather than editing the stimulus in place.',
            ], 409);
        }

        try {
            $merged = array_merge([
                'stimulus' => $group->stimulus,
                'content_format' => $group->content_format,
            ], $validated);

            $validated['content_format'] = $this->resolveFormat($maths, $merged);

            if (array_key_exists('media', $validated)) {
                $validated['media'] = $this->normaliseMedia($validated['media'], $schoolId);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $group->update($validated);

        return response()->json(['message' => 'Question group updated', 'group' => $group->fresh()]);
    }

    /**
     * Delete a group.
     *
     * Deliberately not a cascade. Deleting a passage that six questions still
     * point at could either delete the questions (losing authored work and
     * orphaning attempt history) or silently null the link (turning "part (c)
     * of a comprehension" into a standalone question that reads as nonsense).
     * Neither should happen by accident, so the default is a refusal and the
     * caller has to say which one they meant.
     */
    public function destroy(Request $request, $id)
    {
        $schoolId = $this->schoolId($request);
        $group = CbtQuestionGroup::where('school_id', $schoolId)->findOrFail($id);

        $attached = QuestionBankItem::where('school_id', $schoolId)->where('group_id', $group->id)->get();

        if ($attached->isNotEmpty() && ! $request->boolean('detach')) {
            return response()->json([
                'error' => sprintf(
                    '%d question(s) belong to this group. Move them out first, or repeat with detach=true to return them to the bank as standalone questions.',
                    $attached->count()
                ),
                'question_ids' => $attached->pluck('id')->values(),
            ], 409);
        }

        if ($this->isLive($group)) {
            return response()->json([
                'error' => 'This group is attached to a published exam and cannot be deleted while candidates can sit it.',
            ], 409);
        }

        DB::transaction(function () use ($group, $schoolId) {
            QuestionBankItem::where('school_id', $schoolId)
                ->where('group_id', $group->id)
                ->update(['group_id' => null, 'group_sequence' => null]);

            $group->delete();
        });

        return response()->json([
            'message' => 'Question group deleted',
            'questions_detached' => $attached->count(),
        ]);
    }

    /** Is any question in this group on a published paper right now? */
    private function isLive(CbtQuestionGroup $group): bool
    {
        $questionIds = QuestionBankItem::where('group_id', $group->id)->pluck('id');

        if ($questionIds->isEmpty()) {
            return false;
        }

        return CbtExamQuestion::whereIn('question_id', $questionIds)
            ->whereHas('exam', fn ($q) => $q->where('status', 'published'))
            ->exists();
    }

    /**
     * A stimulus is rendered by the same KaTeX the questions use, so it is
     * validated by the same rules — a passage with broken LaTeX must be caught
     * at authoring time, not by forty candidates at once.
     *
     * @throws \InvalidArgumentException
     */
    private function resolveFormat(MathContentService $maths, array $input): string
    {
        $format = $maths->resolveFormat(
            $input['content_format'] ?? null,
            (string) ($input['stimulus'] ?? '')
        );

        if ($format === 'latex') {
            $maths->assertValid($input['stimulus'] ?? null, 'the stimulus');
        }

        return $format;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function normaliseMedia(?array $media, int $schoolId): ?array
    {
        // A group's images are stimulus images — a map, a circuit, a table
        // photographed from a textbook. `normaliseMediaReferences` keys its
        // role rules off question type; 'theory' is the permissive one that
        // allows a plain stem image without demanding option roles.
        return $this->media->normaliseMediaReferences($media, $schoolId, 'theory') ?: null;
    }
}
