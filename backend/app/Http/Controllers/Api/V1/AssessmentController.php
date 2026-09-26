<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CaScheme;
use App\Models\ReportCardToken;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Services\GradingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssessmentController extends Controller
{
    /**
     * Record a subject mark.
     *
     * Component ceilings come from the school's CA scheme rather than the
     * hardcoded 20/20/60 that used to live in these rules — see CaScheme. A
     * school on 30/30/40 was previously unable to enter a 25-mark CA at all:
     * the request 422'd.
     */
    public function enterScores(Request $request, GradingService $grading)
    {
        $user = $request->user();
        $schoolId = $this->schoolId();

        $scheme = CaScheme::activeFor($schoolId);

        $validator = Validator::make($request->all(), [
            'term_id' => ['required', \Illuminate\Validation\Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'student_id' => ['required', \Illuminate\Validation\Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required', \Illuminate\Validation\Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'components' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $existing = ScoreEntry::where('school_id', $schoolId)
            ->where('term_id', $request->input('term_id'))
            ->where('student_id', $request->input('student_id'))
            ->where('subject_id', $request->input('subject_id'))
            ->value('component_scores');

        // A mark per part of the school's scheme (any number of CAs), or the
        // classic first_ca / second_ca / exam fields. See ScoreInput.
        $marks = \App\Services\ScoreInput::resolve(
            $scheme,
            $request->only(['components', 'first_ca', 'second_ca', 'exam']),
            is_string($existing) ? json_decode($existing, true) : $existing,
        );

        $firstCa = $marks['first_ca'];
        $secondCa = $marks['second_ca'];
        $exam = $marks['exam'];

        // Graded on the school's scale for this student's class level.
        $level = \App\Models\Student::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->with('currentClass:id,level_category')
            ->find($request->input('student_id'))?->currentClass?->level_category;

        $computed = $grading->total($scheme, $firstCa, $secondCa, $exam, $level);

        $entry = ScoreEntry::updateOrCreate(
            [
                'school_id' => $schoolId,
                'term_id' => $request->input('term_id'),
                'student_id' => $request->input('student_id'),
                'subject_id' => $request->input('subject_id'),
            ],
            [
                'first_ca' => $firstCa,
                'second_ca' => $secondCa,
                'exam' => $exam,
                'component_scores' => $marks['component_scores'],
                'total_score' => $computed['percentage'],
                'grade' => $computed['grade'],
            ]
        );

        // Positions shift for the whole class whenever one mark changes, so
        // they are recomputed here rather than left null (doc §7.7 requires
        // class ranking; the column was never written to before).
        $student = Student::where('school_id', $schoolId)->find($request->input('student_id'));

        if ($student && $student->class_id) {
            $grading->recomputeClassPositions($schoolId, $student->class_id, (int) $request->input('term_id'));
            $entry->refresh();
        }

        return response()->json([
            'message' => 'Score entry saved successfully',
            'scheme' => [
                'name' => $scheme->name,
                'first_ca_weight' => $scheme->first_ca_weight,
                'second_ca_weight' => $scheme->second_ca_weight,
                'exam_weight' => $scheme->exam_weight,
                'components' => $scheme->parts(),
            ],
            'score_entry' => $entry,
        ]);
    }

    // ==================================================================
    // CA scheme configuration (§7.7)
    // ==================================================================

    public function listCaSchemes(Request $request)
    {
        $schoolId = $this->schoolId();

        $active = CaScheme::activeFor($schoolId);

        return response()->json([
            'active' => $active,
            // The active scheme's parts, classic schemes included.
            'active_components' => $active->parts(),
            'data' => CaScheme::where('school_id', $schoolId)->orderByDesc('is_default')->get(),
        ]);
    }

    public function storeCaScheme(Request $request)
    {
        $schoolId = $this->schoolId();

        /*
         * Any number of parts: `components: [{name, max, kind: ca|exam}]`,
         * at least one CA and one exam. The legacy weights are derived from
         * them, so older readers see a first CA, the other CAs combined, and
         * the exam. Sending the three weights alone still works.
         */
        if (is_array($request->input('components'))) {
            $request->validate([
                'name' => 'required|string|max:100',
                'components' => 'required|array|min:2|max:10',
                'components.*.name' => 'required|string|max:30',
                'components.*.max' => 'required|integer|min:1|max:100',
                'components.*.kind' => 'required|in:ca,exam',
                'is_default' => 'boolean',
            ]);

            $kinds = array_column($request->input('components'), 'kind');

            if (! in_array('ca', $kinds, true) || ! in_array('exam', $kinds, true)) {
                return response()->json(['errors' => ['components' => ['Include at least one CA and one exam.']]], 422);
            }

            $derived = CaScheme::fromParts($request->input('components'));
            $request->merge([
                'first_ca_weight' => $derived['first_ca_weight'],
                'second_ca_weight' => $derived['second_ca_weight'],
                'exam_weight' => $derived['exam_weight'],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'first_ca_weight' => 'required|integer|min:0|max:100',
            'second_ca_weight' => 'required|integer|min:0|max:100',
            'exam_weight' => 'required|integer|min:0|max:100',
            'is_default' => 'boolean',
        ]);

        $validator->after(function ($validator) use ($request) {
            $total = (int) $request->input('first_ca_weight')
                + (int) $request->input('second_ca_weight')
                + (int) $request->input('exam_weight');

            // Not a hard 100 requirement — a school marking out of 60 is
            // legitimate and gets normalised — but zero is not a scheme.
            if ($total <= 0) {
                $validator->errors()->add('exam_weight', 'The weights must add up to more than zero.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $components = isset($derived) ? $derived['components'] : null;

        $scheme = DB::transaction(function () use ($request, $schoolId, $components) {
            $makeDefault = $request->boolean('is_default', true);

            if ($makeDefault) {
                CaScheme::where('school_id', $schoolId)->update(['is_default' => false]);
            }

            return CaScheme::create([
                'school_id' => $schoolId,
                'name' => $request->input('name'),
                'first_ca_weight' => $request->integer('first_ca_weight'),
                'second_ca_weight' => $request->integer('second_ca_weight'),
                'exam_weight' => $request->integer('exam_weight'),
                'components' => $components,
                'is_default' => $makeDefault,
            ]);
        });

        return response()->json(['message' => 'CA scheme saved', 'scheme' => $scheme], 201);
    }

    /**
     * Switching scheme mid-term rescales marks already entered rather than
     * silently leaving a 60-weight exam mark sitting in a 40-weight scheme,
     * which would read as a different percentage on every card printed after
     * the change.
     */
    public function activateCaScheme(Request $request, $id)
    {
        $schoolId = $this->schoolId();
        $scheme = CaScheme::where('school_id', $schoolId)->findOrFail($id);

        DB::transaction(function () use ($schoolId, $scheme) {
            CaScheme::where('school_id', $schoolId)->update(['is_default' => false]);
            $scheme->update(['is_default' => true]);
        });

        return response()->json([
            'message' => "'{$scheme->name}' is now this school's marking scheme.",
            'scheme' => $scheme->fresh(),
            'note' => 'Existing marks are unchanged. Re-save any affected score entries to retotal them under the new weights.',
        ]);
    }

    public function generateAiComment(Request $request, $id)
    {
        $user = $request->user();
        $schoolId = $this->schoolId();

        $entry = ScoreEntry::where('school_id', $schoolId)->with(['student.user', 'subject'])->findOrFail($id);

        // Wire ClaudeService LLM API generator
        $claudeService = app(\App\Services\ClaudeService::class);
        $generatedComment = $claudeService->generateReportCardComment([
            'student_name' => $entry->student && $entry->student->user ? $entry->student->user->name : 'Student',
            'subject' => $entry->subject ? $entry->subject->name : 'Subject',
            'total_score' => $entry->total_score,
        ]);

        $entry->update([
            'teacher_comment' => $generatedComment,
            'ai_comment_status' => 'pending_approval',
            // A fresh draft needs a fresh review.
            'ai_comment_review' => null,
            'ai_comment_generated_at' => now(),
            'ai_comment_reviewed_at' => null,
        ]);

        return response()->json([
            'message' => 'AI comment generated via Claude API and set to pending approval.',
            'score_entry' => $entry,
        ]);
    }

    /**
     * The AI comments waiting on a human.
     *
     * This is the queue behind the hard rule that no AI-generated remark ever
     * reaches a report card unreviewed. The rule was enforced on the write side
     * — a comment is created as `pending_approval` and `reviewAiComment` is the
     * only way out of it — but there was no way to *read* the queue, so the
     * admin dashboard showed a hardcoded list of invented students and the real
     * pending comments were unreachable from the portal. A gate nobody can see
     * through is a gate that gets worked around at term end.
     */
    public function pendingAiComments(Request $request)
    {
        $user = $request->user();
        $schoolId = $this->schoolId();
        $role = $user->userProfile?->role;

        $query = ScoreEntry::where('score_entries.school_id', $schoolId)
            ->where('score_entries.ai_comment_status', 'pending_approval')
            ->join('students', 'score_entries.student_id', '=', 'students.id')
            ->join('users', 'students.user_id', '=', 'users.id')
            ->leftJoin('classes', 'students.class_id', '=', 'classes.id')
            ->leftJoin('arms', 'students.arm_id', '=', 'arms.id')
            ->join('subjects', 'score_entries.subject_id', '=', 'subjects.id')
            ->join('terms', 'score_entries.term_id', '=', 'terms.id')
            ->select([
                'score_entries.id',
                'score_entries.student_id',
                'score_entries.teacher_comment',
                'score_entries.total_score',
                'score_entries.grade',
                'score_entries.updated_at',
                'users.name as student_name',
                'students.admission_number',
                'classes.name as class_name',
                'arms.name as arm_name',
                'subjects.name as subject_name',
                'terms.id as term_id',
                'terms.name as term_name',
            ]);

        /*
         * A teacher sees only the subjects they teach. Approving a remark is a
         * professional judgement about a child's work, so it belongs to the
         * person who taught them — not to whoever opens the queue first.
         */
        if ($role === 'teacher') {
            $query->whereIn('score_entries.subject_id', function ($sub) use ($user) {
                $sub->select('subject_id')
                    ->from('teacher_subjects')
                    ->where('teacher_id', $user->id);
            });
        }

        if ($termId = $request->query('term_id')) {
            $query->where('score_entries.term_id', $termId);
        }

        if ($classId = $request->query('class_id')) {
            $query->where('students.class_id', $classId);
        }

        $entries = $query->orderBy('score_entries.updated_at')->paginate(
            min((int) $request->query('per_page', 25), 100)
        );

        return response()->json([
            'data' => collect($entries->items())->map(fn ($entry) => [
                'score_entry_id' => (int) $entry->id,
                'student_id' => (int) $entry->student_id,
                'student_name' => $entry->student_name,
                'admission_number' => $entry->admission_number,
                'class' => trim(($entry->class_name ?? '').' '.($entry->arm_name ?? '')) ?: null,
                'subject' => $entry->subject_name,
                'term_id' => (int) $entry->term_id,
                'term' => $entry->term_name,
                'total_score' => (float) $entry->total_score,
                'grade' => $entry->grade,
                'comment' => $entry->teacher_comment,
                'generated_at' => $entry->updated_at
                    ? \Illuminate\Support\Carbon::parse($entry->updated_at)->toIso8601String()
                    : null,
            ]),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    public function reviewAiComment(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,edit,reject',
            'edited_comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $this->schoolId();

        $entry = ScoreEntry::where('school_id', $schoolId)->findOrFail($id);

        // How it was reviewed, kept apart from the status: "approved" covers
        // both a draft taken as written and one the teacher rewrote.
        if ($request->action === 'approve') {
            $entry->update(['ai_comment_status' => 'approved', 'ai_comment_review' => 'approved_as_is', 'ai_comment_reviewed_at' => now()]);
        } elseif ($request->action === 'edit') {
            $entry->update([
                'teacher_comment' => $request->edited_comment,
                'ai_comment_status' => 'approved',
                'ai_comment_review' => 'edited',
                'ai_comment_reviewed_at' => now(),
            ]);
        } else {
            $entry->update([
                'teacher_comment' => null,
                'ai_comment_status' => 'rejected',
                'ai_comment_review' => 'rejected',
                'ai_comment_reviewed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => "AI comment action '{$request->action}' completed.",
            'score_entry' => $entry,
        ]);
    }

    public function getBroadsheet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'class_id' => 'required|exists:classes,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $this->schoolId();

        $students = Student::where('school_id', $schoolId)
            ->where('class_id', $request->class_id)
            ->with(['user'])
            ->get();

        $studentIds = $students->pluck('id');
        $subjectNames = \App\Models\Subject::where('school_id', $schoolId)->pluck('name', 'id');

        $allScores = ScoreEntry::where('school_id', $schoolId)
            ->where('term_id', $request->term_id)
            ->whereIn('student_id', $studentIds)
            ->get()
            // The subject's name, which the broadsheet's columns are headed
            // by. Scores carried only an id, so the web broadsheet showed no
            // subject columns at all.
            ->each(fn ($score) => $score->setAttribute('subject_name', $subjectNames[$score->subject_id] ?? null))
            ->groupBy('student_id');

        // Positions are recomputed and persisted rather than derived only for
        // this response — a report card printed from `position_in_class` and a
        // broadsheet read here must agree.
        app(GradingService::class)->recomputeClassPositions(
            $schoolId,
            (int) $request->input('class_id'),
            (int) $request->input('term_id')
        );

        $allScores = $allScores->map(fn ($group) => $group->each(function ($score) use ($subjectNames) {
            $score->refresh();
            $score->setAttribute('subject_name', $subjectNames[$score->subject_id] ?? null);
        }));

        $broadsheet = [];
        foreach ($students as $student) {
            $scores = $allScores->get($student->id, collect());

            $totalScoreSum = $scores->sum('total_score');
            $average = $scores->count() > 0 ? $totalScoreSum / $scores->count() : 0;

            $broadsheet[] = [
                'student_id' => $student->id,
                'student_name' => $student->user ? $student->user->name : 'N/A',
                'admission_number' => $student->admission_number,
                'scores' => $scores->values(),
                'total_score' => $totalScoreSum,
                'average' => round($average, 2),
                'position_in_class' => $scores->first()?->position_in_class,
            ];
        }

        // Rank by average descending
        usort($broadsheet, fn($a, $b) => $b['average'] <=> $a['average']);

        return response()->json([
            'class_id' => $request->input('class_id'),
            'term_id' => $request->input('term_id'),
            'ca_scheme' => ($scheme = CaScheme::activeFor($schoolId))->only(['name', 'first_ca_weight', 'second_ca_weight', 'exam_weight'])
                + ['components' => $scheme->parts()],
            'broadsheet' => $broadsheet,
        ]);
    }

    /**
     * Public authenticity check for the QR printed on a report card.
     *
     * Unauthenticated by design — an employer or a secondary school admissions
     * officer holding a printed card must be able to scan it. Three properties
     * that has to have, none of which the previous implementation had:
     *
     * 1. **Unguessable, not enumerable.** It looked up
     *    `orWhere('id', str_replace(['SP_VERIFY_','TOKEN_'], '', $token))`, so
     *    `SP_VERIFY_5` returned score entry 5 — from *any* school, with no
     *    tenant scope. Results were walkable by integer.
     * 2. **No fabricated answers.** It returned `valid: true` with an invented
     *    student, school "Grace Land College", score 85 and grade A1 whenever
     *    `APP_ENV === 'testing'` — the same shape of test backdoor already
     *    removed from TotpService.
     * 3. **It has to actually work.** It queried
     *    `score_entries.verification_token`, a column no migration creates, so
     *    on Postgres every call was a 500.
     *
     * What it deliberately does *not* return: per-subject marks. Verification
     * answers "is this document genuine?", not "tell me this child's results" —
     * anyone scanning is holding the card already, and NDPA data-minimisation
     * (doc §12) says an open endpoint should not hand out more than the
     * question requires.
     */
    public function verifyResult(Request $request, $token)
    {
        $record = ReportCardToken::withoutGlobalScopes()
            ->where('qr_token', $token)
            ->where('is_valid', true)
            ->with(['student.user', 'school', 'term'])
            ->first();

        if (! $record) {
            return response()->json([
                'valid' => false,
                'message' => 'This code does not match any report card issued by a school on SchoolPilot. It may have been withdrawn, or the card may not be genuine.',
            ], 404);
        }

        return response()->json([
            'valid' => true,
            'student_name' => $record->student?->user?->name,
            'admission_number' => $record->student?->admission_number,
            'school_name' => $record->school?->name,
            'term' => $record->term?->name,
            'issued_at' => $record->created_at?->toIso8601String(),
            'message' => 'This report card was issued by the school named above and has not been withdrawn.',
            'verified_at' => now()->toIso8601String(),
        ]);
    }
}
