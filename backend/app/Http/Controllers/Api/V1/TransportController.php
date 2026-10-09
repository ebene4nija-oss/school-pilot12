<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BusDocument;
use App\Models\BusMaintenanceLog;
use App\Models\School;
use App\Models\Student;
use App\Models\TransportCharge;
use App\Models\TransportRider;
use App\Models\TransportSetting;
use App\Services\Transport\TransportService;
use App\Support\SchoolFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * School bus management: the fleet, who rides (and pays), the bus fee, and
 * each bus's services, repairs, fuel, breakdowns and papers.
 *
 * Every path is under transport/ so the school's Transport module switch
 * closes it; the parent view checks the switch itself.
 */
class TransportController extends Controller
{
    public function __construct(private TransportService $transport)
    {
    }

    public function callAction($method, $parameters)
    {
        try {
            return $this->{$method}(...array_values($parameters));
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    // ------------------------------------------------------------ settings

    public function settings(): JsonResponse
    {
        return response()->json(TransportSetting::for($this->schoolId())->only(['termly_fee', 'renewal_warning_days']));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'termly_fee' => 'sometimes|numeric|min:0|max:10000000',
            'renewal_warning_days' => 'sometimes|integer|min:1|max:120',
        ]);
        $s = TransportSetting::updateOrCreate(['school_id' => $schoolId], $data);
        $this->audit($request, 'transport.settings_updated', $data);

        return response()->json($s->only(['termly_fee', 'renewal_warning_days']) + ['message' => 'Transport settings saved.']);
    }

    // ------------------------------------------------------------ fleet

    /** Every bus with its state: riders, last service, next due, papers expiring soonest, fuel cost per km. */
    public function fleet(): JsonResponse
    {
        $schoolId = $this->schoolId();
        $buses = DB::table('buses')->where('buses.school_id', $schoolId)
            ->leftJoin('bus_routes', 'bus_routes.bus_id', '=', 'buses.id')
            ->orderBy('buses.bus_number')
            ->get(['buses.*', 'bus_routes.route_name']);

        $riders = TransportRider::where('school_id', $schoolId)->whereNull('ended_on')->selectRaw('bus_id, COUNT(*) as n')->groupBy('bus_id')->pluck('n', 'bus_id');
        $logs = BusMaintenanceLog::where('school_id', $schoolId)->orderByDesc('logged_on')->orderByDesc('id')->get()->groupBy('bus_id');
        $docs = BusDocument::where('school_id', $schoolId)->orderBy('expires_on')->get()->groupBy('bus_id');

        return response()->json(['data' => $buses->map(function ($b) use ($riders, $logs, $docs) {
            $busLogs = $logs->get($b->id, collect());
            $service = $busLogs->firstWhere('kind', 'service');
            $fuel = $busLogs->where('kind', 'fuel')->filter(fn ($l) => $l->odometer_km);
            $km = $fuel->count() > 1 ? $fuel->max('odometer_km') - $fuel->min('odometer_km') : 0;
            // The first fill in the window bought fuel for distance before it.
            $fuelCost = $fuel->count() > 1 ? $fuel->sortBy('odometer_km')->slice(1)->sum(fn ($l) => (float) $l->cost) : 0;
            $nextDoc = $docs->get($b->id, collect())->first();

            return [
                'id' => $b->id, 'bus_number' => $b->bus_number, 'plate_number' => $b->plate_number, 'capacity' => $b->capacity,
                'driver_name' => $b->driver_name, 'driver_phone' => $b->driver_phone, 'route_name' => $b->route_name,
                'status' => $b->status ?? 'in_service', 'odometer_km' => $b->odometer_km,
                'riders' => (int) ($riders[$b->id] ?? 0),
                'last_service_on' => $service?->logged_on?->toDateString(),
                'next_service_on' => $service?->next_service_on?->toDateString(),
                'next_service_km' => $service?->next_service_km,
                'next_document' => $nextDoc ? ['kind' => $nextDoc->kind, 'expires_on' => $nextDoc->expires_on->toDateString(), 'days_left' => (int) now()->startOfDay()->diffInDays($nextDoc->expires_on, false)] : null,
                'fuel_cost_per_km' => $km > 0 ? round($fuelCost / $km, 2) : null,
                'costs_this_year' => round((float) $busLogs->filter(fn ($l) => $l->logged_on->year === now()->year)->sum(fn ($l) => (float) $l->cost), 2),
            ];
        })->values()]);
    }

    public function updateBus(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        abort_unless(DB::table('buses')->where('school_id', $schoolId)->where('id', $id)->exists(), 404);
        $data = $request->validate([
            'bus_number' => 'sometimes|string|max:255',
            'plate_number' => 'sometimes|nullable|string|max:20',
            'capacity' => 'sometimes|nullable|integer|min:1|max:200',
            'driver_name' => 'sometimes|string|max:255',
            'driver_phone' => 'sometimes|string|max:32',
            'odometer_km' => 'sometimes|nullable|integer|min:0|max:5000000',
        ]);
        DB::table('buses')->where('id', $id)->update($data + ['updated_at' => now()]);
        $this->audit($request, 'transport.bus_updated', ['bus_id' => $id] + $data);

        return response()->json(['message' => 'Bus saved.']);
    }

    // ------------------------------------------------------------ riders

    public function riders(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $rows = TransportRider::where('school_id', $schoolId)
            ->when(! $request->boolean('include_past'), fn ($q) => $q->whereNull('ended_on'))
            ->when($request->filled('bus_id'), fn ($q) => $q->where('bus_id', $request->integer('bus_id')))
            ->with(['student:id,user_id,class_id,admission_number', 'student.user:id,name', 'student.currentClass:id,name'])
            ->orderByDesc('id')->limit(1000)->get();
        $buses = DB::table('buses')->where('school_id', $schoolId)->pluck('bus_number', 'id');

        return response()->json(['data' => $rows->map(fn (TransportRider $r) => [
            'id' => $r->id, 'student_id' => $r->student_id, 'name' => $r->student?->user?->name,
            'admission_number' => $r->student?->admission_number, 'class_name' => $r->student?->currentClass?->name,
            'bus_id' => $r->bus_id, 'bus_number' => $r->bus_id ? ($buses[$r->bus_id] ?? null) : null,
            'pickup_point' => $r->pickup_point, 'started_on' => $r->started_on?->toDateString(), 'ended_on' => $r->ended_on?->toDateString(),
        ])]);
    }

    public function addRider(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'bus_id' => ['nullable', Rule::exists('buses', 'id')->where('school_id', $schoolId)],
            'pickup_point' => 'nullable|string|max:120',
            'started_on' => 'nullable|date',
        ]);

        if (TransportRider::where('school_id', $schoolId)->where('student_id', $data['student_id'])->whereNull('ended_on')->exists()) {
            throw new DomainRefusal('This student is already on the bus list.');
        }

        $rider = TransportRider::create($data + ['school_id' => $schoolId, 'started_on' => $data['started_on'] ?? now()->toDateString(), 'created_by' => $request->user()->id]);
        $this->audit($request, 'transport.rider_added', ['student_id' => $rider->student_id, 'bus_id' => $rider->bus_id]);

        return response()->json(['message' => 'Added to the bus list. They are billed from the next bus billing.', 'id' => $rider->id], 201);
    }

    public function updateRider(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $rider = TransportRider::where('school_id', $schoolId)->findOrFail($id);
        $data = $request->validate([
            'bus_id' => ['sometimes', 'nullable', Rule::exists('buses', 'id')->where('school_id', $schoolId)],
            'pickup_point' => 'sometimes|nullable|string|max:120',
        ]);
        $rider->update($data);

        return response()->json(['message' => 'Saved.']);
    }

    /** Stops riding: no bus fee from the next billing. Terms already billed stay billed. */
    public function endRider(Request $request, int $id): JsonResponse
    {
        $rider = TransportRider::where('school_id', $this->schoolId())->whereNull('ended_on')->findOrFail($id);
        $data = $request->validate(['ended_on' => 'nullable|date']);
        $rider->update(['ended_on' => $data['ended_on'] ?? now()->toDateString()]);
        $this->audit($request, 'transport.rider_ended', ['student_id' => $rider->student_id]);

        return response()->json(['message' => 'Taken off the bus list. They will not be billed for later terms.']);
    }

    // ------------------------------------------------------------ billing

    public function bill(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate(['term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)]]);
        $r = $this->transport->bill($schoolId, (int) $data['term_id'], $request->user());

        return response()->json($r + ['message' => "{$r['posted']} bus fee(s) added to term invoices.".($r['awaiting_invoice'] ? " {$r['awaiting_invoice']} wait for the student's term invoice in Finance; bill again once it exists." : '')]);
    }

    public function charges(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $charges = TransportCharge::where('school_id', $schoolId)
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->orderByDesc('id')->limit(1000)->get();
        $names = Student::whereIn('students.id', $charges->pluck('student_id')->unique())->join('users', 'users.id', '=', 'students.user_id')->pluck('users.name', 'students.id');

        return response()->json(['data' => $charges->map(fn ($c) => $c->toArray() + ['student_name' => $names[$c->student_id] ?? null])]);
    }

    // ------------------------------------------------------------ upkeep

    public function logs(int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        abort_unless(DB::table('buses')->where('school_id', $schoolId)->where('id', $id)->exists(), 404);

        return response()->json([
            'data' => BusMaintenanceLog::where('school_id', $schoolId)->where('bus_id', $id)->orderByDesc('logged_on')->orderByDesc('id')->limit(300)->get(),
            'documents' => BusDocument::where('school_id', $schoolId)->where('bus_id', $id)->orderBy('expires_on')->get(),
        ]);
    }

    public function addLog(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $bus = DB::table('buses')->where('school_id', $schoolId)->where('id', $id)->first();
        abort_unless($bus, 404);

        $data = $request->validate([
            'kind' => ['required', Rule::in(BusMaintenanceLog::KINDS)],
            'logged_on' => 'required|date|before_or_equal:today',
            'description' => [$request->input('kind') === 'fuel' ? 'nullable' : 'required', 'string', 'max:500'],
            'garage' => 'nullable|string|max:120',
            'cost' => [$request->input('kind') === 'fuel' ? 'required' : 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'odometer_km' => 'nullable|integer|min:0|max:5000000',
            'litres' => 'required_if:kind,fuel|nullable|numeric|min:0.1|max:5000',
            'next_service_on' => 'nullable|date|after:logged_on',
            'next_service_km' => 'nullable|integer|min:1|max:5000000',
            'tell_parents' => 'sometimes|boolean',
        ]);
        $tell = (bool) ($data['tell_parents'] ?? false);
        unset($data['tell_parents']);

        if ($data['kind'] !== 'service') {
            unset($data['next_service_on'], $data['next_service_km']);
        }

        $log = DB::transaction(function () use ($data, $schoolId, $id, $bus, $request) {
            $log = BusMaintenanceLog::create($data + ['school_id' => $schoolId, 'bus_id' => $id, 'created_by' => $request->user()->id]);
            // The highest reading wins: an old receipt entered late doesn't wind the clock back.
            if (! empty($data['odometer_km']) && (int) $data['odometer_km'] > (int) ($bus->odometer_km ?? 0)) {
                DB::table('buses')->where('id', $id)->update(['odometer_km' => $data['odometer_km'], 'updated_at' => now()]);
            }

            return $log;
        });

        $told = $log->kind === 'breakdown' ? $this->transport->breakdown($schoolId, $id, $log, $tell) : 0;
        $this->audit($request, 'transport.log_added', ['bus_id' => $id, 'kind' => $log->kind, 'cost' => $log->cost]);

        return response()->json([
            'log' => $log,
            'message' => match ($log->kind) {
                'breakdown' => 'Bus marked off the road.'.($tell ? " {$told} parent(s) told." : ''),
                'fuel' => 'Fuel recorded.',
                default => 'Saved to the bus record.',
            },
        ], 201);
    }

    public function backInService(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        abort_unless(DB::table('buses')->where('school_id', $schoolId)->where('id', $id)->exists(), 404);
        $data = $request->validate(['tell_parents' => 'sometimes|boolean']);
        $this->transport->backInService($schoolId, $id, (bool) ($data['tell_parents'] ?? false));
        $this->audit($request, 'transport.back_in_service', ['bus_id' => $id]);

        return response()->json(['message' => 'Bus back in service.']);
    }

    public function deleteLog(Request $request, int $logId): JsonResponse
    {
        $log = BusMaintenanceLog::where('school_id', $this->schoolId())->findOrFail($logId);
        $log->delete();
        $this->audit($request, 'transport.log_deleted', ['bus_id' => $log->bus_id, 'kind' => $log->kind]);

        return response()->json(['message' => 'Entry removed.']);
    }

    public function documents(): JsonResponse
    {
        $schoolId = $this->schoolId();
        $buses = DB::table('buses')->where('school_id', $schoolId)->pluck('bus_number', 'id');

        return response()->json(['data' => BusDocument::where('school_id', $schoolId)->orderBy('expires_on')->get()->map(fn ($d) => $d->toArray() + [
            'bus_number' => $buses[$d->bus_id] ?? null,
            'days_left' => (int) now()->startOfDay()->diffInDays($d->expires_on, false),
        ])]);
    }

    public function addDocument(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        abort_unless(DB::table('buses')->where('school_id', $schoolId)->where('id', $id)->exists(), 404);
        $data = $request->validate([
            'kind' => ['required', Rule::in(BusDocument::KINDS)],
            'reference' => 'nullable|string|max:80',
            'expires_on' => 'required|date',
            'notes' => 'nullable|string|max:255',
        ]);
        $doc = BusDocument::create($data + ['school_id' => $schoolId, 'bus_id' => $id]);

        return response()->json(['document' => $doc, 'message' => 'Saved.'], 201);
    }

    /** A renewal: a new expiry date re-arms the reminder. */
    public function updateDocument(Request $request, int $docId): JsonResponse
    {
        $doc = BusDocument::where('school_id', $this->schoolId())->findOrFail($docId);
        $data = $request->validate([
            'reference' => 'sometimes|nullable|string|max:80',
            'expires_on' => 'sometimes|date',
            'notes' => 'sometimes|nullable|string|max:255',
        ]);
        if (isset($data['expires_on']) && $data['expires_on'] !== $doc->expires_on->toDateString()) {
            $data['reminded_on'] = null;
        }
        $doc->update($data);

        return response()->json(['document' => $doc->fresh(), 'message' => 'Renewed.']);
    }

    public function deleteDocument(int $docId): JsonResponse
    {
        BusDocument::where('school_id', $this->schoolId())->findOrFail($docId)->delete();

        return response()->json(['message' => 'Removed.']);
    }

    // ------------------------------------------------------------ parents

    /** A parent's view: is the child on the bus, which one, who drives it, is it running. */
    public function child(Request $request, int $studentId): JsonResponse
    {
        $student = Student::where('school_id', $this->schoolId())->findOrFail($studentId);
        abort_unless($student->isGuardedBy($request->user()), 404);

        if (! SchoolFeatures::moduleEnabled(School::find($student->school_id), 'transport')) {
            return response()->json(['rides' => false]);
        }

        $rider = TransportRider::where('student_id', $student->id)->whereNull('ended_on')->latest('id')->first();
        if (! $rider) {
            return response()->json(['rides' => false]);
        }

        $bus = $rider->bus_id ? DB::table('buses')->leftJoin('bus_routes', 'bus_routes.bus_id', '=', 'buses.id')->where('buses.id', $rider->bus_id)
            ->first(['buses.bus_number', 'buses.driver_name', 'buses.driver_phone', 'buses.status', 'bus_routes.route_name']) : null;

        return response()->json([
            'rides' => true,
            'pickup_point' => $rider->pickup_point,
            'bus' => $bus ? ['bus_number' => $bus->bus_number, 'route_name' => $bus->route_name, 'driver_name' => $bus->driver_name, 'driver_phone' => $bus->driver_phone, 'in_service' => ($bus->status ?? 'in_service') === 'in_service'] : null,
            'charges' => TransportCharge::where('student_id', $student->id)->where('status', '!=', 'void')->orderByDesc('id')->limit(6)->get(['term_id', 'amount', 'status']),
        ]);
    }

    private function audit(Request $request, string $action, array $values): void
    {
        AuditLog::create(['school_id' => $this->schoolId(), 'user_id' => $request->user()->id, 'action' => $action, 'auditable_type' => TransportRider::class, 'auditable_id' => 0, 'new_values' => $values]);
    }
}
