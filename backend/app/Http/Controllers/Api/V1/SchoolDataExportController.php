<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSchoolDataExportJob;
use App\Models\AuditLog;
use App\Models\SchoolDataExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * School-initiated data exports.
 *
 * Worth being precise about what this is and is not. It is *portability*: the
 * copy a school owns, can take elsewhere, or can produce for an NDPA subject
 * request. It is not the backup — nightly database snapshots are the platform's
 * job and no amount of admins clicking Export substitutes for them.
 */
class SchoolDataExportController extends Controller
{
    /** One archive a day. Building one reads most of the database. */
    private const DAILY_LIMIT = 1;

    private function schoolId(Request $request): ?int
    {
        return $request->user()?->userProfile?->school_id;
    }

    public function index(Request $request)
    {
        $exports = SchoolDataExport::where('school_id', $this->schoolId($request))
            ->with('requestedBy:id,name')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn ($export) => $this->present($export));

        return response()->json(['data' => $exports]);
    }

    public function store(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validator = Validator::make($request->all(), [
            'include_medical' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $today = SchoolDataExport::where('school_id', $schoolId)
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('status', ['pending', 'processing', 'complete'])
            ->count();

        if ($today >= self::DAILY_LIMIT) {
            return response()->json([
                'message' => 'An export was already requested in the last 24 hours. '
                    . 'Download that one, or try again tomorrow.',
            ], 429);
        }

        $includeMedical = $request->boolean('include_medical');

        $export = SchoolDataExport::create([
            'school_id' => $schoolId,
            'requested_by' => $request->user()->id,
            'status' => 'pending',
            'includes_medical' => $includeMedical,
        ]);

        /*
         * Health data leaving the system is a decision, not a checkbox
         * default, and it is audited whether or not the archive is ever
         * downloaded — the request itself is the event worth recording.
         */
        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => $includeMedical ? 'school.export_requested_with_medical' : 'school.export_requested',
            'auditable_type' => SchoolDataExport::class,
            'auditable_id' => $export->id,
            'new_values' => ['includes_medical' => $includeMedical],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        GenerateSchoolDataExportJob::dispatch($export->id);

        return response()->json([
            'message' => 'Your export is being prepared. It usually takes a minute or two.',
            'export' => $this->present($export->fresh()),
        ], 202);
    }

    public function show(Request $request, $id)
    {
        $export = SchoolDataExport::where('school_id', $this->schoolId($request))->findOrFail($id);

        return response()->json(['export' => $this->present($export)]);
    }

    public function download(Request $request, $id)
    {
        $schoolId = $this->schoolId($request);

        $export = SchoolDataExport::where('school_id', $schoolId)->findOrFail($id);

        if (! $export->isDownloadable()) {
            return response()->json([
                'message' => match (true) {
                    $export->status === 'failed' => 'That export failed to build. Request a new one.',
                    $export->status !== 'complete' => 'That export is still being prepared.',
                    default => 'That export has expired. Request a new one.',
                },
            ], 409);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($export->file_path)) {
            $export->update(['status' => 'failed', 'error' => 'Archive file is missing from storage.']);

            return response()->json(['message' => 'That export is no longer on disk. Request a new one.'], 410);
        }

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'school.export_downloaded',
            'auditable_type' => SchoolDataExport::class,
            'auditable_id' => $export->id,
            'new_values' => ['includes_medical' => $export->includes_medical],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $disk->download(
            $export->file_path,
            'schoolpilot-export-' . $export->id . '-' . $export->created_at->format('Y-m-d') . '.zip'
        );
    }

    private function present(SchoolDataExport $export): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status,
            'includes_medical' => $export->includes_medical,
            'row_counts' => $export->row_counts,
            'file_size' => $export->file_size,
            'requested_by' => $export->requestedBy->name ?? null,
            'requested_at' => $export->created_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'downloadable' => $export->isDownloadable(),
            'error' => $export->error,
        ];
    }
}
