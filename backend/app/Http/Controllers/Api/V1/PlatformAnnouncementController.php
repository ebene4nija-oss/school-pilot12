<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformAnnouncement;
use App\Models\School;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Notices from SchoolPilot to school administrators: a maintenance window, a
 * release, a policy change.
 *
 * Shown in the school admin's portal, and nowhere else. Parents and staff are
 * the school's contacts, not SchoolPilot's (NDPA, doc §12); a school that
 * wants to pass a notice on uses its own Notifications screen.
 */
class PlatformAnnouncementController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    /** Operator: every announcement, newest first. */
    public function index()
    {
        $rows = PlatformAnnouncement::query()
            ->with(['school:id,name', 'author:id,name'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (PlatformAnnouncement $row) => $this->present($row, true)),
        ]);
    }

    /** Operator: publish one, to every school or one school. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:150',
            'body' => 'required|string|max:2000',
            'tone' => 'nullable|in:info,warning',
            'school_id' => 'nullable|integer',
            'shows_until' => 'required|date|after:now',
        ]);

        if (! empty($validated['school_id'])) {
            $exists = $this->tenant->withoutScoping(
                fn () => School::query()->tenants()->whereKey($validated['school_id'])->exists()
            );

            if (! $exists) {
                return response()->json(['errors' => ['school_id' => ['No such school.']]], 422);
            }
        }

        $announcement = PlatformAnnouncement::create([
            'school_id' => $validated['school_id'] ?? null,
            'title' => trim($validated['title']),
            'body' => trim($validated['body']),
            'tone' => $validated['tone'] ?? 'info',
            'shows_until' => $validated['shows_until'],
            'created_by' => $request->user()->id,
        ]);

        $this->audit($request, $announcement, 'platform.announcement.published', [
            'title' => $announcement->title,
            'school_id' => $announcement->school_id,
            'shows_until' => $announcement->shows_until->toIso8601String(),
        ]);

        return response()->json([
            'message' => 'Announcement published.',
            'data' => $this->present($announcement->load(['school:id,name', 'author:id,name']), true),
        ], 201);
    }

    /** Operator: take one down before its end date. */
    public function withdraw(Request $request, int $id)
    {
        $announcement = PlatformAnnouncement::find($id);

        if (! $announcement) {
            return response()->json(['message' => 'Announcement not found'], 404);
        }

        if (! $announcement->withdrawn_at) {
            $announcement->update(['withdrawn_at' => now()]);
            $this->audit($request, $announcement, 'platform.announcement.withdrawn', ['title' => $announcement->title]);
        }

        return response()->json(['message' => 'Announcement withdrawn.']);
    }

    /** School admin: what SchoolPilot is currently telling this school. */
    public function forSchool()
    {
        $schoolId = $this->tenant->schoolId();

        if (! $schoolId) {
            return response()->json(['data' => []]);
        }

        $rows = PlatformAnnouncement::query()
            ->showing()
            ->for($schoolId)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (PlatformAnnouncement $row) => $this->present($row, false)),
        ]);
    }

    private function present(PlatformAnnouncement $row, bool $forOperator): array
    {
        $data = [
            'id' => $row->id,
            'title' => $row->title,
            'body' => $row->body,
            'tone' => $row->tone,
            'published_at' => $row->created_at?->toIso8601String(),
            'shows_until' => $row->shows_until->toIso8601String(),
        ];

        if ($forOperator) {
            $data += [
                'school' => $row->school ? ['id' => $row->school->id, 'name' => $row->school->name] : null,
                'author' => $row->author?->name,
                'withdrawn_at' => $row->withdrawn_at?->toIso8601String(),
                'is_showing' => ! $row->withdrawn_at && $row->shows_until->isFuture(),
            ];
        }

        return $data;
    }

    private function audit(Request $request, PlatformAnnouncement $row, string $action, array $values): void
    {
        AuditLog::create([
            'school_id' => $row->school_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => PlatformAnnouncement::class,
            'auditable_id' => $row->id,
            'new_values' => $values,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
