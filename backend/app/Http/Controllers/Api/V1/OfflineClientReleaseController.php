<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OfflineClientRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The offline CBT lab client's releases (desktop/, docs/offline-cbt-client.md).
 *
 * An operator registers each installer by its download URL, version and
 * SHA-256, then publishes it. The marketing site links to one stable address,
 * /public/downloads/offline-cbt, which redirects to whichever release is
 * current, so a new version reaches visitors the moment it is published,
 * without rebuilding the site. Installed clients ask /latest for the current
 * version at moments they are already online (login, provision, sync), never
 * during an exam.
 *
 * Installers are not uploaded here: UploadScanner refuses programs on every
 * endpoint, on purpose, and the installer with WebView2 inside is well over
 * the server's 25 MB upload limit.
 */
class OfflineClientReleaseController extends Controller
{
    // ------------------------------------------------------------ operator

    public function index(): JsonResponse
    {
        $current = OfflineClientRelease::current();

        return response()->json([
            'data' => OfflineClientRelease::all()
                ->sort(fn ($a, $b) => version_compare($b->version, $a->version))
                ->values()
                ->map(fn ($r) => $this->present($r) + ['is_current' => $current?->id === $r->id]),
            'download_link' => self::stableLink(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $release = new OfflineClientRelease($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->stamp($release);
        $release->save();
        $this->audit($request, 'offline_client.release.created', $release);

        return response()->json(['data' => $this->present($release)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $release = OfflineClientRelease::findOrFail($id);
        $release->fill($this->validated($request, $release));
        $this->stamp($release);
        $release->save();
        $this->audit($request, 'offline_client.release.updated', $release);

        return response()->json(['data' => $this->present($release)]);
    }

    /** Only a draft can be deleted; a release schools may have installed is retired instead, so its record stays. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $release = OfflineClientRelease::findOrFail($id);

        if ($release->status !== 'draft') {
            return response()->json(['message' => 'Only a draft can be deleted. Retire a published release instead.'], 422);
        }

        $this->audit($request, 'offline_client.release.deleted', $release);
        $release->delete();

        return response()->json(['deleted' => true]);
    }

    // -------------------------------------------------------------- public

    /** The stable download link: always the current release. */
    public function download(): RedirectResponse|JsonResponse
    {
        $release = OfflineClientRelease::current();

        if (! $release) {
            return response()->json(['message' => 'The offline CBT client is not available for download yet.'], 404);
        }

        $release->increment('downloads');

        return redirect()->away($release->download_url, 302)->header('Cache-Control', 'no-store');
    }

    /** What an installed client, and the site's build, read to learn the current version. */
    public function latest(): JsonResponse
    {
        return response()->json(['data' => self::summary()]);
    }

    /** @return array<string, mixed>|null */
    public static function summary(): ?array
    {
        $release = OfflineClientRelease::current();

        if (! $release) {
            return null;
        }

        return [
            'version' => $release->version,
            'sha256' => $release->sha256,
            'size_bytes' => $release->size_bytes,
            'notes' => $release->notes,
            'published_at' => $release->published_at?->toIso8601String(),
            'min_supported_version' => OfflineClientRelease::minimumSupported(),
            'download_url' => self::stableLink(),
        ];
    }

    // ------------------------------------------------------------- helpers

    private static function stableLink(): string
    {
        return url('/api/v1/public/downloads/offline-cbt');
    }

    private function validated(Request $request, ?OfflineClientRelease $release = null): array
    {
        $data = $request->validate([
            'version' => [$release ? 'sometimes' : 'required', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+$/', Rule::unique('offline_client_releases', 'version')->ignore($release?->id)],
            'download_url' => [$release ? 'sometimes' : 'required', 'url:https', 'max:500'],
            'sha256' => [$release ? 'sometimes' : 'required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'size_bytes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'is_required' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(OfflineClientRelease::STATUSES)],
        ], [
            'version.regex' => "Use the installer's version number, e.g. 1.2.0.",
            'sha256.regex' => 'Paste the 64-character SHA-256 of the installer (Get-FileHash in PowerShell prints it).',
            'download_url.url' => 'Use an https:// link to the installer.',
        ]);

        if (isset($data['sha256'])) {
            $data['sha256'] = strtolower($data['sha256']);
        }

        // Schools may have installed a published release: its file can't change under the same version.
        if ($release && $release->status !== 'draft') {
            foreach (['version', 'download_url', 'sha256'] as $locked) {
                if (array_key_exists($locked, $data) && (string) $data[$locked] !== (string) $release->{$locked}) {
                    abort(response()->json([
                        'message' => 'A published release keeps its version, file and checksum. Add a new release instead.',
                        'errors' => [$locked => ['Locked once published. Add a new release instead.']],
                    ], 422));
                }
            }
        }

        return $data;
    }

    /** Stamped once, the first time a release is published. */
    private function stamp(OfflineClientRelease $release): void
    {
        if ($release->status === 'published' && ! $release->published_at) {
            $release->published_at = now();
        }
    }

    private function present(OfflineClientRelease $r): array
    {
        return [
            'id' => $r->id,
            'version' => $r->version,
            'download_url' => $r->download_url,
            'sha256' => $r->sha256,
            'size_bytes' => $r->size_bytes,
            'notes' => $r->notes,
            'is_required' => (bool) $r->is_required,
            'status' => $r->status ?? 'draft',
            'published_at' => $r->published_at?->toIso8601String(),
            'downloads' => (int) $r->downloads,
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }

    private function audit(Request $request, string $action, OfflineClientRelease $r): void
    {
        AuditLog::create([
            'school_id' => null, 'user_id' => $request->user()->id, 'action' => $action,
            'auditable_type' => OfflineClientRelease::class, 'auditable_id' => $r->id,
            'new_values' => ['version' => $r->version, 'status' => $r->status, 'is_required' => (bool) $r->is_required],
            'ip_address' => $request->ip(),
        ]);
    }
}
