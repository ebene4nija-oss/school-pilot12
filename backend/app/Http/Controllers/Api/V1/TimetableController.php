<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
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
            'version_name' => 'nullable|string',
            'max_daily_teacher_workload' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user && $user->userProfile ? $user->userProfile->school_id : 1;

        $result = $this->solver->generateTimetable(
            $schoolId,
            $request->class_ids,
            $request->assignments,
            $request->slots,
            [
                'version_name' => $request->input('version_name', 'Automated Draft'),
                'max_daily_teacher_workload' => $request->input('max_daily_teacher_workload', 6),
                'save_draft' => true,
            ]
        );

        if (!$result['success']) {
            return response()->json([
                'message' => 'Scheduling conflicts encountered during generation.',
                'conflicts' => $result['conflicts'],
            ], 422);
        }

        return response()->json([
            'message' => 'Conflict-free timetable generated and saved as draft.',
            'version' => $result['version'],
            'timetable' => $result['timetable'],
        ], 200);
    }

    public function versions(Request $request)
    {
        $user = $request->user();
        $schoolId = $user && $user->userProfile ? $user->userProfile->school_id : 1;

        $versions = TimetableVersion::where('school_id', $schoolId)
            ->withCount('entries')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'versions' => $versions,
        ]);
    }

    public function publish(Request $request, $id)
    {
        $user = $request->user();
        $schoolId = $user && $user->userProfile ? $user->userProfile->school_id : 1;

        $version = TimetableVersion::where('school_id', $schoolId)->where('id', $id)->firstOrFail();

        // Unpublish other versions for this school
        TimetableVersion::where('school_id', $schoolId)->update(['status' => 'draft']);

        $version->status = 'published';
        $version->published_at = now();
        $version->save();

        return response()->json([
            'message' => 'Timetable version published successfully.',
            'version' => $version,
        ]);
    }

    public function view(Request $request)
    {
        $user = $request->user();
        $schoolId = $user && $user->userProfile ? $user->userProfile->school_id : 1;

        $versionId = $request->query('version_id');
        $classId = $request->query('class_id');
        $teacherId = $request->query('teacher_id');

        $query = TimetableEntry::whereHas('version', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        });

        if ($versionId) {
            $query->where('timetable_version_id', $versionId);
        } else {
            // Default to published version if version_id is not specified
            $query->whereHas('version', function ($q) {
                $q->where('status', 'published');
            });
        }

        if ($classId) {
            $query->where('school_class_id', $classId);
        }

        if ($teacherId) {
            $query->where('teacher_id', $teacherId);
        }

        $entries = $query->get();

        return response()->json([
            'entries' => $entries,
        ]);
    }
}
