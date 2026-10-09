<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Academics\SubjectOffering;
use App\Services\Academics\TeachingAssignments;
use App\Support\Sections;
use App\Support\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Who teaches what, for one section: every class, its arms, the subjects
 * each arm takes, and who teaches each one — assigned, or the class teacher
 * where the section is taught by class teachers. Gaps are flagged. A load
 * per teacher says how many subjects and classes each one carries.
 */
class TeachingController extends Controller
{
    public function __construct(private TeachingAssignments $teaching, private SubjectOffering $offering)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate(['section' => ['required', Rule::in(array_keys(Sections::SECTIONS))]]);
        $section = $data['section'];

        $models = TeachingAssignments::models($schoolId);
        $rows = $this->teaching->rows($schoolId);
        $names = DB::table('users')->whereIn('id', $rows->pluck('teacher_id')->unique()->all() ?: [0])->pluck('name', 'id');

        $sessionId = TeacherScope::currentSessionId($schoolId);
        $formTeachers = $sessionId
            ? DB::table('class_teacher_assignments')->where('school_id', $schoolId)->where('session_id', $sessionId)->get(['id', 'class_id', 'arm_id', 'form_teacher_id', 'assistant_teacher_id'])
            : collect();
        $staffNames = DB::table('users')->whereIn('id', $formTeachers->pluck('form_teacher_id')->concat($formTeachers->pluck('assistant_teacher_id'))->filter()->all() ?: [0])->pluck('name', 'id');

        $classes = DB::table('classes')->where('school_id', $schoolId)->orderBy('order_index')->orderBy('name')->get(['id', 'name', 'level_category'])
            ->filter(fn ($c) => Sections::sectionOf(Sections::levelGroupOf($c->level_category)) === $section);
        $arms = DB::table('arms')->where('school_id', $schoolId)->orderBy('name')->get(['id', 'class_id', 'name', 'stream'])->groupBy('class_id');

        $out = $classes->map(function ($class) use ($schoolId, $arms, $rows, $names, $formTeachers, $staffNames) {
            $classArms = ($arms->get($class->id) ?? collect())->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name, 'stream' => $a->stream])->values();
            // An unstreamed class is taught as one group: a single column.
            $columns = $classArms->isEmpty() ? collect([['id' => null, 'name' => $class->name, 'stream' => null]]) : $classArms;

            $columns = $columns->map(function ($arm) use ($schoolId, $class, $formTeachers, $staffNames) {
                $placement = $formTeachers->first(fn ($p) => (int) $p->class_id === (int) $class->id && ($p->arm_id === null ? true : (int) $p->arm_id === $arm['id']))
                    ?? null;

                return $arm + [
                    'class_teacher' => $placement ? ['id' => (int) $placement->form_teacher_id, 'name' => $staffNames[$placement->form_teacher_id] ?? null, 'assignment_id' => (int) $placement->id, 'whole_class' => $placement->arm_id === null] : null,
                    'assistant' => $placement && $placement->assistant_teacher_id ? ['id' => (int) $placement->assistant_teacher_id, 'name' => $staffNames[$placement->assistant_teacher_id] ?? null] : null,
                    'subjects' => $this->offering->forArm($schoolId, (int) $class->id, $arm['id']),
                ];
            });

            // Every subject any column takes, or the class's level list when none is set.
            $subjectIds = $columns->flatMap(fn ($c) => array_keys($c['subjects']))->unique();
            if ($subjectIds->isEmpty()) {
                $subjectIds = $this->offering->subjectsFor($schoolId, (int) $class->id)->pluck('id');
            }
            $subjectNames = DB::table('subjects')->whereIn('id', $subjectIds->all() ?: [0])->orderBy('name')->pluck('name', 'id');

            $subjects = $subjectNames->map(function ($name, $subjectId) use ($columns, $rows, $names, $class) {
                $cells = $columns->map(function ($col) use ($subjectId, $rows, $names, $class) {
                    $offered = $col['subjects'] === [] || isset($col['subjects'][$subjectId]);
                    $teachers = $rows->filter(fn ($r) => $r->subject_id === (int) $subjectId
                        && ($r->class_id === null || $r->class_id === (int) $class->id)
                        && ($col['id'] === null || $r->arm_id === null || $r->arm_id === $col['id']))
                        ->unique(fn ($r) => $r->teacher_id.$r->source)
                        ->map(fn ($r) => ['id' => $r->teacher_id, 'name' => $names[$r->teacher_id] ?? null, 'source' => $r->source, 'whole_class' => $r->arm_id === null])
                        ->values();

                    return ['arm_id' => $col['id'], 'offered' => $offered, 'teachers' => $teachers, 'missing' => $offered && $teachers->isEmpty()];
                })->values();

                return ['id' => (int) $subjectId, 'name' => $name, 'cells' => $cells];
            })->values();

            return [
                'id' => (int) $class->id,
                'name' => $class->name,
                'arms' => $columns->map(fn ($c) => collect($c)->except('subjects')->all())->values(),
                'subjects' => $subjects,
                'gaps' => $subjects->sum(fn ($s) => $s['cells']->where('missing', true)->count()),
            ];
        })->values();

        // Load: what each teacher carries in this section.
        $classIds = $classes->pluck('id')->map(fn ($id) => (int) $id)->all();
        $loads = $rows->filter(fn ($r) => $r->class_id === null || in_array($r->class_id, $classIds, true))
            ->groupBy('teacher_id')
            ->map(fn ($g, $teacherId) => [
                'teacher_id' => (int) $teacherId,
                'name' => $names[$teacherId] ?? null,
                'subjects' => $g->pluck('subject_id')->unique()->count(),
                'classes' => $g->pluck('class_id')->filter()->unique()->count(),
                'teaching_slots' => $g->unique(fn ($r) => $r->subject_id.':'.$r->class_id.':'.$r->arm_id)->count(),
            ])
            ->sortByDesc('teaching_slots')->values();

        return response()->json([
            'section' => $section,
            'teaching_model' => $models[$section],
            'teaching_model_options' => TeachingAssignments::MODELS,
            'session_id' => $sessionId,
            'classes' => $out,
            'loads' => $loads,
            'gaps' => $out->sum('gaps'),
        ]);
    }
}
