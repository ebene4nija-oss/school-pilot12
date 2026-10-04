<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Venue;
use App\Services\Timetable\VenueService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Venues and where each lesson takes place (TASK 07). Admins keep the list of
 * rooms; a teacher sets where their own lesson is — every week, or for one
 * day — and is refused if another class is already there.
 */
class VenueController extends Controller
{
    public function __construct(private VenueService $venues)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => Venue::where('school_id', $this->schoolId())->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $venue = Venue::create($this->validated($request) + ['school_id' => $this->schoolId()]);

        return response()->json(['venue' => $venue], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $venue = Venue::where('school_id', $this->schoolId())->findOrFail($id);
        $venue->update($this->validated($request, $venue->id));

        return response()->json(['venue' => $venue->fresh()]);
    }

    /** Venues free when this lesson runs (on a date, if given). */
    public function free(Request $request, int $entryId): JsonResponse
    {
        $schoolId = $this->schoolId();
        $entry = $this->entry($schoolId, $entryId);
        $date = $request->filled('date') ? Carbon::parse($request->input('date'))->toDateString() : null;

        return response()->json(['data' => $this->venues->freeFor($entry, $schoolId, $date)]);
    }

    /** Where this lesson is held every week. null puts it back in the class's own room. */
    public function setForEntry(Request $request, int $entryId): JsonResponse
    {
        $schoolId = $this->schoolId();
        $entry = $this->entry($schoolId, $entryId);
        $this->assertMayMove($request, $entry);
        $data = $request->validate(['venue_id' => ['present', 'nullable', 'integer', Rule::exists('venues', 'id')->where('school_id', $schoolId)->where('active', true)]]);

        if ($data['venue_id'] && ($other = $this->venues->conflictFor($entry, (int) $data['venue_id'], $schoolId))) {
            return $this->taken($other, (int) $data['venue_id']);
        }
        DB::table('timetable_entries')->where('id', $entryId)->update(['venue_id' => $data['venue_id'], 'updated_at' => now()]);
        AuditLog::create(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'action' => 'timetable.venue_set', 'auditable_type' => 'timetable_entry', 'auditable_id' => $entryId,
            'old_values' => ['venue_id' => $entry->venue_id], 'new_values' => ['venue_id' => $data['venue_id']]]);

        return response()->json(['message' => 'Venue saved.', 'venue' => $this->venues->namesFor(collect([DB::table('timetable_entries')->find($entryId)]), $schoolId)[$entryId] ?? null]);
    }

    /** One day somewhere else: "Thursday's Chemistry is in the lab". */
    public function moveForDay(Request $request, int $entryId): JsonResponse
    {
        $schoolId = $this->schoolId();
        $entry = $this->entry($schoolId, $entryId);
        $this->assertMayMove($request, $entry);
        $data = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->where('school_id', $schoolId)->where('active', true)],
            'reason' => 'nullable|string|max:255',
        ]);
        $date = Carbon::parse($data['date'])->toDateString();
        if (VenueService::dayOf($date) !== $entry->day_of_week) {
            return response()->json(['message' => "This lesson is on {$entry->day_of_week}s; {$date} is a ".VenueService::dayOf($date).'.'], 422);
        }

        DB::table('timetable_venue_changes')->where('entry_id', $entryId)->whereDate('date', $date)->delete();
        if (! $data['venue_id']) {
            return response()->json(['message' => 'Back in its usual venue that day.']);
        }
        if ($other = $this->venues->conflictFor($entry, (int) $data['venue_id'], $schoolId, $date)) {
            return $this->taken($other, (int) $data['venue_id']);
        }
        DB::table('timetable_venue_changes')->insert([
            'school_id' => $schoolId, 'entry_id' => $entryId, 'date' => $date, 'venue_id' => $data['venue_id'],
            'reason' => $data['reason'] ?? null, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Moved for '.Carbon::parse($date)->format('D j M').'.'], 201);
    }

    private function entry(int $schoolId, int $id): object
    {
        $entry = DB::table('timetable_entries as e')->join('timetable_versions as v', 'v.id', '=', 'e.timetable_version_id')
            ->where('v.school_id', $schoolId)->where('e.id', $id)->first(['e.*']);
        abort_unless($entry, 404);

        return $entry;
    }

    /** Admins move any lesson; a teacher moves their own. */
    private function assertMayMove(Request $request, object $entry): void
    {
        $role = $request->user()->userProfile?->role;
        abort_unless(in_array($role, ['super_admin', 'school_admin'], true) || (int) $entry->teacher_id === $request->user()->id, 403, 'You can change the venue of your own lessons only.');
    }

    private function taken(object $other, int $venueId): JsonResponse
    {
        $name = DB::table('venues')->where('id', $venueId)->value('name');

        return response()->json(['message' => "{$name} is taken then by {$other->class} ({$other->slot_name})."], 409);
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $schoolId = $this->schoolId();

        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('venues', 'name')->where('school_id', $schoolId)->ignore($ignore)],
            'kind' => 'sometimes|in:classroom,lab,hall,library,field,other',
            'capacity' => 'nullable|integer|min:1|max:5000',
            'class_id' => ['nullable', 'integer', Rule::exists('classes', 'id')->where('school_id', $schoolId), Rule::unique('venues', 'class_id')->where('school_id', $schoolId)->ignore($ignore)],
            'shared' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ]);
    }
}
