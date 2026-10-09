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
}
