<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Backups and data requests (doc §12), for the operator.
 *
 * Backups: read-only. What deploy/cpanel/backup-db.sh left on disk and in its
 * log, plus restore tests the operator records by hand — the script cannot
 * know a restore was tried, and "a backup you have never restored is a
 * hypothesis". Nothing here writes, restores or decrypts a backup.
 *
 * Data requests: exports schools asked for, and parental consent
 * withdrawals, across every school. Dates, schools and statuses — no child
 * or parent names.
 */
class PlatformComplianceController extends Controller
{
    /** A nightly job: older than this and the last backup is overdue. */
    private const STALE_AFTER_HOURS = 26;

    public function backups()
    {
        $dir = (string) config('schoolpilot.backup_dir');
        $log = (string) config('schoolpilot.backup_log');

        $files = [];
        $readable = $dir !== '' && is_dir($dir) && is_readable($dir);

        if ($readable) {
            foreach (glob(rtrim($dir, '/').'/*.dump.gpg') ?: [] as $path) {
                $files[] = ['name' => basename($path), 'size' => filesize($path), 'modified_at' => date(DATE_ATOM, filemtime($path))];
            }

            usort($files, fn ($a, $b) => strcmp($b['modified_at'], $a['modified_at']));
        }

        $fromLog = $this->readLog($log);
        $lastSuccess = $fromLog['last_success_at'] ?? ($files[0]['modified_at'] ?? null);

        $status = match (true) {
            ! $readable && $fromLog === null => 'unknown',
            $lastSuccess === null => 'missing',
            now()->diffInHours($lastSuccess, true) > self::STALE_AFTER_HOURS => 'stale',
            ($fromLog['failed_since_success'] ?? false) => 'failing',
            default => 'ok',
        };

        $lastRestore = AuditLog::query()
            ->withoutGlobalScopes()
            ->whereNull('school_id')
            ->where('action', 'platform.backup.restore_tested')
            ->latest('id')
            ->with('user:id,name')
            ->limit(5)
            ->get()
            ->map(fn (AuditLog $a) => [
                'tested_on' => $a->new_values['tested_on'] ?? null,
                'outcome' => $a->new_values['outcome'] ?? null,
                'notes' => $a->new_values['notes'] ?? null,
                'recorded_by' => $a->user?->name,
                'recorded_at' => $a->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'status' => $status,
            'stale_after_hours' => self::STALE_AFTER_HOURS,
            'visible' => $readable || $fromLog !== null,
            'last_success_at' => $lastSuccess,
            'last_file' => $fromLog['last_file'] ?? ($files[0]['name'] ?? null),
            'last_size' => $fromLog['last_size'] ?? null,
            'offsite' => $fromLog['offsite'] ?? null,
            'last_error' => $fromLog['last_error'] ?? null,
            'files_kept' => count($files),
            'files' => array_slice($files, 0, 20),
            'restore_tests' => $lastRestore,
        ]);
    }

    /** The operator recording that they restored a backup to check it. */
    public function recordRestoreTest(Request $request)
    {
        $validated = $request->validate([
            'tested_on' => 'required|date|before_or_equal:today',
            'outcome' => 'required|in:passed,failed',
            'notes' => 'nullable|string|max:500',
        ]);

        AuditLog::create([
            'school_id' => null,
            'user_id' => $request->user()->id,
            'action' => 'platform.backup.restore_tested',
            'auditable_type' => 'backup',
            'auditable_id' => 0,
            'new_values' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json(['message' => 'Restore test recorded.'], 201);
    }

    public function dataRequests()
    {
        $names = School::query()->tenants()->pluck('name', 'id');

        $exports = DB::table('school_data_exports')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'school_data_exports.requested_by')
            ->select(
                'school_data_exports.id', 'school_data_exports.school_id', 'school_data_exports.status',
                'school_data_exports.includes_medical', 'school_data_exports.file_size',
                'school_data_exports.created_at', 'school_data_exports.completed_at', 'school_data_exports.expires_at',
                'user_profiles.role as requested_by_role'
            )
            ->orderByDesc('school_data_exports.id')
            ->limit(100)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'school_id' => $e->school_id,
                'school' => $names[$e->school_id] ?? null,
                'status' => $e->status,
                'includes_medical' => (bool) $e->includes_medical,
                'file_size' => $e->file_size !== null ? (int) $e->file_size : null,
                'requested_at' => $e->created_at,
                'completed_at' => $e->completed_at,
                'expires_at' => $e->expires_at,
                'requested_by_role' => $e->requested_by_role,
            ]);

        $withdrawals = DB::table('parental_consents')
            ->whereNotNull('withdrawn_at')
            ->select('school_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN ai_cross_border_consent_given THEN 0 ELSE 1 END) as ai_withdrawn')
            ->selectRaw('MAX(withdrawn_at) as latest_at')
            ->groupBy('school_id')
            ->get()
            ->map(fn ($w) => [
                'school_id' => $w->school_id,
                'school' => $names[$w->school_id] ?? null,
                'withdrawals' => (int) $w->total,
                'ai_cross_border_withdrawn' => (int) $w->ai_withdrawn,
                'latest_at' => $w->latest_at,
            ])
            ->sortByDesc('latest_at')
            ->values();

        return response()->json([
            'exports' => $exports,
            'export_totals' => [
                'total' => $exports->count(),
                'with_medical' => $exports->where('includes_medical', true)->count(),
                'failed' => $exports->where('status', 'failed')->count(),
            ],
            'consent_withdrawals' => $withdrawals,
            // There is no erasure-request workflow yet. Said plainly rather
            // than shown as an empty list, which would read as "none received".
            'deletion_requests' => null,
            'deletion_requests_note' => 'SchoolPilot has no deletion-request workflow yet. Requests that arrive by email or phone are not tracked here.',
        ]);
    }

    /**
     * What backup-db.sh said: the last "backup ok" line, and whether anything
     * failed after it.
     *
     * @return array<string, mixed>|null null when the log cannot be read
     */
    private function readLog(string $path): ?array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        // The tail is enough: one run writes a line or two.
        $size = filesize($path);
        $handle = fopen($path, 'r');
        fseek($handle, max(0, $size - 65536));
        $lines = preg_split('/\r?\n/', (string) stream_get_contents($handle));
        fclose($handle);

        $result = ['failed_since_success' => false];

        foreach ($lines as $i => $line) {
            if (preg_match('/^(\S+) backup ok: (\S+) \(([^)]+)\)/', $line, $m)) {
                $result['last_success_at'] = $m[1];
                $result['last_file'] = $m[2];
                $result['last_size'] = $m[3];
                $result['last_error'] = null;
                $result['failed_since_success'] = false;
                // The offsite warning is printed during the same run, just before.
                $window = array_slice($lines, max(0, $i - 5), 5);
                $result['offsite'] = ! collect($window)->contains(fn ($l) => str_contains($l, 'BACKUP_S3_BUCKET unset'));
            } elseif (str_starts_with($line, 'fatal:')) {
                $result['last_error'] = trim(substr($line, 6));
                $result['failed_since_success'] = true;
            }
        }

        return $result;
    }
}
