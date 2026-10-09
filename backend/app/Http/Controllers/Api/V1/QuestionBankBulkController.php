<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\QuestionBankItem;
use App\Services\Cbt\QuestionBankFilter;
use App\Services\Cbt\QuestionVersioner;
use App\Support\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Organising the question bank many questions at a time.
 *
 * A bank of a few thousand WAEC-style questions cannot be filed one edit at
 * a time. The selection is either explicit ids (ticked rows) or "everything
 * matching these filters", resolved here with the same filters the list
 * uses, so the action reaches exactly the questions the teacher saw.
 *
 * Changing questions is limited to what the caller may change: their own,
 * their department's if they head one, any if they are an admin. Adding to a
 * paper is not a change to the question, so it follows the paper's rules
 * instead. Only filing fields move here — class, subject, topic, difficulty,
 * status — never the stem or the marking scheme, so a question on a live
 * paper can be filed without touching what candidates see.
 */
class QuestionBankBulkController extends Controller
{
    /** More than this at once is almost certainly a filter left off. */
    private const MAX = 2000;

    public const ACTIONS = ['set_class', 'set_subject', 'set_topic', 'set_difficulty', 'set_status', 'delete', 'add_to_exam'];

    public function __construct(private QuestionVersioner $versioner)
    {
    }

    public function bulk(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $user = $request->user();

        $data = $request->validate([
            'action' => ['required', Rule::in(self::ACTIONS)],
            'ids' => 'required_without:filters|array|max:'.self::MAX,
            'ids.*' => 'integer',
            'filters' => 'required_without:ids|array',
            'class_id' => ['required_if:action,set_class', 'nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required_if:action,set_subject', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'topic' => 'nullable|string|max:255',
            'difficulty' => 'required_if:action,set_difficulty|in:easy,medium,hard',
            'status' => 'required_if:action,set_status|in:draft,approved,retired',
            'exam_id' => ['required_if:action,add_to_exam', Rule::exists('cbt_exams', 'id')->where('school_id', $schoolId)],
        ]);

        $selected = QuestionBankItem::where('school_id', $schoolId);

        isset($data['ids'])
            ? $selected->whereIn('id', $data['ids'])
            : QuestionBankFilter::apply($selected, $schoolId, array_intersect_key($data['filters'], array_flip(QuestionBankFilter::FILTERS)));

        $total = (clone $selected)->count();

        if ($total > self::MAX) {
            return response()->json(['message' => "That matches {$total} questions. Narrow the filters to ".self::MAX.' or fewer.'], 422);
        }

        if ($data['action'] === 'add_to_exam') {
            return $this->addToExam(CbtExam::where('school_id', $schoolId)->findOrFail($data['exam_id']), $selected);
        }

        $allowed = QuestionBankFilter::editableBy(clone $selected, $user, $schoolId)->get();
        $skipped = $total - $allowed->count();
        $changed = 0;

        DB::transaction(function () use ($data, $allowed, $user, &$changed) {
            foreach ($allowed as $question) {
                $changed += $this->apply($data, $question, $user) ? 1 : 0;
            }
        });

        return response()->json([
            'message' => $this->outcome($data['action'], $changed, $skipped),
            'changed' => $changed,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Counts by class, subject and topic: the bank's table of contents.
     * Questions with no class come back under a null class (unsorted).
     */
    public function summary(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();

        $query = QuestionBankItem::where('school_id', $schoolId);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $rows = $query->selectRaw('class_id, subject_id, topic, count(*) as total')
            ->groupBy('class_id', 'subject_id', 'topic')
            ->get();

        $classes = DB::table('classes')->where('school_id', $schoolId)->orderBy('order_index')->orderBy('name')->get(['id', 'name', 'level_category']);
        $subjects = DB::table('subjects')->where('school_id', $schoolId)->pluck('name', 'id');

        return response()->json([
            'total' => (int) $rows->sum('total'),
            'unsorted' => (int) $rows->whereNull('class_id')->sum('total'),
            'classes' => $classes->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'level_group' => Sections::levelGroupOf($c->level_category),
                'total' => (int) $rows->where('class_id', $c->id)->sum('total'),
            ])->values(),
            'rows' => $rows->map(fn ($r) => [
                'class_id' => $r->class_id ? (int) $r->class_id : null,
                'subject_id' => (int) $r->subject_id,
                'subject' => $subjects[$r->subject_id] ?? null,
                'topic' => $r->topic,
                'total' => (int) $r->total,
            ])->values(),
        ]);
    }

    private function apply(array $data, QuestionBankItem $question, $user): bool
    {
        switch ($data['action']) {
            case 'delete':
                // Attempts reference a question on a paper; it is retired, not deleted.
                if (CbtExamQuestion::where('question_id', $question->id)->exists()) {
                    return $question->update(['status' => 'retired']);
                }

                return (bool) $question->delete();

            case 'set_topic':
            case 'set_difficulty':
                // Versioned fields: an answered question keeps what was answered.
                $field = $data['action'] === 'set_topic' ? 'topic' : 'difficulty';
                $this->versioner->update($question, [$field => $data[$field] ?? null], $user);

                return true;

            default:
                $field = ['set_class' => 'class_id', 'set_subject' => 'subject_id', 'set_status' => 'status'][$data['action']];

                return $question->update([$field => $data[$field] ?? null]);
        }
    }

    private function addToExam(CbtExam $exam, $selected): JsonResponse
    {
        $this->authorize('manage', $exam);

        if ($exam->status === 'published') {
            return response()->json(['error' => 'Cannot change the questions on a published exam.'], 409);
        }

        // Retired questions stay off new papers; ones already on it stay where they are.
        $onPaper = CbtExamQuestion::where('exam_id', $exam->id)->pluck('question_id')->all();
        $ids = (clone $selected)->where('status', '!=', 'retired')->whereNotIn('id', $onPaper ?: [0])->orderBy('id')->pluck('id');
        $next = (int) CbtExamQuestion::where('exam_id', $exam->id)->max('order_index');

        DB::transaction(function () use ($ids, $exam, &$next) {
            foreach ($ids as $id) {
                CbtExamQuestion::create(['exam_id' => $exam->id, 'question_id' => $id, 'order_index' => ++$next]);
            }
        });

        return response()->json([
            'message' => "Added {$ids->count()} question(s) to the paper.",
            'changed' => $ids->count(),
            'question_count' => CbtExamQuestion::where('exam_id', $exam->id)->count(),
            'total_marks' => $exam->recalculateTotalMarks(),
        ]);
    }

    private function outcome(string $action, int $changed, int $skipped): string
    {
        $verb = [
            'set_class' => 'Moved', 'set_subject' => 'Moved', 'set_topic' => 'Re-topicked',
            'set_difficulty' => 'Updated', 'set_status' => 'Updated', 'delete' => 'Deleted or retired',
        ][$action];

        return "{$verb} {$changed} question(s)."
            .($skipped ? " {$skipped} skipped: you can only change questions you wrote or your department's." : '');
    }
}
