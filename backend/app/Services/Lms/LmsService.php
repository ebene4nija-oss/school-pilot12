<?php

namespace App\Services\Lms;

use App\Exceptions\DomainRefusal;
use App\Models\AuditLog;
use App\Models\LearningMaterial;
use App\Models\LiveClass;
use App\Models\LiveClassParticipant;
use App\Models\LmsSetting;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Results\AuthorisationFailure;
use App\Services\Sections\HeadResolver;
use App\Support\Sections;
use App\Support\TeacherScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The rules the LMS shares (TASK 14): who may share with whom, who sees a
 * material, who reviews it, and how time in a virtual class becomes an
 * attendance status.
 */
class LmsService
{
    /** A heartbeat gap longer than this is a disconnection, not time in class. */
    public const HEARTBEAT_GAP_SECONDS = 150;

    public function __construct(private HeadResolver $heads)
    {
    }

    public function sectionOfClass(?int $classId): ?string
    {
        if (! $classId) {
            return null;
        }
        $level = SchoolClass::withoutGlobalScopes()->whereKey($classId)->value('level_category');

        return Sections::sectionOf(Sections::levelGroupOf($level));
    }

    public function isAdmin(User $user): bool
    {
        return in_array($user->userProfile?->role, ['school_admin', 'super_admin'], true);
    }

    /** Sections a user heads; null = all (admin or principal). */
    public function sectionsHeaded(User $user, int $schoolId): ?array
    {
        $levels = $this->heads->levelsHeadedBy($user, $schoolId);

        return $levels === null ? null : array_values(array_unique(array_map(fn ($l) => Sections::sectionOf($l), $levels)));
    }

    /** Whether a teacher teaches a class (whole, or any arm of it). */
    public function teachesClass(User $user, int $classId, ?int $armId = null): bool
    {
        if ($this->isAdmin($user) || TeacherScope::seesWholeSchool($user)) {
            return true;
        }
        $p = TeacherScope::placements($user);
        if (in_array($classId, $p['classes'], true)) {
            return true;
        }
        foreach ($p['arms'] as [$c, $a]) {
            if ($c === $classId && ($armId === null || $armId === $a)) {
                return true;
            }
        }
        // A section head may share with any class in their section.
        $headed = $this->sectionsHeaded($user, (int) $user->userProfile?->school_id);

        return $headed !== null && in_array($this->sectionOfClass($classId), $headed, true);
    }

    /**
     * Check every share target against the sharer's scope (§4.3, §14): a
     * Primary 4 teacher does not reach Secondary.
     *
     * @param  array<int, array<string, mixed>>  $targets
     */
    public function assertTargets(User $user, int $schoolId, array $targets): void
    {
        foreach ($targets as $t) {
            $ok = match ($t['target_type']) {
                'class' => $this->teachesClass($user, (int) $t['class_id']),
                'arm' => $this->teachesClass($user, (int) $t['class_id'], (int) $t['arm_id']),
                'section' => ($h = $this->sectionsHeaded($user, $schoolId)) === null || in_array($t['section'], $h, true),
                'student' => ($s = Student::find($t['student_id'])) && ($this->isAdmin($user) || TeacherScope::seesWholeSchool($user) || TeacherScope::teaches($user, $s)),
                'live_class' => ($lc = LiveClass::find($t['live_class_id'])) && ($this->isAdmin($user) || (int) $lc->teacher_id === $user->id),
                default => false,
            };
            if (! $ok) {
                throw new AuthorisationFailure('You can only share with classes, students and sections you are responsible for.');
            }
        }
    }

    /** Published materials one student may open. */
    public function visibleTo(Student $student): Builder
    {
        $section = $this->sectionOfClass($student->class_id);
        $liveClassIds = LiveClass::where('class_id', $student->class_id)
            ->where(fn ($q) => $q->whereNull('arm_id')->orWhere('arm_id', $student->arm_id))->pluck('id');

        return LearningMaterial::where('school_id', $student->school_id)->where('status', 'published')
            ->whereExists(function ($q) use ($student, $section, $liveClassIds) {
                $q->from('learning_material_access as t')->whereColumn('t.material_id', 'learning_materials.id')
                    ->where(function ($w) use ($student, $section, $liveClassIds) {
                        $w->where(fn ($x) => $x->where('t.target_type', 'class')->where('t.class_id', $student->class_id))
                            ->orWhere(fn ($x) => $x->where('t.target_type', 'arm')->where('t.class_id', $student->class_id)->where('t.arm_id', $student->arm_id))
                            ->orWhere(fn ($x) => $x->where('t.target_type', 'section')->where('t.section', $section))
                            ->orWhere(fn ($x) => $x->where('t.target_type', 'student')->where('t.student_id', $student->id))
                            ->orWhere(fn ($x) => $x->where('t.target_type', 'live_class')->whereIn('t.live_class_id', $liveClassIds));
                    });
            });
    }

    public function canSee(User $user, LearningMaterial $m): bool
    {
        $role = $user->userProfile?->role;
        if (in_array($role, ['school_admin', 'super_admin'], true) || (int) $m->uploaded_by === $user->id) {
            return true;
        }
        if ($role === 'teacher') {
            // Staff see the school's published library and anything they review.
            return $m->status === 'published' || $this->canReview($user, $m);
        }
        if ($role === 'student') {
            $s = Student::where('user_id', $user->id)->first();

            return $s && $this->visibleTo($s)->whereKey($m->id)->exists();
        }
        if ($role === 'parent' && LmsSetting::for((int) $m->school_id)->parents_see_materials) {
            return Student::whereHas('guardians', fn ($q) => $q->where('guardians.user_id', $user->id))->get()
                ->contains(fn ($s) => $this->visibleTo($s)->whereKey($m->id)->exists());
        }

        return false;
    }

    /** Reviewers: the subject's HOD, the section head, or an admin when both are vacant. */
    public function canReview(User $user, LearningMaterial $m): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $schoolId = (int) $m->school_id;
        if ($m->class_id) {
            $level = Sections::levelGroupOf(SchoolClass::withoutGlobalScopes()->whereKey($m->class_id)->value('level_category'));
            foreach (['hod', 'level_head'] as $role) {
                if (in_array($user->id, $this->heads->holders($schoolId, $role, (int) $m->class_id, $level, $m->subject_id), true)) {
                    return true;
                }
            }
        }
        $headed = $this->sectionsHeaded($user, $schoolId);

        return $headed !== null && $headed !== [] && in_array($m->section, $headed, true);
    }

    // ==================================================================
    // Virtual-class attendance (§3.3)
    // ==================================================================

    public function join(LiveClass $lc, Student $student): LiveClassParticipant
    {
        $p = LiveClassParticipant::firstOrNew(['live_class_id' => $lc->id, 'student_id' => $student->id], ['school_id' => $lc->school_id]);
        $this->accrue($p, $lc);
        $p->fill(['first_joined_at' => $p->first_joined_at ?? now(), 'last_seen_at' => now(), 'connected' => true, 'joins' => $p->joins + 1])->save();

        return $p;
    }

    public function heartbeat(LiveClass $lc, Student $student, bool $leaving = false): ?LiveClassParticipant
    {
        $p = LiveClassParticipant::where('live_class_id', $lc->id)->where('student_id', $student->id)->first();
        if (! $p) {
            return null;
        }
        $this->accrue($p, $lc);
        $p->last_seen_at = now();
        if ($leaving) {
            $p->connected = false;
            $p->last_left_at = now();
        }
        $p->save();

        return $p;
    }

    /** Add the time since the last ping, inside the lesson window and only across a short gap. */
    private function accrue(LiveClassParticipant $p, LiveClass $lc): void
    {
        if (! $p->connected || ! $p->last_seen_at) {
            return;
        }
        $from = $p->last_seen_at->max($lc->scheduled_for);
        $to = now()->min($lc->ends_at);
        $gap = $p->last_seen_at->diffInSeconds(now());
        if ($gap > self::HEARTBEAT_GAP_SECONDS) {
            // The connection dropped: the gap is not counted, and the ping
            // that arrives after it is a rejoin, starting a new stretch.
            $p->joins++;

            return;
        }
        if ($to->gt($from)) {
            $p->seconds_connected += (int) $from->diffInSeconds($to);
        }
    }

    /** present | late | partial | absent, from the school's thresholds; a teacher's correction wins. */
    public function status(?LiveClassParticipant $p, LiveClass $lc, LmsSetting $settings): string
    {
        if ($p?->corrected_status) {
            return $p->corrected_status;
        }
        if (! $p || ! $p->first_joined_at) {
            return 'absent';
        }
        if ($p->first_joined_at->gt($lc->scheduled_for->copy()->addMinutes($settings->late_grace_minutes))) {
            return 'late';
        }

        return $p->seconds_connected >= $lc->duration_minutes * 60 * $settings->present_percent / 100 ? 'present' : 'partial';
    }

    /** Students a class session or assignment is for. */
    public function studentsOf(int $schoolId, ?int $classId, ?int $armId = null, array $studentIds = []): \Illuminate\Support\Collection
    {
        if ($studentIds !== []) {
            return Student::where('school_id', $schoolId)->whereIn('id', $studentIds)->get();
        }
        if (! $classId) {
            return collect();
        }

        return Student::where('school_id', $schoolId)->where('class_id', $classId)->where('status', 'active')
            ->when($armId, fn ($q) => $q->where('arm_id', $armId))->get();
    }

    /** @return array<int, int> */
    public function guardianUserIds(array $studentIds): array
    {
        return DB::table('student_guardian')->join('guardians', 'guardians.id', '=', 'student_guardian.guardian_id')
            ->whereIn('student_guardian.student_id', $studentIds ?: [0])->pluck('guardians.user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Reminders (§11): classes starting within the next half hour, and
     * homework due tomorrow that has not been handed in. Each goes once,
     * whatever the run frequency.
     *
     * @return array{classes: int, deadlines: int}
     */
    public function sendReminders(): array
    {
        $sent = ['classes' => 0, 'deadlines' => 0];

        $soon = LiveClass::withoutGlobalScopes()->where('status', 'scheduled')->whereBetween('scheduled_for', [now(), now()->addMinutes(30)])->get();
        foreach ($soon as $lc) {
            if (! \Illuminate\Support\Facades\Cache::add("lms:class-reminder:{$lc->id}", 1, now()->addDay())) {
                continue;
            }
            $students = Student::withoutGlobalScopes()->where('school_id', $lc->school_id)->where('class_id', $lc->class_id)->where('status', 'active')
                ->when($lc->arm_id, fn ($q) => $q->where('arm_id', $lc->arm_id))->get();
            $text = "{$lc->title} starts at ".$lc->scheduled_for->format('g:ia').'.';
            \App\Support\InAppNotifier::send($lc->school_id, $students->pluck('user_id')->map(fn ($v) => (int) $v)->all(), 'lms', 'Virtual class soon', $text, true);
            \App\Support\InAppNotifier::send($lc->school_id, $this->guardianUserIds($students->pluck('id')->all()), 'lms', 'Virtual class soon', $text);
            $sent['classes']++;
        }

        // Once a day is enough for deadlines; the cache key carries the date.
        $due = \App\Models\Homework::withoutGlobalScopes()->whereDate('due_date', now()->addDay()->toDateString())
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'published'))->get();
        foreach ($due as $h) {
            if (! \Illuminate\Support\Facades\Cache::add("lms:deadline-reminder:{$h->id}:".now()->toDateString(), 1, now()->addDays(2))) {
                continue;
            }
            $handedIn = DB::table('homework_submissions')->where('homework_id', $h->id)->pluck('student_id')->all();
            $students = $this->studentsOf((int) $h->school_id, (int) $h->class_id, $h->arm_id, $h->targetStudentIds())->whereNotIn('id', $handedIn);
            $text = "{$h->title} is due tomorrow.";
            \App\Support\InAppNotifier::send((int) $h->school_id, $students->pluck('user_id')->map(fn ($v) => (int) $v)->all(), 'lms', 'Homework due tomorrow', $text);
            \App\Support\InAppNotifier::send((int) $h->school_id, $this->guardianUserIds($students->pluck('id')->all()), 'lms', 'Homework due tomorrow', $text);
            $sent['deadlines'] += $students->count();
        }

        return $sent;
    }

    public function audit(User $by,string $action, string $type, int $id, array $values): void
    {
        AuditLog::create(['school_id' => $by->userProfile?->school_id, 'user_id' => $by->id, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => $id, 'new_values' => $values]);
    }

    public function refuse(string $message): never
    {
        throw new DomainRefusal($message);
    }
}
