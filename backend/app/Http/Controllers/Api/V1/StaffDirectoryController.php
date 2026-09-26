<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\GuardianDirectory;
use App\Services\PasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The school's staff: who works here, and adding them one at a time or by CSV.
 *
 * There was no way to add a teacher from the portal. An older bulk endpoint
 * created login accounts from fixed column positions, with no preview and no
 * staff record, so HR screens had nobody to attach leave or employment history
 * to. This makes the account, the profile and the staff record together.
 *
 * Staff sign in by email, so unlike a parent a staff member needs one.
 * School admin only.
 */
class StaffDirectoryController extends Controller
{
    /** Roles a school can give its own staff. */
    public const ROLES = ['school_admin', 'teacher', 'nurse', 'bursar'];

    private const MAX_IMPORT_ROWS = 500;

    public function index(Request $request)
    {
        $schoolId = $this->schoolId();

        $query = User::query()
            ->whereHas('userProfile', fn ($q) => $q->where('school_id', $schoolId)->whereIn('role', self::ROLES))
            ->with('userProfile:id,user_id,role,phone,sees_all_students');

        if ($request->filled('role') && in_array($request->role, self::ROLES, true)) {
            $query->whereHas('userProfile', fn ($q) => $q->where('role', $request->role));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        $users = $query->orderBy('name')->paginate(50);

        $staff = Staff::whereIn('user_id', collect($users->items())->pluck('id'))->get()->keyBy('user_id');

        return response()->json([
            'data' => collect($users->items())->map(fn (User $u) => [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->userProfile?->phone,
                'role' => $u->userProfile?->role,
                // Teachers only: whether they see the whole school or just their classes.
                'sees_all_students' => (bool) $u->userProfile?->sees_all_students,
                'staff_id' => $staff->get($u->id)?->staff_id,
                'designation' => $staff->get($u->id)?->designation,
                'qualification' => $staff->get($u->id)?->qualification,
                'employment_date' => $staff->get($u->id)?->employment_date?->toDateString(),
            ]),
            'counts' => UserProfile::where('school_id', $schoolId)
                ->whereIn('role', self::ROLES)
                ->selectRaw('role, COUNT(*) as total')
                ->groupBy('role')
                ->pluck('total', 'role'),
            'meta' => ['total' => $users->total(), 'current_page' => $users->currentPage(), 'last_page' => $users->lastPage()],
        ]);
    }

    public function store(Request $request, PasswordResetService $resets)
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $validator = Validator::make($request->all(), $this->rules($schoolId));

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = DB::transaction(fn () => $this->create($validator->validated(), $schoolId, $request));

        $delivery = $resets->sendSetupLink($user, School::find($schoolId));

        return response()->json([
            'message' => "{$user->name} added.",
            'user_id' => $user->id,
            'setup_link_sent' => ($delivery['email']['status'] ?? null) === 'sent',
        ], 201);
    }

    /**
     * Add many staff from a CSV.
     *
     * `dry_run=1` checks every row and creates nothing — the preview. Without
     * it the whole file is created, or nothing is: a half-imported staff list
     * is worse than none, because nobody can tell which half.
     */
    public function import(Request $request, PasswordResetService $resets)
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
            'dry_run' => 'nullable|boolean',
        ]);

        $parsed = $this->readCsv($request->file('file')->getRealPath());

        if (isset($parsed['error'])) {
            return response()->json(['message' => $parsed['error']], 422);
        }

        $rows = [];
        $emailsInFile = [];
        $staffIdsInFile = [];

        foreach ($parsed['rows'] as $index => $row) {
            $line = $index + 2; // header is line 1
            $validator = Validator::make($row, $this->rules($schoolId));
            $errors = $validator->errors()->all();

            // Duplicates within the file, which the database check cannot see.
            $email = strtolower((string) ($row['email'] ?? ''));

            if ($email !== '' && isset($emailsInFile[$email])) {
                $errors[] = "Email repeats line {$emailsInFile[$email]}.";
            }

            $emailsInFile[$email] = $emailsInFile[$email] ?? $line;

            if (filled($row['staff_id'] ?? null)) {
                $sid = strtolower($row['staff_id']);

                if (isset($staffIdsInFile[$sid])) {
                    $errors[] = "Staff ID repeats line {$staffIdsInFile[$sid]}.";
                }

                $staffIdsInFile[$sid] = $staffIdsInFile[$sid] ?? $line;
            }

            $rows[] = [
                'line' => $line,
                'status' => $errors === [] ? 'ready' : 'error',
                'errors' => $errors,
                'name' => $row['name'] ?? null,
                'email' => $row['email'] ?? null,
                'role' => $row['role'] ?? null,
                'data' => $row,
            ];
        }

        $errorCount = count(array_filter($rows, fn ($r) => $r['status'] === 'error'));

        $summary = ['total' => count($rows), 'ready' => count($rows) - $errorCount, 'errors' => $errorCount];

        if ($request->boolean('dry_run') || $errorCount > 0) {
            return response()->json([
                'summary' => $summary,
                'created' => 0,
                'rows' => array_map(fn ($r) => array_diff_key($r, ['data' => true]), $rows),
                'message' => $errorCount > 0
                    ? 'Fix the rows marked with errors and upload again. Nothing was created.'
                    : 'Every row is ready. Confirm to add them.',
            ], $errorCount > 0 && ! $request->boolean('dry_run') ? 422 : 200);
        }

        $users = DB::transaction(function () use ($rows, $schoolId, $request) {
            return array_map(
                fn ($r) => $this->create(Validator::make($r['data'], $this->rules($schoolId))->validated(), $schoolId, $request),
                $rows,
            );
        });

        $school = School::find($schoolId);

        foreach ($users as $user) {
            $resets->sendSetupLink($user, $school);
        }

        return response()->json([
            'summary' => $summary,
            'created' => count($users),
            'rows' => [],
            'message' => count($users).' staff added. Each has been sent a link to set a password.',
        ], 201);
    }

    public function template()
    {
        $headers = ['name', 'email', 'phone', 'role', 'staff_id', 'designation', 'qualification', 'employment_date'];
        $example = ['Example Teacher', 'example.teacher@yourschool.com', '08031234567', 'teacher', 'STF/001', 'Mathematics teacher', 'B.Sc. Ed', '2024-09-02'];

        $csv = implode(',', $headers).chr(13).chr(10)
            .implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $example)).chr(13).chr(10);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="staff-import-template.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function rules(int $schoolId): array
    {
        return [
            'name' => 'required|string|max:255',
            // Staff sign in by email, so one is required here.
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[\d\s()-]{7,20}$/'],
            'role' => ['required', Rule::in(self::ROLES)],
            'staff_id' => [
                'nullable', 'string', 'max:50',
                Rule::unique('staff', 'staff_id')->where('school_id', $schoolId)->whereNull('deleted_at'),
            ],
            'designation' => 'nullable|string|max:100',
            'qualification' => 'nullable|string|max:100',
            'employment_date' => 'nullable|date|before_or_equal:today',
        ];
    }

    private function create(array $data, int $schoolId, Request $request): User
    {
        $user = User::create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            // Never shown; the member sets their own from the setup link.
            'password' => bin2hex(random_bytes(32)),
        ]);

        UserProfile::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'role' => $data['role'],
            'phone' => GuardianDirectory::canonicalPhone($data['phone'] ?? null),
        ]);

        Staff::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'staff_id' => $data['staff_id'] ?? null,
            'designation' => $data['designation'] ?? (['nurse' => 'School nurse', 'bursar' => 'Bursar'][$data['role']] ?? null),
            'qualification' => $data['qualification'] ?? null,
            'employment_date' => $data['employment_date'] ?? null,
        ]);

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'staff.created',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'new_values' => ['email' => $user->email, 'role' => $data['role']],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return $user;
    }

    /**
     * Rows keyed by header. Headers are matched loosely — "Staff ID", "staff_id"
     * and "staff id" are one column — and blank lines are skipped.
     *
     * @return array{rows?: array<int, array<string, string>>, error?: string}
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['error' => 'That file could not be opened.'];
        }

        $header = fgetcsv($handle);

        if (! $header) {
            fclose($handle);

            return ['error' => 'The file is empty.'];
        }

        $known = ['name', 'email', 'phone', 'role', 'staff_id', 'designation', 'qualification', 'employment_date'];
        $columns = array_map(function ($h) use ($known) {
            // Strip a UTF-8 byte-order mark, which Excel adds to CSV exports.
            $key = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
            $key = preg_replace('/[^a-z]+/', '_', $key);
            $key = trim($key, '_');

            return in_array($key, $known, true) ? $key : null;
        }, $header);

        foreach (['name', 'email', 'role'] as $required) {
            if (! in_array($required, $columns, true)) {
                fclose($handle);

                return ['error' => "The file needs a \"{$required}\" column. Download the template to see the layout."];
            }
        }

        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $row = [];

            foreach ($columns as $i => $key) {
                if ($key !== null) {
                    $value = trim((string) ($line[$i] ?? ''));
                    $row[$key] = $key === 'role' ? strtolower(str_replace(' ', '_', $value)) : $value;
                }
            }

            // Empty optional cells are absent, not empty strings, so they pass
            // "nullable" rules.
            $rows[] = array_filter($row, fn ($v) => $v !== '');

            if (count($rows) > self::MAX_IMPORT_ROWS) {
                fclose($handle);

                return ['error' => 'At most '.self::MAX_IMPORT_ROWS.' staff per file.'];
            }
        }

        fclose($handle);

        return $rows === [] ? ['error' => 'The file has a header but no staff in it.'] : ['rows' => $rows];
    }
}
