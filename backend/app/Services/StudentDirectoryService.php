<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\StudentDirectorySetting;
use App\Models\User;
use App\Support\TeacherScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The student list (TASK: View Students page instructions).
 *
 * One query builder behind the list, the summary cards, the chips and the
 * export, so a card's count is always exactly the list it filters to (§4.2,
 * acceptance 15). Status comes from the enrolment record for the chosen
 * session, not from the profile (§3 rule 1).
 *
 * What a role may see is decided here, on the server, for every path (§2):
 * teachers only the students they teach; fee status only for admin and
 * bursar; a suspension only for those who manage discipline.
 */
class StudentDirectoryService
{
    public const TABS = ['active', 'pending', 'transferred', 'graduated', 'withdrawn', 'suspended', 'all'];

    public const SORTS = ['name', 'admission_number', 'class', 'admission_date'];

    /** Who sees what. Server-side; the page only mirrors it. */
    public function permissions(User $user): array
    {
        $role = $user->userProfile?->role;
        $admin = in_array($role, ['school_admin', 'super_admin'], true);
        $settings = StudentDirectorySetting::for((int) $user->userProfile?->school_id);
        $teacherWhole = $role === 'teacher' && TeacherScope::seesWholeSchool($user);
        $isClassTeacher = $role === 'teacher' && $this->formClassIds($user) !== [];

        return [
            'role' => $role,
            'scope' => $admin || $role === 'bursar' || $teacherWhole ? 'school' : 'own_classes',
            'see_guardian_name' => $admin || $role === 'teacher',
            'see_guardian_phone' => $admin || $isClassTeacher || ($role === 'teacher' && $settings->subject_teachers_see_guardian_phone),
            'see_fee_status' => $admin || $role === 'bursar',
            'see_date_of_birth' => $admin,
            'see_completeness' => $admin,
            'see_suspended' => $admin,
            'see_results' => $admin || $role === 'teacher',
            'see_attendance' => $admin || $role === 'teacher',
            'can_bulk' => $admin,
            'can_edit' => $admin,
            'can_export' => $admin || $isClassTeacher,
            // A class teacher exports a class list, never contact or finance data.
            'export_fields' => $admin
                ? ['admission_number', 'name', 'gender', 'class', 'arm', 'status', 'admission_date', 'date_of_birth', 'guardian_name', 'guardian_phone']
                : ($isClassTeacher ? ['admission_number', 'name', 'gender', 'class', 'arm'] : []),
        ];
    }

    public function sessionId(int $schoolId, ?int $requested): ?int
    {
        if ($requested && AcademicSession::withoutGlobalScopes()->where('school_id', $schoolId)->whereKey($requested)->exists()) {
            return $requested;
        }

        return TeacherScope::currentSessionId($schoolId);
    }

    /**
     * Students with their enrolment-derived status for the session.
     *
     * `directory_status` is one of the tabs. A student placed in a class but
     * with no enrolment row for the current session (a school part-way
     * through adopting enrolments) is read from their profile instead.
     */
    public function base(int $schoolId, ?int $sessionId, User $user): Builder
    {
        $perm = $this->permissions($user);
        $current = $sessionId !== null && $sessionId === TeacherScope::currentSessionId($schoolId);

        $statusSql = "CASE
            WHEN e.id IS NULL THEN (CASE WHEN s.status = 'suspended' THEN 'suspended' WHEN s.status IN ('graduated','transferred','withdrawn') THEN s.status ELSE 'active' END)
            WHEN e.outcome = 'transferred_out' THEN 'transferred'
            WHEN e.outcome = 'graduated' THEN 'graduated'
            WHEN e.outcome = 'withdrawn' THEN 'withdrawn'
            WHEN s.status = 'suspended' AND e.status = 'active' THEN 'suspended'
            ELSE 'active' END";

        $query = DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('student_enrollments as e', function ($j) use ($sessionId) {
                $j->on('e.student_id', '=', 's.id')->where('e.session_id', '=', $sessionId ?? 0);
            })
            ->leftJoin('classes as c', 'c.id', '=', DB::raw('COALESCE(e.class_id, s.class_id)'))
            ->leftJoin('arms as a', 'a.id', '=', DB::raw('COALESCE(e.arm_id, s.arm_id)'))
            ->where('s.school_id', $schoolId)
            ->whereNull('s.deleted_at')
            ->where(function ($q) use ($current) {
                // In the session: enrolled in it, or, for the current session
                // only, placed by profile without an enrolment row yet.
                $q->whereNotNull('e.id');

                if ($current) {
                    $q->orWhere(fn ($w) => $w->whereNull('e.id')->whereNotExists(fn ($x) => $x->from('student_enrollments as e2')->whereColumn('e2.student_id', 's.id')));
                }
            })
            ->select([
                's.id', 's.admission_number', 's.gender', 's.date_of_birth', 's.passport_photo_path', 's.status as profile_status',
                's.previous_school', 's.state_of_origin', 's.lga',
                'u.name',
                DB::raw('COALESCE(e.class_id, s.class_id) as class_id'),
                DB::raw('COALESCE(e.arm_id, s.arm_id) as arm_id'),
                'c.name as class_name', 'c.order_index as class_order', 'a.name as arm_name',
                'e.enrolled_on', 'e.id as enrollment_id',
                DB::raw("({$statusSql}) as directory_status"),
            ]);

        if ($perm['scope'] === 'own_classes') {
            $placements = TeacherScope::placements($user);

            $query->where(function ($q) use ($placements) {
                $q->whereRaw('1 = 0');

                if ($placements['classes'] !== []) {
                    $q->orWhereIn(DB::raw('COALESCE(e.class_id, s.class_id)'), $placements['classes']);
                }

                foreach ($placements['arms'] as [$classId, $armId]) {
                    $q->orWhere(fn ($w) => $w->where(DB::raw('COALESCE(e.class_id, s.class_id)'), $classId)->where(DB::raw('COALESCE(e.arm_id, s.arm_id)'), $armId));
                }
            });
        }

        return $query;
    }

    /**
     * Apply filters and search (§5). Everything here runs in SQL.
     *
     * @param  array<string, mixed>  $f
     */
    public function filter(Builder $query, array $f, User $user, int $schoolId, ?int $sessionId): Builder
    {
        $perm = $this->permissions($user);
        $tab = $f['tab'] ?? 'active';

        // A suspension discloses a discipline matter: only managers see it as
        // such. For everyone else a suspended student is listed as active.
        $statusExpr = $perm['see_suspended']
            ? 'directory_status'
            : "CASE WHEN directory_status = 'suspended' THEN 'active' ELSE directory_status END";

        $wrapped = DB::query()->fromSub($query, 'd')->select('d.*')->selectRaw("{$statusExpr} as shown_status");

        if ($tab !== 'all' && $tab !== 'pending') {
            $wrapped->whereRaw("{$statusExpr} = ?", [$tab]);
        }

        if (! empty($f['class_id'])) {
            $wrapped->where('d.class_id', (int) $f['class_id']);
        }

        if (! empty($f['arm_id'])) {
            $wrapped->where('d.arm_id', (int) $f['arm_id']);
        }

        if (! empty($f['gender'])) {
            $wrapped->where('d.gender', $f['gender']);
        }

        if (! empty($f['admitted_from'])) {
            $wrapped->where('d.enrolled_on', '>=', $f['admitted_from']);
        }

        if (! empty($f['admitted_to'])) {
            $wrapped->where('d.enrolled_on', '<=', $f['admitted_to'].' 23:59:59');
        }

        if (! empty($f['admission_type'])) {
            $this->admissionType($wrapped, $f['admission_type'], $sessionId);
        }

        if (! empty($f['no_class'])) {
            $wrapped->whereNull('d.class_id');
        }

        if (! empty($f['fee_status']) && $perm['see_fee_status']) {
            $this->feeStatus($wrapped, $f['fee_status'], $schoolId, $sessionId);
        }

        if (! empty($f['record_status']) && $perm['see_completeness']) {
            $this->recordStatus($wrapped, $f['record_status'], $schoolId);
        }

        if (! empty($f['search'])) {
            $this->search($wrapped, (string) $f['search'], $perm['see_guardian_phone']);
        }

        return $wrapped;
    }

    /** Names in any order, admission number, and guardian name or phone where allowed (§5.1). */
    private function search(Builder $q, string $term, bool $guardianPhone): void
    {
        $tokens = array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim($term))) ?: [], fn ($t) => $t !== ''));

        if ($tokens === []) {
            return;
        }

        $q->where(function ($w) use ($tokens, $term, $guardianPhone) {
            $w->where(function ($names) use ($tokens) {
                foreach ($tokens as $t) {
                    $names->whereRaw('LOWER(d.name) LIKE ?', ['%'.$t.'%']);
                }
            })->orWhereRaw('LOWER(d.admission_number) LIKE ?', ['%'.mb_strtolower(trim($term)).'%']);

            $w->orWhereExists(function ($g) use ($tokens, $term, $guardianPhone) {
                $g->from('student_guardian as sg')
                    ->join('guardians as gd', 'gd.id', '=', 'sg.guardian_id')
                    ->join('users as gu', 'gu.id', '=', 'gd.user_id')
                    ->leftJoin('user_profiles as gp', 'gp.user_id', '=', 'gu.id')
                    ->whereColumn('sg.student_id', 'd.id')
                    ->where(function ($m) use ($tokens, $term, $guardianPhone) {
                        $m->where(function ($n) use ($tokens) {
                            foreach ($tokens as $t) {
                                $n->whereRaw('LOWER(gu.name) LIKE ?', ['%'.$t.'%']);
                            }
                        });

                        $digits = preg_replace('/\D+/', '', $term);
                        if ($guardianPhone && strlen((string) $digits) >= 6) {
                            $m->orWhere('gp.phone', 'like', '%'.substr((string) $digits, -9).'%');
                        }
                    });
            });
        });
    }

    private function admissionType(Builder $q, string $type, ?int $sessionId): void
    {
        $transferIn = fn ($x) => $x->from('transfers as t')->whereColumn('t.new_student_id', 'd.id')->where('t.direction', 'incoming');
        $firstSession = fn ($x) => $x->from('student_enrollments as e3')->whereColumn('e3.student_id', 'd.id')->where('e3.session_id', '!=', $sessionId ?? 0);

        match ($type) {
            'transfer_in' => $q->whereExists($transferIn),
            'new' => $q->whereNotExists($transferIn)->whereNotExists($firstSession),
            'returning' => $q->whereExists($firstSession),
            default => null,
        };
    }

    private function feeStatus(Builder $q, string $status, int $schoolId, ?int $sessionId): void
    {
        $termIds = DB::table('terms')->where('school_id', $schoolId)->where('session_id', $sessionId ?? 0)->pluck('id')->all() ?: [0];

        $owed = fn ($x) => $x->from('invoices as i')->whereColumn('i.student_id', 'd.id')->whereIn('i.term_id', $termIds);

        match ($status) {
            'paid' => $q->whereExists($owed)->whereNotExists(fn ($x) => $owed($x)->whereRaw('i.amount_paid < i.total_amount')),
            'partial' => $q->whereExists(fn ($x) => $owed($x)->whereRaw('i.amount_paid > 0 AND i.amount_paid < i.total_amount')),
            'outstanding' => $q->whereExists(fn ($x) => $owed($x)->whereRaw('i.amount_paid < i.total_amount')),
            default => null,
        };
    }

    /** Complete, incomplete (a mandatory field is empty) or requires review (possible duplicate). */
    private function recordStatus(Builder $q, string $status, int $schoolId): void
    {
        $mandatory = StudentDirectorySetting::for($schoolId)->mandatory();

        $incomplete = function ($w) use ($mandatory) {
            foreach ($mandatory as $field) {
                match ($field) {
                    'guardian' => $w->orWhereNotExists(fn ($x) => $x->from('student_guardian as sg2')->whereColumn('sg2.student_id', 'd.id')),
                    'guardian_phone' => $w->orWhereNotExists(fn ($x) => $x->from('student_guardian as sg3')
                        ->join('guardians as g3', 'g3.id', '=', 'sg3.guardian_id')
                        ->join('user_profiles as p3', 'p3.user_id', '=', 'g3.user_id')
                        ->whereColumn('sg3.student_id', 'd.id')->whereNotNull('p3.phone')->where('p3.phone', '!=', '')),
                    'class_id', 'arm_id', 'gender', 'date_of_birth', 'passport_photo_path', 'state_of_origin', 'lga' => $w->orWhereNull("d.{$field}"),
                    default => null,
                };
            }
        };

        $duplicate = fn ($x) => $x->from('students as s4')->join('users as u4', 'u4.id', '=', 's4.user_id')
            ->where('s4.school_id', $schoolId)->whereNull('s4.deleted_at')
            ->whereColumn('s4.id', '!=', 'd.id')
            ->whereRaw('LOWER(u4.name) = LOWER(d.name)')
            ->where(fn ($m) => $m->whereColumn('s4.date_of_birth', 'd.date_of_birth')->orWhereNull('d.date_of_birth'));

        match ($status) {
            'incomplete' => $q->where($incomplete),
            'requires_review' => $q->whereExists($duplicate),
            'complete' => $q->whereNot($incomplete)->whereNotExists($duplicate),
            default => null,
        };
    }

    public function sort(Builder $q, ?string $sort, ?string $dir): Builder
    {
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        return match ($sort) {
            'admission_number' => $q->orderBy('d.admission_number', $dir)->orderBy('d.id'),
            'class' => $q->orderBy('d.class_order', $dir)->orderBy('d.class_name', $dir)->orderBy('d.arm_name', $dir)->orderBy('d.name')->orderBy('d.id'),
            'admission_date' => $q->orderBy('d.enrolled_on', $dir)->orderBy('d.name')->orderBy('d.id'),
            default => $q->orderByRaw("LOWER(d.name) {$dir}")->orderBy('d.id'),
        };
    }

    /** "Okafor John" for schools that write the surname first (§6.2). */
    public static function displayName(?string $name, string $order): ?string
    {
        if ($name === null || $order !== 'surname_first') {
            return $name;
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return count($parts) > 1 ? array_pop($parts).' '.implode(' ', $parts) : $name;
    }

    /**
     * The extra columns for one page of rows, each only for roles allowed it.
     *
     * @param  array<int, object>  $rows
     */
    public function decorate(array $rows, User $user, int $schoolId, ?int $sessionId): array
    {
        $perm = $this->permissions($user);
        $ids = array_map(fn ($r) => (int) $r->id, $rows);

        if ($ids === []) {
            return [];
        }

        $guardians = DB::table('student_guardian as sg')
            ->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
            ->join('users as gu', 'gu.id', '=', 'g.user_id')
            ->leftJoin('user_profiles as gp', 'gp.user_id', '=', 'gu.id')
            ->whereIn('sg.student_id', $ids)
            ->orderByDesc('sg.is_primary')
            ->get(['sg.student_id', 'g.id as guardian_id', 'gu.name', 'gp.phone'])
            ->groupBy('student_id');

        $siblings = DB::table('student_guardian')
            ->whereIn('guardian_id', $guardians->flatten()->pluck('guardian_id')->unique()->all() ?: [0])
            ->get(['guardian_id', 'student_id'])
            ->groupBy('guardian_id');

        $termIds = DB::table('terms')->where('school_id', $schoolId)->where('session_id', $sessionId ?? 0)->pluck('id')->all() ?: [0];

        $attendance = $perm['see_attendance'] ? DB::table('attendance_records')
            ->whereIn('student_id', $ids)->whereIn('term_id', $termIds)
            ->selectRaw("student_id, SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as attended, COUNT(*) as days")
            ->groupBy('student_id')->get()->keyBy('student_id') : collect();

        $latestTerm = DB::table('terms')->where('school_id', $schoolId)->where('session_id', $sessionId ?? 0)->orderByDesc('start_date')->value('id');
        $averages = $perm['see_results'] && $latestTerm ? DB::table('score_entries')
            ->whereIn('student_id', $ids)->where('term_id', $latestTerm)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('result_state')->orWhere('result_state', 'scored'))
            ->selectRaw('student_id, AVG(total_score) as average')->groupBy('student_id')->get()->keyBy('student_id') : collect();

        $fees = $perm['see_fee_status'] ? DB::table('invoices')->whereIn('student_id', $ids)->whereIn('term_id', $termIds)
            ->selectRaw('student_id, SUM(total_amount) as billed, SUM(amount_paid) as paid')->groupBy('student_id')->get()->keyBy('student_id') : collect();

        $transfers = DB::table('transfers')->where('school_id', $schoolId)->whereIn('student_id', $ids)->where('direction', 'outgoing')
            ->whereNotIn('status', ['cancelled', 'rejected'])->orderByDesc('id')->get(['student_id', 'reference', 'status'])->groupBy('student_id');

        $settings = StudentDirectorySetting::for($schoolId);
        $mandatory = $settings->mandatory();
        $order = $settings->name_order;

        // A teacher sees only the subjects they teach: a latest average across
        // every subject is shown to them for their own classes only, which the
        // scope already guarantees.
        return array_map(function ($r) use ($perm, $guardians, $siblings, $attendance, $averages, $fees, $transfers, $mandatory, $order) {
            $g = $guardians->get($r->id, collect());
            $primary = $g->first();
            $att = $attendance->get($r->id);
            $fee = $fees->get($r->id);
            $missing = $this->missingFields($r, $g, $mandatory);

            $siblingCount = $primary ? max(0, ($siblings->get($primary->guardian_id)?->count() ?? 1) - 1) : 0;

            return [
                'id' => (int) $r->id,
                'name' => $r->name,
                'display_name' => self::displayName($r->name, $order),
                'admission_number' => $r->admission_number,
                'photo' => $r->passport_photo_path,
                'gender' => $r->gender,
                'class_id' => $r->class_id ? (int) $r->class_id : null,
                'class' => $r->class_name,
                'arm' => $r->arm_name,
                'status' => $r->shown_status,
                'admission_date' => $r->enrolled_on ? substr((string) $r->enrolled_on, 0, 10) : null,
                'guardian_name' => $perm['see_guardian_name'] ? $primary?->name : null,
                'guardian_phone' => $perm['see_guardian_phone'] ? $primary?->phone : null,
                'siblings' => $siblingCount,
                'attendance_rate' => $att && $att->days > 0 ? round($att->attended / $att->days * 100, 1) : null,
                'latest_average' => isset($averages[$r->id]) ? round((float) $averages[$r->id]->average, 1) : null,
                'fee_status' => $perm['see_fee_status'] ? $this->feeLabel($fee) : null,
                'date_of_birth' => $perm['see_date_of_birth'] && $r->date_of_birth ? substr((string) $r->date_of_birth, 0, 10) : null,
                'completeness' => $perm['see_completeness'] ? ($missing === [] ? 'complete' : 'incomplete') : null,
                'missing_fields' => $perm['see_completeness'] ? $missing : [],
                'transfer' => ($t = $transfers->get($r->id)?->first()) ? ['reference' => $t->reference, 'status' => $t->status] : null,
            ];
        }, $rows);
    }

    private function missingFields(object $r, $guardians, array $mandatory): array
    {
        $labels = StudentDirectorySetting::FIELDS;
        $missing = [];

        foreach ($mandatory as $field) {
            $empty = match ($field) {
                'guardian' => $guardians->isEmpty(),
                'guardian_phone' => $guardians->filter(fn ($g) => filled($g->phone))->isEmpty(),
                default => blank($r->{$field} ?? null),
            };

            if ($empty) {
                $missing[] = $labels[$field] ?? $field;
            }
        }

        return $missing;
    }

    private function feeLabel(?object $fee): string
    {
        if (! $fee || (float) $fee->billed <= 0) {
            return 'not_billed';
        }

        return match (true) {
            (float) $fee->paid >= (float) $fee->billed => 'paid',
            (float) $fee->paid > 0 => 'partial',
            default => 'outstanding',
        };
    }

    /** Class ids where this teacher is the form or assistant teacher this session. */
    public function formClassIds(User $user): array
    {
        $schoolId = (int) $user->userProfile?->school_id;
        $sessionId = TeacherScope::currentSessionId($schoolId);

        return $sessionId ? DB::table('class_teacher_assignments')
            ->where('school_id', $schoolId)->where('session_id', $sessionId)
            ->where(fn ($q) => $q->where('form_teacher_id', $user->id)->orWhere('assistant_teacher_id', $user->id))
            ->pluck('class_id')->map(fn ($id) => (int) $id)->unique()->values()->all() : [];
    }

    /** Accepted applicants not yet enrolled (§13, baseline D4). */
    public function pendingCount(int $schoolId): int
    {
        return Schema::hasTable('admission_applications')
            ? DB::table('admission_applications')->where('school_id', $schoolId)->where('status', 'accepted')->whereNull('student_id')->count()
            : 0;
    }
}
