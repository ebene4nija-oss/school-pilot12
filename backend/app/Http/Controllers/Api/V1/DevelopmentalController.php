<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DevelopmentalIndicator;
use App\Models\DevelopmentalRating;
use App\Models\SchoolClass;
use App\Models\Term;
use App\Services\Results\ResultCalculator;
use App\Support\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Nursery developmental assessment (broadsheet spec §7).
 *
 * Nursery children are rated against areas the school chooses — language,
 * social interaction, motor skills — on a four-point descriptive scale, with
 * the teacher's observations. No numeric average or class position is
 * produced here: the spec makes those optional for nursery, and the numeric
 * broadsheet already offers them where a school wants them.
 */
class DevelopmentalController extends Controller
{
    public const DEFAULT_INDICATORS = [
        'Language and communication',
        'Numeracy readiness',
        'Social interaction',
        'Emotional development',
        'Physical development',
        'Creativity',
        'Personal hygiene',
        'Classroom participation',
        'Motor skills',
    ];

    public function indicators(): JsonResponse
    {
        $schoolId = $this->schoolId();

        // A school starts with the spec's suggested areas, which it can rename,
        // reorder or switch off.
        if (! DevelopmentalIndicator::where('school_id', $schoolId)->exists()) {
            foreach (self::DEFAULT_INDICATORS as $i => $name) {
                DevelopmentalIndicator::create(['school_id' => $schoolId, 'name' => $name, 'order_index' => $i]);
            }
        }

        return response()->json([
            'data' => DevelopmentalIndicator::where('school_id', $schoolId)->orderBy('order_index')->orderBy('id')->get(),
            'ratings' => DevelopmentalIndicator::RATINGS,
        ]);
    }

    public function saveIndicators(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'indicators' => 'required|array|max:40',
            'indicators.*.id' => ['nullable', Rule::exists('developmental_indicators', 'id')->where('school_id', $schoolId)],
            'indicators.*.name' => 'required|string|max:100',
            'indicators.*.is_active' => 'boolean',
        ]);

        DB::transaction(function () use ($data, $schoolId) {
            foreach (array_values($data['indicators']) as $i => $row) {
                $attributes = ['name' => $row['name'], 'order_index' => $i, 'is_active' => $row['is_active'] ?? true];

                if (! empty($row['id'])) {
                    // Never deleted: a past term's ratings still point at it.
                    DevelopmentalIndicator::where('school_id', $schoolId)->whereKey($row['id'])->update($attributes);
                } else {
                    DevelopmentalIndicator::create($attributes + ['school_id' => $schoolId]);
                }
            }
        });

        return $this->indicators();
    }

    /** The class grid: one row per child, one column per active indicator. */
    public function sheet(Request $request, ResultCalculator $calculator): JsonResponse
    {
        [$schoolId, $term, $class] = $this->termAndClass($request);

        if (! $this->mayRate($request, $schoolId, $class)) {
            return response()->json(['message' => 'You can rate only the classes you teach.'], 403);
        }

        $indicators = DevelopmentalIndicator::where('school_id', $schoolId)->where('is_active', true)->orderBy('order_index')->get(['id', 'name']);
        $roster = $calculator->roster($schoolId, $term, $class->id);

        $ratings = DevelopmentalRating::where('school_id', $schoolId)
            ->where('term_id', $term->id)
            ->whereIn('student_id', $roster->pluck('id'))
            ->get()
            ->groupBy('student_id');

        $students = $roster->map(fn ($s) => [
            'id' => (int) $s->id,
            'name' => $s->name,
            'admission_number' => $s->admission_number,
            'ratings' => ($ratings->get($s->id) ?? collect())->mapWithKeys(fn ($r) => [$r->indicator_id => ['rating' => $r->rating, 'observation' => $r->observation]]),
        ])->values();

        $distribution = [];
        foreach ($indicators as $indicator) {
            $counts = array_fill_keys(array_keys(DevelopmentalIndicator::RATINGS), 0);
            foreach ($ratings->flatten() as $r) {
                if ($r->indicator_id === $indicator->id) {
                    $counts[$r->rating] = ($counts[$r->rating] ?? 0) + 1;
                }
            }
            $distribution[$indicator->id] = $counts;
        }

        return response()->json([
            'class' => ['id' => $class->id, 'name' => $class->name],
            'term' => ['id' => $term->id, 'name' => $term->name],
            'indicators' => $indicators,
            'scale' => DevelopmentalIndicator::RATINGS,
            'students' => $students,
            'distribution' => $distribution,
        ]);
    }

    public function saveRatings(Request $request, ResultCalculator $calculator): JsonResponse
    {
        [$schoolId, $term, $class] = $this->termAndClass($request);

        if (! $this->mayRate($request, $schoolId, $class)) {
            return response()->json(['message' => 'You can rate only the classes you teach.'], 403);
        }

        $data = $request->validate([
            'ratings' => 'required|array|max:2000',
            'ratings.*.student_id' => 'required|integer',
            'ratings.*.indicator_id' => ['required', Rule::exists('developmental_indicators', 'id')->where('school_id', $schoolId)],
            'ratings.*.rating' => ['required', Rule::in(array_keys(DevelopmentalIndicator::RATINGS))],
            'ratings.*.observation' => 'nullable|string|max:1000',
        ]);

        $roster = $calculator->roster($schoolId, $term, $class->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $outside = collect($data['ratings'])->pluck('student_id')->map(fn ($id) => (int) $id)->diff($roster);

        if ($outside->isNotEmpty()) {
            return response()->json(['message' => 'Some of these children are not in this class for the term.'], 422);
        }

        DB::transaction(function () use ($data, $schoolId, $term, $request) {
            foreach ($data['ratings'] as $row) {
                DevelopmentalRating::updateOrCreate(
                    ['term_id' => $term->id, 'student_id' => $row['student_id'], 'indicator_id' => $row['indicator_id']],
                    ['school_id' => $schoolId, 'rating' => $row['rating'], 'observation' => $row['observation'] ?? null, 'recorded_by' => $request->user()->id]
                );
            }
        });

        return response()->json(['message' => count($data['ratings']).' rating(s) saved.']);
    }

    private function mayRate(Request $request, int $schoolId, SchoolClass $class): bool
    {
        $user = $request->user();

        if (in_array($user->userProfile?->role, ['school_admin', 'super_admin'], true) || TeacherScope::seesWholeSchool($user)) {
            return true;
        }

        $placements = TeacherScope::placements($user);

        return in_array($class->id, $placements['classes'], true)
            || collect($placements['arms'])->contains(fn ($p) => $p[0] === $class->id);
    }

    /** @return array{0: int, 1: Term, 2: SchoolClass} */
    private function termAndClass(Request $request): array
    {
        $schoolId = $this->schoolId();
        $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
        ]);

        return [
            $schoolId,
            Term::where('school_id', $schoolId)->findOrFail($request->integer('term_id')),
            SchoolClass::where('school_id', $schoolId)->findOrFail($request->integer('class_id')),
        ];
    }
}
