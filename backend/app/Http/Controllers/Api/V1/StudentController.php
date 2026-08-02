<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $query = Student::with(['user', 'school']);

        if ($request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        if ($request->has('arm_id')) {
            $query->where('arm_id', $request->arm_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })->orWhere('admission_number', 'like', "%{$search}%");
        }

        $students = $query->paginate($request->get('per_page', 20));

        return response()->json($students);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'class_id' => 'nullable|exists:classes,id',
            'arm_id' => 'nullable|exists:arms,id',
            'admission_number' => 'nullable|string',
            'gender' => 'required|in:male,female,other',
            'date_of_birth' => 'nullable|date',
            'state_of_origin' => 'nullable|string',
            'lga' => 'nullable|string',
            'blood_group' => 'nullable|string',
            'allergies' => 'nullable|array',
            'medical_notes' => 'nullable|string',
            'birth_certificate_reference' => 'nullable|string',
            'passport_photo_path' => 'nullable|string',
            'emergency_contacts' => 'nullable|array',
            'avatar' => 'nullable|file|image|max:2048',
            'passport_photo' => 'nullable|file|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = $request->user();
        $schoolId = $admin->userProfile ? $admin->userProfile->school_id : null;

        return DB::transaction(function () use ($request, $admin, $schoolId) {
            $tempPassword = bin2hex(random_bytes(4));

            $passportPhotoPath = $request->passport_photo_path;
            if ($request->hasFile('avatar') || $request->hasFile('passport_photo')) {
                $file = $request->file('avatar') ?? $request->file('passport_photo');
                $passportPhotoPath = $file->store('students/avatars', 'public');
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($tempPassword),
            ]);

            UserProfile::create([
                'school_id' => $schoolId,
                'user_id' => $user->id,
                'role' => 'student',
                'phone' => $request->phone,
                'avatar_url' => $passportPhotoPath,
            ]);

            $student = Student::create([
                'school_id' => $schoolId,
                'user_id' => $user->id,
                'class_id' => $request->class_id,
                'arm_id' => $request->arm_id,
                'admission_number' => $request->admission_number,
                'gender' => $request->gender,
                'date_of_birth' => $request->date_of_birth,
                'state_of_origin' => $request->state_of_origin,
                'lga' => $request->lga,
                'blood_group' => $request->blood_group,
                'allergies' => $request->allergies,
                'medical_notes' => $request->medical_notes,
                'birth_certificate_reference' => $request->birth_certificate_reference,
                'passport_photo_path' => $passportPhotoPath,
                'emergency_contacts' => $request->emergency_contacts,
            ]);

            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $admin->id,
                'action' => 'student.created',
                'auditable_type' => Student::class,
                'auditable_id' => $student->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'Student record created successfully',
                'student' => $student->load('user'),
                'temp_password' => $tempPassword,
            ], 201);
        });
    }

    public function show(Request $request, $id)
    {
        $admin = $request->user();
        $schoolId = $admin->userProfile ? $admin->userProfile->school_id : null;

        $student = Student::where('school_id', $schoolId)->with(['user', 'school'])->findOrFail($id);

        return response()->json(['student' => $student]);
    }

    public function update(Request $request, $id)
    {
        $admin = $request->user();
        $schoolId = $admin->userProfile ? $admin->userProfile->school_id : null;

        $student = Student::where('school_id', $schoolId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $student->user_id,
            'class_id' => 'nullable|exists:classes,id',
            'arm_id' => 'nullable|exists:arms,id',
            'admission_number' => 'nullable|string',
            'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date',
            'state_of_origin' => 'nullable|string',
            'lga' => 'nullable|string',
            'blood_group' => 'nullable|string',
            'allergies' => 'nullable|array',
            'medical_notes' => 'nullable|string',
            'birth_certificate_reference' => 'nullable|string',
            'passport_photo_path' => 'nullable|string',
            'emergency_contacts' => 'nullable|array',
            'avatar' => 'nullable|file|image|max:2048',
            'passport_photo' => 'nullable|file|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return DB::transaction(function () use ($request, $admin, $schoolId, $student) {
            $user = $student->user;
            if ($user && $request->has('name')) {
                $user->name = $request->name;
            }
            if ($user && $request->has('email')) {
                $user->email = $request->email;
            }
            if ($user) {
                $user->save();
            }

            $passportPhotoPath = $request->input('passport_photo_path', $student->passport_photo_path);
            if ($request->hasFile('avatar') || $request->hasFile('passport_photo')) {
                $file = $request->file('avatar') ?? $request->file('passport_photo');
                $passportPhotoPath = $file->store('students/avatars', 'public');

                if ($user && $user->userProfile) {
                    $user->userProfile->update(['avatar_url' => $passportPhotoPath]);
                }
            }

            $student->update([
                'class_id' => $request->get('class_id', $student->class_id),
                'arm_id' => $request->get('arm_id', $student->arm_id),
                'admission_number' => $request->get('admission_number', $student->admission_number),
                'gender' => $request->get('gender', $student->gender),
                'date_of_birth' => $request->get('date_of_birth', $student->date_of_birth),
                'state_of_origin' => $request->get('state_of_origin', $student->state_of_origin),
                'lga' => $request->get('lga', $student->lga),
                'blood_group' => $request->get('blood_group', $student->blood_group),
                'allergies' => $request->get('allergies', $student->allergies),
                'medical_notes' => $request->get('medical_notes', $student->medical_notes),
                'birth_certificate_reference' => $request->get('birth_certificate_reference', $student->birth_certificate_reference),
                'passport_photo_path' => $passportPhotoPath,
                'emergency_contacts' => $request->get('emergency_contacts', $student->emergency_contacts),
            ]);

            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $admin->id,
                'action' => 'student.updated',
                'auditable_type' => Student::class,
                'auditable_id' => $student->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'Student record updated successfully',
                'student' => $student->load('user'),
            ]);
        });
    }

    public function promote(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_ids' => 'required|array',
            'student_ids.*' => 'exists:students,id',
            'target_class_id' => 'required|exists:classes,id',
            'target_arm_id' => 'nullable|exists:arms,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = $request->user();
        $schoolId = $admin->userProfile ? $admin->userProfile->school_id : null;

        $updatedCount = Student::whereIn('id', $request->student_ids)
            ->update([
                'class_id' => $request->target_class_id,
                'arm_id' => $request->target_arm_id,
            ]);

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $admin->id,
            'action' => 'students.promoted',
            'auditable_type' => Student::class,
            'auditable_id' => $request->target_class_id,
            'new_values' => [
                'count' => $updatedCount,
                'student_ids' => $request->student_ids,
                'target_class_id' => $request->target_class_id,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => "Successfully promoted {$updatedCount} students to target class.",
        ]);
    }

    public function bulkImport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt,xlsx',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $file = $request->file('file');
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        $header = array_shift($rows);

        $successCount = 0;
        $errors = [];

        $admin = $request->user();
        $schoolId = $admin->userProfile ? $admin->userProfile->school_id : null;

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2; // Accounting for 1-based index and header row
            if (count($row) < 3) {
                $errors[] = "Row {$rowNum}: Insufficient columns provided.";
                continue;
            }

            $name = trim($row[0]);
            $email = trim($row[1]);
            $gender = strtolower(trim($row[2]));

            if (empty($name) || empty($email)) {
                $errors[] = "Row {$rowNum}: Name and Email are required.";
                continue;
            }

            if (User::where('email', $email)->exists()) {
                $errors[] = "Row {$rowNum}: Email {$email} already exists.";
                continue;
            }

            try {
                DB::transaction(function () use ($name, $email, $gender, $schoolId) {
                    $tempPassword = bin2hex(random_bytes(4));
                    $user = User::create([
                        'name' => $name,
                        'email' => $email,
                        'password' => Hash::make($tempPassword),
                    ]);

                    UserProfile::create([
                        'school_id' => $schoolId,
                        'user_id' => $user->id,
                        'role' => 'student',
                    ]);

                    Student::create([
                        'school_id' => $schoolId,
                        'user_id' => $user->id,
                        'gender' => in_array($gender, ['male', 'female']) ? $gender : 'male',
                    ]);
                });

                $successCount++;
            } catch (\Exception $e) {
                $errors[] = "Row {$rowNum}: Failed to import — " . $e->getMessage();
            }
        }

        return response()->json([
            'message' => "Import complete. {$successCount} students imported successfully.",
            'successful_count' => $successCount,
            'errors' => $errors,
        ]);
    }
}
