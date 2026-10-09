<?php

namespace App\Services\Finance;

use App\Models\Term;
use App\Support\Sections;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who has paid and who is owing for a term, by section, class and arm.
 *
 * The defaulter list answers "who owes"; a bursar walking into a principal's
 * office needs the other half too: how much SS 2B has paid against what it
 * was billed, how many children have cleared, and who has not even been
 * invoiced. Every student on the roll appears once, with one status:
 *
 *  - paid          billed and fully paid
 *  - part_paid     paid something, still owes
 *  - owing         billed, paid nothing
 *  - not_invoiced  on the roll, no invoice for the term
 *
 * Placement is the class and arm the student sat in that session (from
 * enrolments), else where they sit now, so a past term reports against the
 * class the money was owed in. Amounts are Naira, as invoices store them.
 */
class FeeStatusReport
{
    public const STATUSES = ['paid', 'part_paid', 'owing', 'not_invoiced'];

    /**
     * @param  array{section?: string|null, class_id?: int|null, arm_id?: int|null, status?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function build(int $schoolId, Term $term, array $filters = []): array
    {
        $classes = DB::table('classes')->where('school_id', $schoolId)->orderBy('order_index')->orderBy('name')->get(['id', 'name', 'level_category'])->keyBy('id');
        $arms = DB::table('arms')->where('school_id', $schoolId)->orderBy('name')->get(['id', 'class_id', 'name'])->keyBy('id');

        $students = $this->roll($schoolId, $term)
            ->filter(fn ($s) => $s->class_id && $classes->has($s->class_id))
            ->map(function ($s) use ($classes) {
                $s->section = Sections::sectionOf(Sections::levelGroupOf($classes[$s->class_id]->level_category));

                return $s;
            });

        $students = $students
            ->when($filters['section'] ?? null, fn ($c, $v) => $c->where('section', $v))
            ->when($filters['class_id'] ?? null, fn ($c, $v) => $c->where('class_id', (int) $v))
            ->when($filters['arm_id'] ?? null, fn ($c, $v) => $c->where('arm_id', (int) $v));

        $invoices = DB::table('invoices')
            ->where('school_id', $schoolId)
            ->where('term_id', $term->id)
            ->whereIn('student_id', $students->pluck('id')->all() ?: [0])
            ->selectRaw('student_id, SUM(total_amount) as billed, SUM(amount_paid) as paid')
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $rows = $students->map(function ($s) use ($invoices, $classes, $arms) {
            $inv = $invoices->get($s->id);
            $billed = $inv ? round((float) $inv->billed, 2) : 0.0;
            $paid = $inv ? round((float) $inv->paid, 2) : 0.0;
            $balance = round(max(0, $billed - $paid), 2);

            return [
                'student_id' => (int) $s->id,
                'name' => $s->name,
                'admission_number' => $s->admission_number,
                'section' => $s->section,
                'class_id' => (int) $s->class_id,
                'class' => $classes[$s->class_id]->name,
                'arm_id' => $s->arm_id ? (int) $s->arm_id : null,
                'arm' => $s->arm_id ? $arms->get($s->arm_id)?->name : null,
                'billed' => $billed,
                'paid' => $paid,
                'balance' => $balance,
                'status' => match (true) {
                    ! $inv => 'not_invoiced',
                    $balance <= 0 => 'paid',
                    $paid > 0 => 'part_paid',
                    default => 'owing',
                },
            ];
        })->values();

        $sections = $rows->groupBy('section')->map(fn ($group, $section) => ['section' => $section, 'label' => Sections::SECTIONS[$section] ?? $section] + $this->totals($group));

        $byClass = $rows->groupBy('class_id')->map(function ($group, $classId) use ($classes) {
            $byArm = $group->groupBy(fn ($r) => $r['arm_id'] ?? 0)->map(fn ($g) => [
                'arm_id' => $g->first()['arm_id'],
                'arm' => $g->first()['arm'] ?? 'No arm',
            ] + $this->totals($g))->sortBy('arm')->values();

            return [
                'class_id' => (int) $classId,
                'class' => $classes[$classId]->name,
                'section' => $group->first()['section'],
                'arms' => $byArm,
            ] + $this->totals($group);
        });

        // Teaching order, not the order rows happened to arrive in.
        $byClass = $classes->keys()->filter(fn ($id) => $byClass->has($id))->map(fn ($id) => $byClass[$id])->values();

        $listed = ($filters['status'] ?? null) ? $rows->where('status', $filters['status'])->values() : $rows;

        return [
            'term' => ['id' => (int) $term->id, 'name' => $term->name],
            'currency' => 'NGN',
            'totals' => $this->totals($rows),
            'sections' => collect(array_keys(Sections::SECTIONS))->filter(fn ($s) => $sections->has($s))->map(fn ($s) => $sections[$s])->values(),
            'classes' => $byClass,
            'students' => $listed->sortBy([['class', 'asc'], ['arm', 'asc'], ['name', 'asc']])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function totals(Collection $rows): array
    {
        $billed = round($rows->sum('billed'), 2);
        $paid = round($rows->sum('paid'), 2);

        return [
            'students' => $rows->count(),
            'billed' => $billed,
            'paid' => $paid,
            'outstanding' => round($rows->sum('balance'), 2),
            'collection_rate' => $billed > 0 ? round($paid / $billed * 100, 1) : null,
            'counts' => collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => $rows->where('status', $s)->count()])->all(),
        ];
    }

    /** Active students and where they sat in the term's session. */
    private function roll(int $schoolId, Term $term): Collection
    {
        $placed = DB::table('student_enrollments')
            ->where('school_id', $schoolId)
            ->where('session_id', $term->session_id)
            ->where(fn ($q) => $q->whereNull('closed_on')->orWhere('closed_on', '>=', $term->start_date->toDateString()))
            ->get(['student_id', 'class_id', 'arm_id'])
            ->keyBy('student_id');

        return DB::table('students')
            ->leftJoin('users', 'users.id', '=', 'students.user_id')
            ->where('students.school_id', $schoolId)
            ->whereNull('students.deleted_at')
            ->where(fn ($q) => $q->where('students.status', 'active')->orWhereIn('students.id', $placed->keys()->all() ?: [0]))
            ->get(['students.id', 'students.admission_number', 'students.class_id', 'students.arm_id', 'users.name'])
            ->map(function ($s) use ($placed) {
                if ($p = $placed->get($s->id)) {
                    $s->class_id = $p->class_id;
                    $s->arm_id = $p->arm_id;
                }

                return $s;
            });
    }
}
