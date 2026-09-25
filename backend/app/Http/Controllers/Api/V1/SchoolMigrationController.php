<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SchoolMigrationRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Assisted data migration: a school asking SchoolPilot to bring its old records
 * across from paper, a spreadsheet or a rival portal.
 *
 * The self-service path is the bulk CSV importer and is untouched. This exists
 * because the portal advertised a staffed 24-hour migration behind a form that
 * resolved a `setTimeout`: the school was told "successfully queued" and
 * nothing was recorded, so the commitment reached nobody who could honour it.
 */
class SchoolMigrationController extends Controller
{
    /** How long the school is told the migration will take. */
    private const TURNAROUND_HOURS = 24;

    public function __construct(private TenantContext $tenant)
    {
    }

    /** This school's own requests, newest first. */
    public function index(Request $request)
    {
        $requests = SchoolMigrationRequest::with('requester:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $requests->map(fn (SchoolMigrationRequest $row) => $this->present($row)),
            'turnaround_hours' => self::TURNAROUND_HOURS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'previous_system' => 'required|string|max:255',
            'source_format' => 'required|in:excel,sql_dump,competitor_portal,paper,other',
            'notes' => 'nullable|string|max:2000',
            'approximate_records' => 'nullable|integer|min:1|max:1000000',
        ]);

        $schoolId = $this->tenant->schoolId() ?? $request->user()->userProfile?->school_id;

        if (! $schoolId) {
            return response()->json(['message' => 'No school context for this user.'], 403);
        }

        /*
         * One open request at a time. A proprietor who does not see a response
         * clicks the button again, and three duplicate jobs for one school is
         * how an operator loses track of which upload is the real one.
         */
        $open = SchoolMigrationRequest::whereIn('status', ['submitted', 'in_progress'])->first();

        if ($open) {
            return response()->json([
                'message' => 'A migration request is already open for this school.',
                'data' => $this->present($open),
            ], 409);
        }

        $migration = SchoolMigrationRequest::create([
            'school_id' => $schoolId,
            'requested_by' => $request->user()->id,
            'previous_system' => $validated['previous_system'],
            'source_format' => $validated['source_format'],
            'notes' => $validated['notes'] ?? null,
            'approximate_records' => $validated['approximate_records'] ?? null,
            'status' => 'submitted',
            // Recorded at submission, so the promise is a date rather than a
            // claim on a marketing page.
            'due_at' => now()->addHours(self::TURNAROUND_HOURS),
        ]);

        return response()->json([
            'message' => 'Migration request received.',
            'data' => $this->present($migration->fresh('requester')),
        ], 201);
    }

    /** A school withdrawing its own request. */
    public function cancel(int $id)
    {
        $migration = SchoolMigrationRequest::find($id);

        if (! $migration) {
            return response()->json(['message' => 'Migration request not found'], 404);
        }

        if (in_array($migration->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => 'That request is already closed.'], 422);
        }

        $migration->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Migration request cancelled.',
            'data' => $this->present($migration),
        ]);
    }

    /**
     * Every school's requests, for the operator working the queue.
     *
     * Overdue first: this queue exists to be worked, and the request that has
     * already broken its promise is the one that matters most.
     */
    public function queue()
    {
        return $this->tenant->withoutScoping(function () {
            $requests = SchoolMigrationRequest::query()
                ->with(['requester:id,name', 'school:id,name,subdomain'])
                ->whereIn('status', ['submitted', 'in_progress'])
                ->orderByRaw('due_at is null, due_at asc')
                ->get();

            return response()->json([
                'data' => $requests->map(fn (SchoolMigrationRequest $row) => $this->present($row) + [
                    'school' => [
                        'id' => $row->school?->id,
                        'name' => $row->school?->name,
                        'subdomain' => $row->school?->subdomain,
                    ],
                ]),
            ]);
        });
    }

    /** An operator moving a request along. */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:submitted,in_progress,completed,cancelled',
            'operator_notes' => 'nullable|string|max:2000',
        ]);

        return $this->tenant->withoutScoping(function () use ($id, $validated) {
            $migration = SchoolMigrationRequest::find($id);

            if (! $migration) {
                return response()->json(['message' => 'Migration request not found'], 404);
            }

            $migration->status = $validated['status'];

            if (array_key_exists('operator_notes', $validated)) {
                $migration->operator_notes = $validated['operator_notes'];
            }

            if ($validated['status'] === 'completed' && ! $migration->completed_at) {
                $migration->completed_at = now();
            }

            $migration->save();

            return response()->json([
                'message' => 'Migration request updated.',
                'data' => $this->present($migration),
            ]);
        });
    }

    private function present(SchoolMigrationRequest $row): array
    {
        return [
            'id' => $row->id,
            'previous_system' => $row->previous_system,
            'source_format' => $row->source_format,
            'notes' => $row->notes,
            'approximate_records' => $row->approximate_records,
            'status' => $row->status,
            'requested_by' => $row->requester?->name,
            'submitted_at' => $row->created_at?->toIso8601String(),
            'due_at' => $row->due_at?->toIso8601String(),
            'completed_at' => $row->completed_at?->toIso8601String(),
            'operator_notes' => $row->operator_notes,
            'is_overdue' => $row->is_overdue,
        ];
    }
}
