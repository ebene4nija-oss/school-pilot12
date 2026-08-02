<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Phase1And2Controller extends Controller
{
    private function getSchoolId(Request $request)
    {
        if ($request->user() && $request->user()->userProfile && $request->user()->userProfile->school_id) {
            return $request->user()->userProfile->school_id;
        }

        $tenant = $request->attributes->get('tenant_school');
        if ($tenant) {
            return $tenant->id;
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

    // 4. Hostel & Boarding Bed Allocation
    public function assignBed(Request $request)
    {
        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'student_id' => 'required|exists:students,id',
            'bed_number' => 'required|string',
        ]);

        $id = DB::table('bed_assignments')->insertGetId(array_merge($validated, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(['message' => 'Bed assigned successfully', 'id' => $id], 201);
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
