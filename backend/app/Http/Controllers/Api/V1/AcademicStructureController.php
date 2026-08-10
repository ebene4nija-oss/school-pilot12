<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The two pickers every other screen depends on (gap G12).
 *
 * Nothing published a school's classes or its terms. `GET /classes/{id}/subjects`
 * existed, which is only useful once you already hold a class id — so a register,
 * a score sheet, a broadsheet or a result release had no way to ask "which class,
 * which term?" without the client hardcoding ids. The web app derived classes
 * from the teacher's own subject assignments, which works for teachers and for
 * nobody else; terms had no fallback at all.
 *
 * Both are reference data about the school, not about any child, so neither
 * returns anything that touches NDPA §12 personal data.
 */
class AcademicStructureController extends Controller
{
    /**
     * The school's classes, in teaching order, with their arms.
     *
     * `order_index` is what makes JSS 1 sort before JSS 2 and SS 1 after JSS 3 —
     * alphabetical ordering puts SS 1 first, which is wrong on every picker in
     * the product. It defaults to 0, so a school that never set it falls through
     * to the name and still gets a stable list rather than insertion order.
     */
    public function classes(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $armsByClass = Arm::where('school_id', $schoolId)
            ->orderBy('name')
            ->get()
            ->groupBy('class_id');

        // One grouped count rather than a count query per class.
        $rollByClass = Student::where('school_id', $schoolId)
            ->where('status', 'active')
            ->selectRaw('class_id, count(*) as total')
            ->groupBy('class_id')
            ->pluck('total', 'class_id');

        $classes = SchoolClass::where('school_id', $schoolId)
            ->orderBy('order_index')
            ->orderBy('name')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'id' => $class->id,
                'name' => $class->name,
                'level_category' => $class->level_category,
                'order_index' => $class->order_index,
                'student_count' => (int) ($rollByClass[$class->id] ?? 0),
                'arms' => $armsByClass->get($class->id, collect())
                    ->map(fn (Arm $arm) => ['id' => $arm->id, 'name' => $arm->name])
                    ->values(),
            ]);

        return response()->json(['data' => $classes]);
    }

    /**
     * The school's terms, newest first, with the current one flagged.
     *
     * `current_term_id` is lifted out of the list deliberately. Every caller
     * wants it — a register defaults to today's term, a score sheet defaults to
     * today's term — and making each client scan the array for `is_current`
     * invites each of them to disagree about what happens when no term is
     * marked. Here it is null, once, in one place.
     *
     * The `is_current` returned here is **computed** rather than read off the
     * column of the same name; see `resolveCurrentTerm()` for why.
     */
    public function terms(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $sessions = AcademicSession::where('school_id', $schoolId)->get()->keyBy('id');

        $terms = Term::where('school_id', $schoolId)
            ->orderByDesc('start_date')
            ->get();

        $current = $this->resolveCurrentTerm($terms);

        $data = $terms->map(function (Term $term) use ($sessions, $current) {
            $session = $sessions->get($term->session_id);

            return [
                'id' => $term->id,
                'name' => $term->name,
                'session_id' => $term->session_id,
                'session_name' => $session?->name,
                'start_date' => $term->start_date?->toDateString(),
                'end_date' => $term->end_date?->toDateString(),
                'is_current' => $current !== null && $current->id === $term->id,
            ];
        })->values();

        /*
         * The session follows the term rather than being resolved on its own.
         * A term belongs to exactly one session, so agreeing with the term we
         * just picked is the only answer that cannot contradict it.
         */
        $currentSession = ($current ? $sessions->get($current->session_id) : null)
            ?? $sessions->firstWhere('is_current', true);

        return response()->json([
            'data' => $data,
            'current_term_id' => $current?->id,
            'current_session' => $currentSession
                ? ['id' => $currentSession->id, 'name' => $currentSession->name]
                : null,
        ]);
    }

    /**
     * Which term "now" belongs to.
     *
     * The `terms.is_current` column exists but nothing in the product ever
     * writes it — there is no route, no job and no admin screen that sets it,
     * so on a real school's data it is `false` on every row and a picker that
     * trusted it would open with no default at all. The dates, by contrast,
     * are entered when the term is created and cannot drift.
     *
     * So: the term today falls inside. Failing that, whatever an admin pinned,
     * because a hand-set flag is still a deliberate statement and outranks a
     * guess. Failing that — and this is the Nigerian long vacation, roughly
     * July to September, when today is inside no term at all — the term that
     * most recently started, since a school on holiday is finishing results
     * for the term that just ended, not preparing one that has not begun.
     */
    private function resolveCurrentTerm(Collection $terms): ?Term
    {
        $today = now()->startOfDay();

        $containing = $terms->first(
            fn (Term $term) => $term->start_date && $term->end_date
                && $today->gte($term->start_date) && $today->lte($term->end_date)
        );

        if ($containing) {
            return $containing;
        }

        // `$terms` arrives ordered by start_date descending, so the first row
        // that has already started is the most recent one.
        return $terms->firstWhere('is_current', true)
            ?? $terms->first(fn (Term $term) => $term->start_date && $term->start_date->lte($today));
    }

    private function schoolId(Request $request): ?int
    {
        $profile = $request->user()->userProfile;

        return $profile ? (int) $profile->school_id : null;
    }
}
