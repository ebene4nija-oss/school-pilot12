<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Arm;
use App\Models\SchoolClass;
use App\Services\Academics\SubjectOffering;
use App\Support\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Setting up a school's classes and their arms.
 *
 * Until this, classes and arms could only come from the seeder, and an arm was
 * only a name. Now an arm can carry a stream (SS 2A science, SS 2B arts) that
 * decides which of the class's subjects it takes, and a merit flag for the arm
 * a school keeps its strongest students in. The merit flag is shown to staff
 * only; parents and students see the arm's name and nothing else.
 *
 * Nothing that has history is deleted: a class or arm with students, past
 * enrolments or scores refuses, because report cards and transcripts name it.
 */
class ClassStructureController extends Controller
{
    public function storeClass(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate($this->classRules($schoolId));

        $class = SchoolClass::create($data + ['school_id' => $schoolId, 'order_index' => $data['order_index'] ?? 0]);

        return response()->json(['message' => "{$class->name} added.", 'data' => $class], 201);
    }

    public function updateClass(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $class = SchoolClass::where('school_id', $schoolId)->findOrFail($id);

        $class->update($request->validate($this->classRules($schoolId, $class->id, true)));

        return response()->json(['message' => 'Class updated.', 'data' => $class->fresh()]);
    }

    public function destroyClass(int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $class = SchoolClass::where('school_id', $schoolId)->findOrFail($id);

        if ($reason = $this->inUse('class_id', $class->id)) {
            return response()->json(['message' => "{$class->name} {$reason}, so it cannot be deleted."], 409);
        }

        DB::transaction(function () use ($class) {
            Arm::where('class_id', $class->id)->delete();
            $class->delete();
        });

        return response()->json(['message' => "{$class->name} deleted."]);
    }

    public function storeArm(Request $request, int $classId): JsonResponse
    {
        $schoolId = $this->schoolId();
        $class = SchoolClass::where('school_id', $schoolId)->findOrFail($classId);
        $data = $request->validate($this->armRules($class->id));

        $arm = Arm::create($data + ['school_id' => $schoolId, 'class_id' => $class->id]);

        return response()->json(['message' => "{$class->name} {$arm->name} added.", 'data' => $arm], 201);
    }

    public function updateArm(Request $request, int $id): JsonResponse
    {
        $arm = Arm::where('school_id', $this->schoolId())->findOrFail($id);

        $arm->update($request->validate($this->armRules($arm->class_id, $arm->id, true)));

        return response()->json(['message' => 'Arm updated.', 'data' => $arm->fresh()]);
    }

    public function destroyArm(int $id): JsonResponse
    {
        $arm = Arm::where('school_id', $this->schoolId())->findOrFail($id);

        if ($reason = $this->inUse('arm_id', $arm->id)) {
            return response()->json(['message' => "{$arm->name} {$reason}, so it cannot be deleted."], 409);
        }

        $arm->delete();

        return response()->json(['message' => "{$arm->name} deleted."]);
    }

    private function classRules(int $schoolId, ?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:50', Rule::unique('classes', 'name')->where('school_id', $schoolId)->ignore($ignoreId)],
            'level_category' => [$required, Rule::in(array_keys(Sections::LEVEL_GROUPS))],
            'order_index' => 'sometimes|integer|min:0|max:255',
            'is_exit_class' => 'sometimes|boolean',
        ];
    }

    private function armRules(int $classId, ?int $ignoreId = null, bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:30', Rule::unique('arms', 'name')->where('class_id', $classId)->ignore($ignoreId)],
            'stream' => ['sometimes', 'nullable', Rule::in(array_keys(SubjectOffering::STREAMS))],
            'is_merit' => 'sometimes|boolean',
        ];
    }

    /** Why a class or arm still has history, or null when it has none. */
    private function inUse(string $column, int $id): ?string
    {
        if (DB::table('students')->where($column, $id)->whereNull('deleted_at')->exists()) {
            return 'has students';
        }

        if (DB::table('student_enrollments')->where($column, $id)->exists()) {
            return 'has enrolment history';
        }

        return null;
    }

    /**
     * Set up many classes and arms at once, from rows a client read out of a
     * CSV or a pasted list: {class, level?, order?, arms?}.
     *
     * Preview first (commit=false says exactly what would be created), then
     * commit. Safe to repeat: a class or arm that already exists (any case) is
     * reused, never duplicated. Level may be left out; it is worked out from
     * the class name (JSS → junior secondary, SS → senior secondary, Primary
     * or Basic → primary, Nursery / KG / Creche → nursery).
     */
    public function importStructure(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'rows' => 'required|array|min:1|max:200',
            'rows.*.class' => 'required|string|max:50',
            'rows.*.level' => 'nullable|string|max:40',
            'rows.*.order' => 'nullable|integer|min:0|max:255',
            'rows.*.arms' => 'nullable',
            'commit' => 'sometimes|boolean',
        ]);

        $existing = SchoolClass::where('school_id', $schoolId)->with('arms:id,class_id,name')->get()
            ->keyBy(fn ($c) => mb_strtolower(trim($c->name)));

        $plan = [];
        $errors = [];
        $seen = [];
        foreach ($data['rows'] as $i => $row) {
            $name = preg_replace('/\s+/', ' ', trim($row['class']));
            $key = mb_strtolower($name);
            $level = $this->levelFor($row['level'] ?? null, $name);
            if ($level === null) {
                $errors[] = ['row' => $i + 1, 'message' => "Say which level \"{$name}\" is (nursery, primary, junior_secondary, senior_secondary or secondary)."];
                continue;
            }

            $arms = is_array($row['arms'] ?? null) ? $row['arms'] : preg_split('/[;|\/]+/', (string) ($row['arms'] ?? ''));
            $arms = collect($arms)->map(fn ($a) => trim((string) $a))->filter()->unique(fn ($a) => mb_strtolower($a))->values();
            if ($long = $arms->first(fn ($a) => mb_strlen($a) > 30)) {
                $errors[] = ['row' => $i + 1, 'message' => "Arm \"{$long}\" is longer than 30 characters."];
                continue;
            }

            $class = $existing->get($key);
            $have = $class ? $class->arms->map(fn ($a) => mb_strtolower($a->name))->all() : [];
            $entry = $seen[$key] ?? ['class' => $name, 'level' => $level, 'order' => $row['order'] ?? null, 'exists' => (bool) $class, 'new_arms' => []];
            foreach ($arms as $arm) {
                if (! in_array(mb_strtolower($arm), $have, true) && ! in_array(mb_strtolower($arm), array_map('mb_strtolower', $entry['new_arms']), true)) {
                    $entry['new_arms'][] = $arm;
                }
            }
            $seen[$key] = $entry;
        }
        $plan = array_values($seen);

        $summary = [
            'classes_to_create' => count(array_filter($plan, fn ($p) => ! $p['exists'])),
            'arms_to_create' => array_sum(array_map(fn ($p) => count($p['new_arms']), $plan)),
            'already_there' => count(array_filter($plan, fn ($p) => $p['exists'] && $p['new_arms'] === [])),
        ];

        if (! ($data['commit'] ?? false) || $errors !== []) {
            return response()->json(['plan' => $plan, 'summary' => $summary, 'errors' => $errors, 'committed' => false], $errors !== [] && ($data['commit'] ?? false) ? 422 : 200);
        }

        DB::transaction(function () use ($plan, $schoolId, $existing) {
            foreach ($plan as $p) {
                $class = $existing->get(mb_strtolower($p['class'])) ?? SchoolClass::create([
                    'school_id' => $schoolId, 'name' => $p['class'], 'level_category' => $p['level'], 'order_index' => $p['order'] ?? 0,
                ]);
                foreach ($p['new_arms'] as $arm) {
                    Arm::create(['school_id' => $schoolId, 'class_id' => $class->id, 'name' => $arm]);
                }
            }
        });

        \App\Models\AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'classes.imported',
            'auditable_type' => SchoolClass::class,
            'auditable_id' => 0,
            'new_values' => $summary,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'plan' => $plan, 'summary' => $summary, 'errors' => [], 'committed' => true,
            'message' => "Added {$summary['classes_to_create']} class(es) and {$summary['arms_to_create']} arm(s).",
        ]);
    }

    /** A level key from what was typed, or guessed from the class name. */
    private function levelFor(?string $given, string $className): ?string
    {
        $g = mb_strtolower(trim((string) $given));
        if ($g !== '') {
            $g = str_replace([' ', '-'], '_', $g);
            $aliases = ['jss' => 'junior_secondary', 'junior' => 'junior_secondary', 'jhs' => 'junior_secondary', 'sss' => 'senior_secondary', 'ss' => 'senior_secondary', 'senior' => 'senior_secondary', 'basic' => 'primary', 'kg' => 'nursery', 'pre_school' => 'nursery', 'preschool' => 'nursery'];
            $g = $aliases[$g] ?? $g;

            return array_key_exists($g, Sections::LEVEL_GROUPS) ? $g : null;
        }

        $n = mb_strtolower($className);

        return match (true) {
            (bool) preg_match('/^(jss|jhs|junior)/', $n) => 'junior_secondary',
            (bool) preg_match('/^(sss|ss|shs|senior)\b|^ss\s*\d/', $n) => 'senior_secondary',
            (bool) preg_match('/^(primary|basic|pry|grade)/', $n) => 'primary',
            (bool) preg_match('/^(nursery|kg|kindergarten|creche|crèche|pre)/', $n) => 'nursery',
            default => null,
        };
    }
}

