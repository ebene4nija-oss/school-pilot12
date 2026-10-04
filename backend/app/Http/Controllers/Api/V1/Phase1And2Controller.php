<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Phase1And2Controller extends Controller
{
    /**
     * Which school this request is acting on.
     *
     * The resolved tenant comes first, not the user's own profile. A platform
     * operator inspecting one school through the console has a null
     * `school_id` on their profile but a tenant set by BindTenantFromUser, and
     * reading the profile first meant every one of these endpoints answered
     * with a null school for them — writing rows with no owner and listing
     * nothing back.
     */
    private function getSchoolId(Request $request)
    {
        /*
         * Tenant first — see Controller::schoolId(), which prefers the resolved
         * tenant, falls back to the acting user's own profile, and returns null
         * for a request that is explicitly unscoped.
         *
         * The guard here used to test the user's profile *first*. A platform
         * operator has no school on their profile, so for them the guard was
         * always false and this fell through to `tenant_school` — the school
         * resolved from the host, which for an operator is the internal `admin`
         * subdomain they signed in through, not the tenant they are inspecting.
         * These endpoints therefore answered for the wrong school entirely.
         */
        $schoolId = $this->schoolId();

        if ($schoolId !== null) {
            return $schoolId;
        }

        // Host-resolved tenant, for routes reached without an authenticated
        // profile at all.
        $tenant = $request->attributes->get('tenant_school');

        return $tenant ? $tenant->id : $request->attributes->get('school_id');
    }

    // 1. Scholarships & Installments
    public function storeScholarship(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric',
            'description' => 'nullable|string',
        ]);

        $schoolId = $this->getSchoolId($request);
        $id = DB::table('scholarships')->insertGetId(array_merge($validated, [
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(['message' => 'Scholarship created', 'id' => $id], 201);
    }

    /**
     * The fleet, each bus with its route and last known position.
     *
     * Position comes from the driver's own phone (doc §3 — no purchased
     * trackers), so `last_ping_at` is the honest signal of whether a location
     * means anything. A pin from this morning on an afternoon run is stale, and
     * the caller is given the timestamp to say so rather than a bare
     * coordinate that looks live.
     */
    public function buses(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $buses = DB::table('buses')
            ->where('buses.school_id', $schoolId)
            ->leftJoin('bus_routes', 'bus_routes.bus_id', '=', 'buses.id')
            ->select([
                'buses.id',
                'buses.bus_number',
                'buses.driver_name',
                'buses.driver_phone',
                'bus_routes.id as route_id',
                'bus_routes.route_name',
                'bus_routes.is_active',
                'bus_routes.current_lat',
                'bus_routes.current_lng',
                'bus_routes.last_ping_at',
            ])
            ->orderBy('buses.bus_number')
            ->get()
            ->map(function ($row) {
                $pingedAt = $row->last_ping_at ? \Illuminate\Support\Carbon::parse($row->last_ping_at) : null;

                return [
                    'id' => (int) $row->id,
                    'bus_number' => $row->bus_number,
                    'driver_name' => $row->driver_name,
                    'driver_phone' => $row->driver_phone,
                    'route_id' => $row->route_id ? (int) $row->route_id : null,
                    'route_name' => $row->route_name,
                    'is_active' => (bool) $row->is_active,
                    'current_lat' => $row->current_lat !== null ? (float) $row->current_lat : null,
                    'current_lng' => $row->current_lng !== null ? (float) $row->current_lng : null,
                    'last_ping_at' => $pingedAt?->toIso8601String(),
                    // Anything older than 10 minutes is not a live position.
                    'position_is_fresh' => $pingedAt !== null && $pingedAt->gt(now()->subMinutes(10)),
                ];
            });

        return response()->json(['data' => $buses]);
    }

    /** Register a bus and, optionally, the route it runs. */
    public function storeBus(Request $request)
    {
        $validated = $request->validate([
            'bus_number' => 'required|string|max:255',
            'driver_name' => 'required|string|max:255',
            'driver_phone' => 'required|string|max:32',
            'route_name' => 'nullable|string|max:255',
        ]);

        $schoolId = $this->getSchoolId($request);

        $busId = DB::table('buses')->insertGetId([
            'school_id' => $schoolId,
            'bus_number' => $validated['bus_number'],
            'driver_name' => $validated['driver_name'],
            'driver_phone' => $validated['driver_phone'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! empty($validated['route_name'])) {
            DB::table('bus_routes')->insert([
                'school_id' => $schoolId,
                'bus_id' => $busId,
                'route_name' => $validated['route_name'],
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Bus registered', 'id' => $busId], 201);
    }

    // 2. Hardware-Free GPS Bus Tracking
    public function updateBusLocation(Request $request, $busId)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $schoolId = $this->getSchoolId($request);

        DB::table('bus_routes')
            ->where('school_id', $schoolId)
            ->where('bus_id', $busId)
            ->update([
                'current_lat' => $validated['lat'],
                'current_lng' => $validated['lng'],
                'last_ping_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Bus GPS location updated']);
    }

    /**
     * The library catalogue, with what is actually on the shelf.
     *
     * `available_copies` is stored rather than derived from `borrow_records`,
     * so it is reported alongside the outstanding-loan count: if the two
     * disagree the librarian can see it, instead of the discrepancy being
     * hidden behind whichever number the page happened to render.
     */
    public function books(Request $request)
    {
        $schoolId = $this->getSchoolId($request);
        $search = trim((string) $request->query('search', ''));

        $query = DB::table('books')->where('books.school_id', $schoolId);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('author', 'like', "%{$search}%")
                    ->orWhere('isbn', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $books = $query->orderBy('title')->paginate(50);

        $ids = collect($books->items())->pluck('id');

        $onLoan = DB::table('borrow_records')
            ->whereIn('book_id', $ids)
            ->whereNull('returned_at')
            ->selectRaw('book_id, COUNT(*) as total')
            ->groupBy('book_id')
            ->pluck('total', 'book_id');

        $overdue = DB::table('borrow_records')
            ->whereIn('book_id', $ids)
            ->whereNull('returned_at')
            ->whereDate('due_date', '<', now()->toDateString())
            ->selectRaw('book_id, COUNT(*) as total')
            ->groupBy('book_id')
            ->pluck('total', 'book_id');

        return response()->json([
            'data' => collect($books->items())->map(fn ($book) => [
                'id' => (int) $book->id,
                'title' => $book->title,
                'author' => $book->author,
                'isbn' => $book->isbn,
                'barcode' => $book->barcode,
                'total_copies' => (int) $book->total_copies,
                'available_copies' => (int) $book->available_copies,
                'on_loan' => (int) ($onLoan[$book->id] ?? 0),
                'overdue' => (int) ($overdue[$book->id] ?? 0),
            ]),
            'meta' => [
                'current_page' => $books->currentPage(),
                'last_page' => $books->lastPage(),
                'total' => $books->total(),
            ],
        ]);
    }

    /** Add a title to the catalogue. */
    public function storeBook(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'isbn' => 'nullable|string|max:32',
            'barcode' => 'required|string|max:64',
            'total_copies' => 'required|integer|min:1',
        ]);

        // The barcode is what the phone camera looks up, so it has to identify
        // exactly one title within the school.
        $clash = DB::table('books')
            ->where('school_id', $schoolId)
            ->where('barcode', $validated['barcode'])
            ->exists();

        if ($clash) {
            return response()->json([
                'message' => 'A book with that barcode already exists in this school.',
                'errors' => ['barcode' => ['This barcode is already in use.']],
            ], 422);
        }

        $id = DB::table('books')->insertGetId([
            'school_id' => $schoolId,
            'title' => $validated['title'],
            'author' => $validated['author'],
            'isbn' => $validated['isbn'] ?? null,
            'barcode' => $validated['barcode'],
            'total_copies' => $validated['total_copies'],
            'available_copies' => $validated['total_copies'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Book added to catalogue', 'id' => $id], 201);
    }

    // 3. Hardware-Free Digital Library (Phone Camera Barcode Lookup)
    public function scanBookBarcode(Request $request)
    {
        $validated = $request->validate([
            'barcode' => 'required|string',
        ]);

        $schoolId = $this->getSchoolId($request);
        $book = DB::table('books')
            ->where('school_id', $schoolId)
            ->where('barcode', $validated['barcode'])
            ->first();

        if (!$book) {
            return response()->json(['message' => 'Book not found'], 404);
        }

        return response()->json(['data' => $book]);
    }

    /*
     * The original hostel endpoints, kept for existing clients, now reading
     * and writing the full hostel model (HostelService) so there is one
     * source of truth. Shapes and error fields are unchanged. New work uses
     * /hostel/structure, /hostel/allocations and the rest of the module.
     */

    /** Hostel rooms with live occupancy, counted from active allocations. */
    public function rooms(Request $request)
    {
        $rooms = app(\App\Services\Hostel\HostelService::class)->rooms($this->getSchoolId($request));

        return response()->json([
            'data' => $rooms->map(fn ($r) => [
                'id' => $r['id'],
                'hostel_name' => $r['hostel'],
                'room_number' => $r['name'],
                'capacity' => $r['capacity'],
                'occupied' => $r['occupied'],
                'beds_free' => max($r['capacity'] - $r['occupied'], 0),
                // Reported, not inferred by the page: a room over capacity
                // is an allocation error somebody has to see.
                'is_full' => $r['occupied'] >= $r['capacity'],
                'is_over_capacity' => $r['occupied'] > $r['capacity'],
            ])->values(),
        ]);
    }

    /** Create a hostel room (and its hostel, the first time a name is used). */
    public function storeRoom(Request $request)
    {
        $validated = $request->validate([
            'hostel_name' => 'required|string|max:255',
            'room_number' => 'required|string|max:64',
            'capacity' => 'required|integer|min:1|max:64',
        ]);

        $schoolId = $this->getSchoolId($request);
        $hostel = \App\Models\Hostel::firstOrCreate(['school_id' => $schoolId, 'name' => $validated['hostel_name']], ['status' => 'active']);
        $room = app(\App\Services\Hostel\HostelService::class)->createRoom($hostel, ['name' => $validated['room_number'], 'capacity' => $validated['capacity']]);

        return response()->json(['message' => 'Room created', 'id' => $room->id], 201);
    }

    /** Who is in which bed, for one school. */
    public function bedAssignments(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $assignments = DB::table('hostel_allocations as a')
            ->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')
            ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
            ->join('hostels as h', 'h.id', '=', 'r.hostel_id')
            ->join('students', 'a.student_id', '=', 'students.id')
            ->join('users', 'students.user_id', '=', 'users.id')
            ->where('a.school_id', $schoolId)
            ->where('a.status', 'active')
            ->select([
                'a.id',
                'b.label as bed_number',
                'r.id as room_id',
                'h.name as hostel_name',
                'r.name as room_number',
                'students.id as student_id',
                'users.name as student_name',
                'students.admission_number',
            ])
            ->orderBy('h.name')
            ->orderBy('r.name')
            ->get();

        return response()->json(['data' => $assignments]);
    }

    /** Put a student in a bed, by room and bed label. */
    public function assignBed(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validated = $request->validate([
            'room_id' => ['required', \Illuminate\Validation\Rule::exists('hostel_rooms', 'id')->where('school_id', $schoolId)],
            'student_id' => ['required', \Illuminate\Validation\Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'bed_number' => 'required|string|max:32',
        ]);

        // The old form typed a bed label freely; an unknown label becomes a
        // bed in the room, still subject to the room's capacity.
        $bed = \App\Models\HostelBed::firstOrCreate(
            ['room_id' => $validated['room_id'], 'label' => $validated['bed_number']],
            ['school_id' => $schoolId, 'status' => 'available']
        );

        try {
            $allocation = app(\App\Services\Hostel\HostelService::class)->allocate($schoolId, (int) $validated['student_id'], $bed, $request->user(), ['legacy' => true, 'reason' => 'Assigned from the room list']);
        } catch (\App\Services\Hostel\AllocationRefused $e) {
            if ($bed->wasRecentlyCreated) {
                $bed->delete();
            }

            return response()->json(['message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]], 422);
        } catch (\App\Exceptions\DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Bed assigned successfully', 'id' => $allocation->id], 201);
    }

    /** Release a bed: the allocation ends; its history stays. */
    public function releaseBed(Request $request, $assignmentId)
    {
        $allocation = \App\Models\HostelAllocation::where('school_id', $this->getSchoolId($request))->where('status', 'active')->find($assignmentId);

        if (! $allocation) {
            return response()->json(['message' => 'Bed assignment not found'], 404);
        }

        app(\App\Services\Hostel\HostelService::class)->endAllocation($allocation, $request->user(), 'Released from the room list');

        return response()->json(['message' => 'Bed released']);
    }

    /**
     * The clinic log.
     *
     * Symptoms and treatment are health data about a child, so this is
     * deliberately the narrowest read that still answers "who was seen and
     * what was done": no blood group, no allergy list, no medical notes from
     * the student record, and no free-text search across the log (doc §12,
     * NDPA data minimisation). A single student's history is fetched through
     * `student_id` by someone who already knows which child they are asking
     * about.
     */
    public function clinicVisits(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validated = $request->validate([
            'student_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = \App\Models\ClinicVisit::query()
            ->where('clinic_visits.school_id', $schoolId)
            ->join('students', 'clinic_visits.student_id', '=', 'students.id')
            ->join('users', 'students.user_id', '=', 'users.id')
            ->select([
                'clinic_visits.id',
                'clinic_visits.student_id',
                'clinic_visits.symptoms',
                'clinic_visits.treatment',
                'clinic_visits.attending_nurse',
                'clinic_visits.visited_at',
                'users.name as student_name',
                'students.admission_number',
            ]);

        if (! empty($validated['student_id'])) {
            $query->where('clinic_visits.student_id', $validated['student_id']);
        }

        if (! empty($validated['from'])) {
            $query->where('clinic_visits.visited_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->where('clinic_visits.visited_at', '<=', $validated['to']);
        }

        $visits = $query->orderByDesc('clinic_visits.visited_at')->paginate(50);

        return response()->json([
            'data' => collect($visits->items())->map(fn ($visit) => [
                'id' => (int) $visit->id,
                'student_id' => (int) $visit->student_id,
                'student_name' => $visit->student_name,
                'admission_number' => $visit->admission_number,
                'symptoms' => $visit->symptoms,
                'treatment' => $visit->treatment,
                'attending_nurse' => $visit->attending_nurse,
                'visited_at' => $visit->visited_at
                    ? \Illuminate\Support\Carbon::parse($visit->visited_at)->toIso8601String()
                    : null,
            ]),
            'meta' => [
                'current_page' => $visits->currentPage(),
                'last_page' => $visits->lastPage(),
                'total' => $visits->total(),
            ],
        ]);
    }

    // 5. Health & School Clinic Log
    public function recordClinicVisit(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validated = $request->validate([
            'student_id' => ['required', \Illuminate\Validation\Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'symptoms' => 'required|string',
            'treatment' => 'required|string',
            // A nurse recording her own visit is the attending nurse; anyone
            // recording on a nurse's behalf names her.
            'attending_nurse' => [
                \Illuminate\Validation\Rule::requiredIf(fn () => $request->user()?->userProfile?->role !== 'nurse'),
                'nullable', 'string', 'max:255',
            ],
        ]);

        $visit = \App\Models\ClinicVisit::create([
            'school_id' => $schoolId,
            'student_id' => $validated['student_id'],
            'symptoms' => $validated['symptoms'],
            'treatment' => $validated['treatment'],
            'attending_nurse' => filled($validated['attending_nurse'] ?? null)
                ? $validated['attending_nurse']
                : $request->user()->name,
            'visited_at' => now(),
        ]);

        return response()->json(['message' => 'Clinic visit recorded successfully under NDPA encrypted storage', 'id' => $visit->id], 201);
    }
}
