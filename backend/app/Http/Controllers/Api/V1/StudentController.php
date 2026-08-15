<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentMedicalResource;
use App\Http\Resources\StudentResource;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentClassHistory;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\PasswordResetService;
use App\Services\StudentImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        // `school` was eager-loaded onto every row and never used — the same
        // tenant repeated 20 times per page, on connections parents pay for by
        // the megabyte.
        $query = Student::with(['user:id,name,email', 'currentClass:id,name', 'currentArm:id,name']);

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

        $students = $query->paginate(min((int) $request->input('per_page', 20), 100));

        return StudentResource::collection($students)->response();
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

        $resets = app(PasswordResetService::class);

        $student = DB::transaction(function () use ($request, $admin, $schoolId) {
            /*
             * Never seen by anyone, including the admin creating the record.
             * It exists so the row is never briefly stored with a guessable
             * password; the student receives a setup link instead, sent once
             * the transaction has committed.
             */
            $tempPassword = bin2hex(random_bytes(32));

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

            return $student;
        });

        // Outside the transaction: a mail transport hiccup must not roll back
        // an admission. The student can always use "Forgot password" instead.
        $delivery = $resets->sendSetupLink($student->user, School::find($schoolId));

        return response()->json([
            'message' => 'Student record created successfully',
            'student' => new StudentResource($student->load('user:id,name,email')),
            'setup_link_sent' => ($delivery['email']['status'] ?? null) === 'sent',
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $admin = $request->user();
        $schoolId = $admin->userProfile ? $admin->userProfile->school_id : null;

        $student = Student::where('school_id', $schoolId)
            ->with(['user:id,name,email', 'currentClass:id,name', 'currentArm:id,name'])
            ->findOrFail($id);

        $this->authorize('view', $student);

        return response()->json(['student' => new StudentResource($student)]);
    }

    /**
     * A child's health record.
     *
     * Separated from `show()` deliberately. Blood group, allergies, medical
     * notes and emergency contacts are special-category data under NDPA (doc
     * §12), and they were previously returned to anyone who could list the
     * roster — which includes every teacher in the school.
     *
     * Three constraints here that the roster does not have: a narrower role
     * list, an object-level policy check, and an audit row. "Who read this
     * child's medical notes, and when" is a question a school will eventually
     * be asked, and it is only answerable if there is one way in.
     */
    public function medical(Request $request, $id)
    {
        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $student = Student::where('school_id', $schoolId)->with('user:id,name')->findOrFail($id);

        $this->authorize('view', $student);

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'action' => 'student.medical_accessed',
            'auditable_type' => Student::class,
            'auditable_id' => $student->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['medical' => new StudentMedicalResource($student)]);
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
                'student' => new StudentResource($student->load('user:id,name,email')),
            ]);
        });
    }

    public function promote(Request $request)
    {
        $admin = $request->user();
        $schoolId = $admin->userProfile ? $admin->userProfile->school_id : null;

        /*
         * Every id is scoped to the acting admin's school.
         *
         * A bare `exists:classes,id` passes for *any* school's class, and the
         * destination was the one side of this operation that was never
         * tenant-checked: the students were loaded with a `where school_id`,
         * so a foreign `target_class_id` did not fail — it wrote this school's
         * children into another school's class and left them there.
         */
        $validator = Validator::make($request->all(), [
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'exists:students,id',
            'target_class_id' => [
                'required',
                Rule::exists('classes', 'id')->where('school_id', $schoolId),
            ],
            'target_arm_id' => [
                'nullable',
                Rule::exists('arms', 'id')
                    ->where('school_id', $schoolId)
                    ->where('class_id', $request->input('target_class_id')),
            ],
            'session_id' => [
                'required',
                Rule::exists('academic_sessions', 'id')->where('school_id', $schoolId),
            ],
            'action' => 'required|in:promote,repeat,transfer',
            'is_leaving_school' => 'nullable|boolean',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return DB::transaction(function () use ($request, $admin, $schoolId) {
            // Load only students that belong to the admin's school
            $students = Student::where('school_id', $schoolId)
                ->whereIn('id', $request->student_ids)
                ->get();

            if ($students->isEmpty()) {
                return response()->json([
                    'message' => 'No matching students found in your school.',
                ], 404);
            }

            $historyRecords = [];
            $movedStudentIds = [];

            foreach ($students as $student) {
                // Create a history record preserving the before/after snapshot
                $historyRecords[] = StudentClassHistory::create([
                    'school_id' => $schoolId,
                    'student_id' => $student->id,
                    'from_class_id' => $student->class_id,
                    'from_arm_id' => $student->arm_id,
                    'to_class_id' => $request->target_class_id,
                    'to_arm_id' => $request->target_arm_id,
                    'session_id' => $request->session_id,
                    'action' => $request->action,
                    'remarks' => $request->remarks,
                    'performed_by' => $admin->id,
                ]);

                // Update the student's current placement
                $updateData = [
                    'class_id' => $request->target_class_id,
                    'arm_id' => $request->target_arm_id,
                ];

                $isLeaving = $request->action === 'transfer'
                    && $request->boolean('is_leaving_school', false);

                // If transferring out of the school, mark student as transferred
                if ($isLeaving) {
                    $updateData['status'] = 'transferred';
                }

                $student->update($updateData);

                /*
                 * Keep the enrolment record in step.
                 *
                 * This endpoint predates `student_enrollments` and is still
                 * the right call for moving a handful of children between
                 * arms, so it cannot be left writing only the old audit trail
                 * — a placement made here would be invisible to the rollover
                 * preview, and those students would silently sit out the next
                 * promotion.
                 */
                if ($isLeaving) {
                    StudentEnrollment::where('student_id', $student->id)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'closed',
                            'outcome' => 'transferred_out',
                            'closed_on' => now()->toDateString(),
                            'outcome_remarks' => $request->remarks,
                            'recorded_by' => $admin->id,
                        ]);
                } else {
                    StudentEnrollment::updateOrCreate(
                        ['student_id' => $student->id, 'session_id' => $request->session_id],
                        [
                            'school_id' => $schoolId,
                            'class_id' => $request->target_class_id,
                            'arm_id' => $request->target_arm_id,
                            'status' => 'active',
                            'enrolled_on' => now()->toDateString(),
                            'recorded_by' => $admin->id,
                        ]
                    );
                }

                $movedStudentIds[] = $student->id;
            }

            $actionLabel = match ($request->action) {
                'promote' => 'promoted',
                'repeat' => 'retained (repeat)',
                'transfer' => 'transferred',
            };

            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $admin->id,
                'action' => "students.{$request->action}",
                'auditable_type' => Student::class,
                'auditable_id' => $request->target_class_id,
                'old_values' => [
                    'student_ids' => $movedStudentIds,
                ],
                'new_values' => [
                    'count' => count($movedStudentIds),
                    'target_class_id' => $request->target_class_id,
                    'target_arm_id' => $request->target_arm_id,
                    'session_id' => $request->session_id,
                    'action' => $request->action,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $count = count($movedStudentIds);

            return response()->json([
                'message' => "Successfully {$actionLabel} {$count} student(s).",
                'count' => $count,
                'student_ids' => $movedStudentIds,
            ]);
        });
    }

    public function classHistory(Request $request, $id)
    {
        $admin = $request->user();
        $schoolId = $admin->userProfile ? $admin->userProfile->school_id : null;

        $student = Student::where('school_id', $schoolId)->findOrFail($id);

        $history = StudentClassHistory::where('student_id', $student->id)
            ->where('school_id', $schoolId)
            ->with(['fromClass', 'toClass', 'fromArm', 'toArm', 'session', 'performedBy:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'student_id' => $student->id,
            'history' => $history,
        ]);
    }

    /**
     * Read a register and report what would happen. Writes nothing.
     *
     * The first half of the import. An admin uploads the class list, sees
     * every row resolved — name, class, arm, guardian — with the bad ones
     * named and numbered, and only then commits. Importing 400 children was
     * previously a single irreversible click with no way to look first.
     */
    public function importPreview(Request $request, StudentImportService $importer)
    {
        $admin = $request->user();
        $schoolId = $admin->userProfile ? $admin->userProfile->school_id : null;

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:5120',
            'class_id' => [
                'nullable',
                Rule::exists('classes', 'id')->where('school_id', $schoolId),
            ],
            'arm_id' => [
                'nullable',
                Rule::exists('arms', 'id')
                    ->where('school_id', $schoolId)
                    ->where('class_id', $request->input('class_id')),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $analysis = $importer->analyse($request->file('file'), $schoolId, [
            'default_class_id' => $request->input('class_id'),
            'default_arm_id' => $request->input('arm_id'),
        ]);

        if (isset($analysis['fatal'])) {
            return response()->json(['message' => $analysis['fatal']], 422);
        }

        /*
         * The validated rows are parked in the cache rather than handed back
         * for the client to return: re-posting them would let anything that
         * failed validation walk straight back in as "ready". The token is
         * bound to the school, so another tenant redeeming it finds nothing.
         */
        $token = (string) Str::uuid();

        Cache::put("student-import:{$schoolId}:{$token}", [
            'rows' => $analysis['rows'],
            'class_id' => $request->input('class_id'),
            'arm_id' => $request->input('arm_id'),
        ], now()->addMinutes(30));

        return response()->json([
            'token' => $token,
            'expires_in_minutes' => 30,
            'summary' => $analysis['summary'],
            'recognised_columns' => $analysis['recognised_columns'],
            'unrecognised_columns' => $analysis['unrecognised_columns'],
            'rows' => $analysis['rows'],
        ]);
    }

    /**
     * Commit a previewed file. All the valid rows, or none of them.
     */
    public function importCommit(Request $request, StudentImportService $importer)
    {
        $admin = $request->user();
        $schoolId = $admin->userProfile ? $admin->userProfile->school_id : null;

        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'session_id' => [
                'nullable',
                Rule::exists('academic_sessions', 'id')->where('school_id', $schoolId),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $cacheKey = "student-import:{$schoolId}:{$request->token}";
        $payload = Cache::get($cacheKey);

        if (! $payload) {
            return response()->json([
                'message' => 'That preview has expired or was already used. Upload the file again.',
            ], 410);
        }

        // An import session is single-use: a double-tapped Confirm button must
        // not create the intake twice.
        Cache::forget($cacheKey);

        $sessionId = $request->input('session_id')
            ?: AcademicSession::where('school_id', $schoolId)->where('is_current', true)->value('id');

        $result = $importer->commit($payload['rows'], $schoolId, $sessionId, $admin->id);

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $admin->id,
            'action' => 'students.imported',
            // The school, not a student: a batch has no single subject, and
            // `auditable_id` is NOT NULL.
            'auditable_type' => School::class,
            'auditable_id' => $schoolId,
            'new_values' => [
                'created' => $result['created'],
                'class_id' => $payload['class_id'],
                'arm_id' => $payload['arm_id'],
                'session_id' => $sessionId,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => $result['created'] . ' student(s) imported. They have no password yet — '
                . 'tell them to use "Forgot password" on the sign-in screen.',
            'created' => $result['created'],
            'student_ids' => $result['student_ids'],
        ]);
    }

    /**
     * The original one-shot import.
     *
     * Kept because installed mobile apps call it, and kept to its old response
     * shape for the same reason. It now runs through the same parser as the
     * preview flow, so it inherits header-driven columns and in-file duplicate
     * detection; what it does not inherit is the requirement to place a
     * student in a class, because files written for this endpoint have no
     * class column and rejecting all of them would be the breaking change the
     * versioning rule exists to prevent.
     */
    public function bulkImport(Request $request, StudentImportService $importer)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt,xlsx|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = $request->user();
        $schoolId = $admin->userProfile ? $admin->userProfile->school_id : null;

        $analysis = $importer->analyse($request->file('file'), $schoolId, [
            'require_class' => false,
        ]);

        if (isset($analysis['fatal'])) {
            return response()->json([
                'message' => $analysis['fatal'],
                'successful_count' => 0,
                'errors' => [$analysis['fatal']],
            ], 422);
        }

        $sessionId = AcademicSession::where('school_id', $schoolId)
            ->where('is_current', true)
            ->value('id');

        $result = $importer->commit($analysis['rows'], $schoolId, $sessionId, $admin->id);

        $errors = [];

        foreach ($analysis['rows'] as $row) {
            if ($row['status'] === 'error') {
                $errors[] = "Row {$row['line']}: " . implode(' ', $row['errors']);
            }
        }

        return response()->json([
            'message' => "Import complete. {$result['created']} students imported successfully. "
                . 'They have no password yet — tell them to use "Forgot password" on the sign-in screen.',
            'successful_count' => $result['created'],
            'errors' => $errors,
        ]);
    }
}
