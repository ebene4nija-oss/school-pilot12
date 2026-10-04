<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LiveClass;
use App\Models\VideoProviderConnection;
use App\Services\Lms\LmsService;
use App\Services\Lms\ProviderAttendanceService;
use App\Services\Lms\Providers\GoogleMeetAttendance;
use App\Services\Lms\Providers\ZoomAttendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Reading virtual-class attendance from Zoom or Google Meet (TASK 14 §3.3).
 * An admin connects the school's own account; the class teacher reads the
 * report after a class (it is also read automatically) and says who any
 * unmatched participant was.
 */
class ProviderAttendanceController extends Controller
{
    public function __construct(private ProviderAttendanceService $attendance, private LmsService $lms)
    {
    }

    /** Which providers are connected. Credentials never leave the server. */
    public function connections(): JsonResponse
    {
        return response()->json(['data' => VideoProviderConnection::where('school_id', $this->schoolId())->get(['id', 'provider', 'status', 'last_error', 'last_synced_at', 'updated_at'])]);
    }

    /** Connect (or replace) a provider account; the credentials are tried before they are saved. */
    public function connect(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, ProviderAttendanceService::PROVIDERS, true), 404);
        $creds = $provider === 'zoom'
            ? $request->validate(['account_id' => 'required|string|max:100', 'client_id' => 'required|string|max:100', 'client_secret' => 'required|string|max:200'])
            : $request->validate(['service_account_json' => 'required|string|max:10000', 'impersonate' => 'required|email|max:191']);

        try {
            $provider === 'zoom' ? app(ZoomAttendance::class)->token($creds) : app(GoogleMeetAttendance::class)->token($creds);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $conn = VideoProviderConnection::updateOrCreate(
            ['school_id' => $this->schoolId(), 'provider' => $provider],
            ['credentials' => $creds, 'status' => 'connected', 'last_error' => null, 'connected_by' => $request->user()->id],
        );
        AuditLog::create(['school_id' => $this->schoolId(), 'user_id' => $request->user()->id, 'action' => 'lms.provider_connected', 'auditable_type' => VideoProviderConnection::class, 'auditable_id' => $conn->id, 'new_values' => ['provider' => $provider]]);

        return response()->json(['message' => ($provider === 'zoom' ? 'Zoom' : 'Google Meet').' is connected. Attendance will be read after each class.', 'connection' => $conn->only(['id', 'provider', 'status'])]);
    }

    public function disconnect(Request $request, string $provider): JsonResponse
    {
        $conn = VideoProviderConnection::where('school_id', $this->schoolId())->where('provider', $provider)->firstOrFail();
        $conn->delete();
        AuditLog::create(['school_id' => $this->schoolId(), 'user_id' => $request->user()->id, 'action' => 'lms.provider_disconnected', 'auditable_type' => VideoProviderConnection::class, 'auditable_id' => $conn->id, 'new_values' => ['provider' => $provider]]);

        return response()->json(['message' => 'Disconnected. The credentials were deleted.']);
    }

    /** Read the report now, instead of waiting for the automatic run. */
    public function sync(Request $request, int $id): JsonResponse
    {
        $lc = $this->forStaff($request, $id);
        try {
            $r = $this->attendance->sync($lc);
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($r + ['message' => "Read from the provider: {$r['matched']} student(s) found in the call".($r['unmatched'] ? ", {$r['unmatched']} participant(s) to match." : '.')]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $lc = $this->forStaff($request, $id);
        $data = $request->validate(['participant_key' => 'required|string|max:191', 'student_id' => 'required|integer', 'remember' => 'boolean']);
        try {
            $this->attendance->assign($lc, $data['participant_key'], (int) $data['student_id'], (bool) ($data['remember'] ?? true));
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
        $this->lms->audit($request->user(), 'lms.provider_participant_matched', LiveClass::class, $lc->id, ['student_id' => $data['student_id']]);

        return response()->json(['message' => 'Matched.']);
    }

    private function forStaff(Request $request, int $id): LiveClass
    {
        $lc = LiveClass::where('school_id', $this->schoolId())->findOrFail($id);
        $user = $request->user();
        abort_unless((int) $lc->teacher_id === $user->id || $this->lms->isAdmin($user), 403, 'Only the class teacher or an admin manages this class.');

        return $lc;
    }
}
