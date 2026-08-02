<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TimetableSolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TimetableController extends Controller
{
    protected $solver;

    public function __construct(TimetableSolverService $solver)
    {
        $this->solver = $solver;
    }

    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'class_ids' => 'required|array',
            'assignments' => 'required|array',
            'slots' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $result = $this->solver->generateTimetable(
            $schoolId ?? 1,
            $request->class_ids,
            $request->assignments,
            $request->slots
        );

        if (!$result['success']) {
            return response()->json([
                'message' => 'Scheduling conflicts encountered during generation.',
                'conflicts' => $result['conflicts'],
            ], 422);
        }

        return response()->json([
            'message' => 'Conflict-free master timetable generated successfully.',
            'timetable' => $result['timetable'],
        ]);
    }
}
