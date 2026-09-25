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
        $tenant = app(TenantContext::class);

        if ($tenant->hasSchool()) {
            return $tenant->schoolId();
        }

        if ($request->user() && $request->user()->userProfile && $request->user()->userProfile->school_id) {
            return $request->user()->userProfile->school_id;
        }

        $tenantSchool = $request->attributes->get('tenant_school');
        if ($tenantSchool) {
            return $tenantSchool->id;
        }

        return $request->attributes->get('school_id');
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

    /**
     * Hostel rooms with live occupancy, counted from actual bed assignments.
     *
     * `bed_assignments` has no `school_id` of its own — it is owned through its
     * room — so the count joins back through `rooms` to stay inside the tenant.
     */
    public function rooms(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $rooms = DB::table('rooms')
            ->where('rooms.school_id', $schoolId)
            ->orderBy('rooms.hostel_name')
            ->orderBy('rooms.room_number')
            ->get();

        $occupancy = DB::table('bed_assignments')
            ->join('rooms', 'bed_assignments.room_id', '=', 'rooms.id')
            ->where('rooms.school_id', $schoolId)
            ->selectRaw('bed_assignments.room_id, COUNT(*) as total')
            ->groupBy('bed_assignments.room_id')
            ->pluck('total', 'room_id');

        return response()->json([
            'data' => $rooms->map(function ($room) use ($occupancy) {
                $occupied = (int) ($occupancy[$room->id] ?? 0);
                $capacity = (int) $room->capacity;

                return [
                    'id' => (int) $room->id,
                    'hostel_name' => $room->hostel_name,
                    'room_number' => $room->room_number,
                    'capacity' => $capacity,
                    'occupied' => $occupied,
                    'beds_free' => max($capacity - $occupied, 0),
                    // Reported, not inferred by the page: a room over capacity
                    // is an allocation error somebody has to see.
                    'is_full' => $occupied >= $capacity,
                    'is_over_capacity' => $occupied > $capacity,
                ];
            }),
        ]);
    }

    /** Create a hostel room. */
    public function storeRoom(Request $request)
    {
        $validated = $request->validate([
            'hostel_name' => 'required|string|max:255',
            'room_number' => 'required|string|max:64',
            'capacity' => 'required|integer|min:1|max:64',
        ]);

        $schoolId = $this->getSchoolId($request);

        $id = DB::table('rooms')->insertGetId([
            'school_id' => $schoolId,
            'hostel_name' => $validated['hostel_name'],
            'room_number' => $validated['room_number'],
            'capacity' => $validated['capacity'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Room created', 'id' => $id], 201);
    }

    /** Who is in which bed, for one school. */
    public function bedAssignments(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $assignments = DB::table('bed_assignments')
            ->join('rooms', 'bed_assignments.room_id', '=', 'rooms.id')
            ->join('students', 'bed_assignments.student_id', '=', 'students.id')
            // The student's name is on `users`; `students` holds only the
            // enrolment record.
            ->join('users', 'students.user_id', '=', 'users.id')
            ->where('rooms.school_id', $schoolId)
            ->select([
                'bed_assignments.id',
                'bed_assignments.bed_number',
                'bed_assignments.room_id',
                'rooms.hostel_name',
                'rooms.room_number',
                'students.id as student_id',
                'users.name as student_name',
                'students.admission_number',
            ])
            ->orderBy('rooms.hostel_name')
            ->orderBy('rooms.room_number')
            ->get();

        return response()->json(['data' => $assignments]);
    }

    // 4. Hostel & Boarding Bed Allocation
    public function assignBed(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        /*
         * Both the room and the student are checked against this school.
         * `exists:rooms,id` alone let a school admin assign one of their
         * students into another school's room — `bed_assignments` carries no
         * `school_id`, so nothing downstream would ever have caught it.
         */
        $validated = $request->validate([
            'room_id' => ['required', \Illuminate\Validation\Rule::exists('rooms', 'id')->where('school_id', $schoolId)],
            'student_id' => ['required', \Illuminate\Validation\Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'bed_number' => 'required|string|max:32',
        ]);

        // A bed holds one student, and a student sleeps in one bed.
        $bedTaken = DB::table('bed_assignments')
            ->where('room_id', $validated['room_id'])
            ->where('bed_number', $validated['bed_number'])
            ->exists();

        if ($bedTaken) {
            return response()->json([
                'message' => 'That bed is already assigned.',
                'errors' => ['bed_number' => ['This bed is occupied.']],
            ], 422);
        }

        $alreadyPlaced = DB::table('bed_assignments')
            ->where('student_id', $validated['student_id'])
            ->exists();

        if ($alreadyPlaced) {
            return response()->json([
                'message' => 'That student already has a bed. Release the current assignment first.',
                'errors' => ['student_id' => ['Student is already assigned a bed.']],
            ], 422);
        }

        $room = DB::table('rooms')->where('id', $validated['room_id'])->first();

        $occupied = DB::table('bed_assignments')->where('room_id', $validated['room_id'])->count();

        if ($room && $occupied >= (int) $room->capacity) {
            return response()->json([
                'message' => 'That room is at capacity.',
                'errors' => ['room_id' => ['Room is full.']],
            ], 422);
        }

        $id = DB::table('bed_assignments')->insertGetId(array_merge($validated, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(['message' => 'Bed assigned successfully', 'id' => $id], 201);
    }

    /** Release a bed, when a boarder leaves or moves room. */
    public function releaseBed(Request $request, $assignmentId)
    {
        $schoolId = $this->getSchoolId($request);

        $deleted = DB::table('bed_assignments')
            ->whereIn('room_id', DB::table('rooms')->where('school_id', $schoolId)->select('id'))
            ->where('id', $assignmentId)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Bed assignment not found'], 404);
        }

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
            'attending_nurse' => 'required|string',
        ]);

        $visit = \App\Models\ClinicVisit::create([
            'school_id' => $schoolId,
            'student_id' => $validated['student_id'],
            'symptoms' => $validated['symptoms'],
            'treatment' => $validated['treatment'],
            'attending_nurse' => $validated['attending_nurse'],
            'visited_at' => now(),
        ]);

        return response()->json(['message' => 'Clinic visit recorded successfully under NDPA encrypted storage', 'id' => $visit->id], 201);
    }
}
