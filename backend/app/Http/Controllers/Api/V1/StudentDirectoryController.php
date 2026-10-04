<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentClassHistory;
use App\Models\StudentDirectorySetting;
use App\Models\StudentEnrollment;
use App\Models\StudentRecordChange;
use App\Models\StudentSavedView;
use App\Services\StudentDirectoryService;
use App\Services\StudentEnrolmentService;
use App\Support\TeacherScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The View Students page (TASK: View Students page instructions).
 *
 * Search, filters, sorting and paging all run here. Scope and field
 * permissions are enforced here for the list, a direct lookup, an export and
 * a bulk action alike — hiding a button is not enough (§2). There is no
 * delete: status changes and archiving only (§3 rule 4).
 */
class StudentDirectoryController extends Controller
{
    public function __construct(private StudentDirectoryService $directory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $filters = $this->validated($request);
        $user = $request->user();
        $perm = $this->directory->permissions($user);
        $sessionId = $this->directory->sessionId($schoolId, $filters['session_id'] ?? null);
        $tab = $filters['tab'] ?? 'active';

        if ($tab === 'pending') {
            return $this->pending($schoolId, $perm, $sessionId);
        }

        $base = $this->directory->base($schoolId, $sessionId, $user);
        $query = $this->directory->filter(clone $base, $filters, $user, $schoolId, $sessionId);
        $query = $this->directory->sort($query, $filters['sort'] ?? 'name', $filters['dir'] ?? 'asc');

        $page = $query->paginate((int) ($filters['per_page'] ?? 25));
        $rows = $this->directory->decorate($page->items(), $user, $schoolId, $sessionId);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'session_id' => $sessionId,
                'scope' => $perm['scope'],
                'scope_note' => $perm['scope'] === 'own_classes' ? 'Showing students in your classes.' : null,
            ],
            'cards' => $this->cards($base, $user, $schoolId, $sessionId),
            'chips' => $this->chips($base, $user, $schoolId, $sessionId),
            'permissions' => $perm,
        ]);
    }

    /** Clicking a card applies exactly the filter its count came from (§4.2). */
    private function cards($base, $user, int $schoolId, ?int $sessionId): array
    {
        $count = fn (array $f) => $this->directory->filter(clone $base, $f, $user, $schoolId, $sessionId)->count();
        $settings = StudentDirectorySetting::for($schoolId);

        $cards = [
            ['key' => 'total', 'label' => 'Total students', 'count' => $count(['tab' => 'all']), 'filter' => ['tab' => 'all']],
            ['key' => 'active', 'label' => 'Active', 'count' => $count(['tab' => 'active']), 'filter' => ['tab' => 'active']],
            ['key' => 'new', 'label' => 'New admissions', 'count' => $count(['tab' => 'all', 'admission_type' => 'new']), 'filter' => ['tab' => 'all', 'admission_type' => 'new']],
            ['key' => 'left', 'label' => 'Transferred or withdrawn', 'count' => $count(['tab' => 'transferred']) + $count(['tab' => 'withdrawn']), 'filter' => ['tab' => 'transferred']],
        ];

        if ($settings->show_gender_counts) {
            $cards[] = ['key' => 'boys', 'label' => 'Boys', 'count' => $count(['tab' => 'active', 'gender' => 'male']), 'filter' => ['tab' => 'active', 'gender' => 'male']];
            $cards[] = ['key' => 'girls', 'label' => 'Girls', 'count' => $count(['tab' => 'active', 'gender' => 'female']), 'filter' => ['tab' => 'active', 'gender' => 'female']];
        }

        return $cards;
    }

    /** Needs-attention chips, only those relevant to the role and non-zero (§4.2). */
    private function chips($base, $user, int $schoolId, ?int $sessionId): array
    {
        $perm = $this->directory->permissions($user);

        if (! $perm['see_completeness']) {
            return [];
        }

        $count = fn (array $f) => $this->directory->filter(clone $base, $f, $user, $schoolId, $sessionId)->count();

        $chips = [
            ['key' => 'incomplete', 'label' => 'Incomplete records', 'count' => $count(['tab' => 'active', 'record_status' => 'incomplete']), 'filter' => ['tab' => 'active', 'record_status' => 'incomplete']],
            ['key' => 'pending', 'label' => 'Pending admissions', 'count' => $this->directory->pendingCount($schoolId), 'filter' => ['tab' => 'pending']],
            ['key' => 'transfer', 'label' => 'Awaiting transfer approval', 'count' => DB::table('transfers')->where('school_id', $schoolId)->where('direction', 'outgoing')->where('status', 'pending_approval')->count(), 'link' => '/transfers'],
            ['key' => 'no_class', 'label' => 'No class assigned', 'count' => $count(['tab' => 'active', 'no_class' => true]), 'filter' => ['tab' => 'active', 'no_class' => true]],
            ['key' => 'review', 'label' => 'Requires review', 'count' => $count(['tab' => 'active', 'record_status' => 'requires_review']), 'filter' => ['tab' => 'active', 'record_status' => 'requires_review']],
        ];

        return array_values(array_filter($chips, fn ($c) => $c['count'] > 0));
    }

    /** Accepted applicants not yet enrolled. */
    private function pending(int $schoolId, array $perm, ?int $sessionId): JsonResponse
    {
        $rows = \Illuminate\Support\Facades\Schema::hasTable('admission_applications')
            ? DB::table('admission_applications')->where('school_id', $schoolId)->where('status', 'accepted')->whereNull('student_id')->orderBy('id')->limit(200)->get()
                ->map(fn ($a) => ['id' => null, 'application_id' => $a->id, 'name' => $a->applicant_name ?? null, 'display_name' => $a->applicant_name ?? null, 'admission_number' => null, 'class' => null, 'status' => 'pending', 'reference' => $a->reference ?? null])
            : collect();

        return response()->json(['data' => $rows, 'meta' => ['total' => $rows->count(), 'current_page' => 1, 'last_page' => 1, 'per_page' => 200, 'session_id' => $sessionId, 'scope' => $perm['scope']], 'cards' => [], 'chips' => [], 'permissions' => $perm]);
    }

    public function quickView(Request $request, int $id): JsonResponse
    {
        $student = $this->inScope($request, $id);
        $schoolId = $this->schoolId();
        $sessionId = $this->directory->sessionId($schoolId, $request->integer('session_id') ?: null);

        $row = $this->directory->filter($this->directory->base($schoolId, $sessionId, $request->user())->where('s.id', $student->id), ['tab' => 'all'], $request->user(), $schoolId, $sessionId)->first();
        $decorated = $row ? $this->directory->decorate([$row], $request->user(), $schoolId, $sessionId)[0] : null;

        return response()->json([
            'student' => $decorated,
            'links' => [
                'profile' => "/students?id={$student->id}",
                'progress' => "/progress?student_id={$student->id}",
                'academic_history' => "/academic-history?student_id={$student->id}",
            ],
        ]);
    }

    /** Field history: class, arm, status, date of birth, name — who, when, why. */
    public function changes(Request $request, int $id): JsonResponse
    {
        $student = $this->inScope($request, $id);
        abort_unless($this->directory->permissions($request->user())['can_edit'], 403);

        return response()->json([
            'data' => StudentRecordChange::where('student_id', $student->id)->orderByDesc('id')->limit(200)->get()
                ->map(fn ($c) => $c->toArray() + ['changed_by_name' => $c->changed_by ? \App\Models\User::find($c->changed_by)?->name : null]),
        ]);
    }

    // ==================================================================
    // Changes with reasons (§7)
    // ==================================================================

    public function changeStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:active,suspended,withdrawn',
            'reason' => 'required|string|min:3|max:500',
        ]);

        $result = $this->applyStatus($this->inScope($request, $id), $data['status'], $data['reason'], $request);

        return response()->json($result, $result['ok'] ? 200 : 409);
    }

    public function moveClass(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'arm_id' => ['nullable', Rule::exists('arms', 'id')->where('school_id', $schoolId)->where('class_id', $request->input('class_id'))],
            'reason' => 'required|string|min:3|max:500',
        ]);

        $result = $this->applyMove($this->inScope($request, $id), (int) $data['class_id'], $data['arm_id'] ?? null, $data['reason'], $request);

        return response()->json($result, $result['ok'] ? 200 : 409);
    }

    /** What a bulk action will touch, before anyone confirms it (§7.2). */
    public function bulkPreview(Request $request): JsonResponse
    {
        $data = $this->bulkRequest($request);
        $students = $this->bulkStudents($request, $data['student_ids']);

        $destination = isset($data['class_id']) ? DB::table('classes')->where('id', $data['class_id'])->value('name') : null;

        return response()->json([
            'action' => $data['action'],
            'count' => $students->count(),
            'not_found' => array_values(array_diff($data['student_ids'], $students->pluck('id')->all())),
            'destination' => $destination,
            'students' => $students->map(fn ($s) => ['id' => $s->id, 'name' => $s->user?->name, 'admission_number' => $s->admission_number, 'class' => $s->currentClass?->name, 'status' => $s->status]),
        ]);
    }

    /** Every record reports success or failure; nothing is dropped silently (§7.2). */
    public function bulk(Request $request): JsonResponse
    {
        $data = $this->bulkRequest($request);
        $request->validate(['confirm' => 'accepted']);

        $students = $this->bulkStudents($request, $data['student_ids'])->keyBy('id');
        $results = [];

        foreach ($data['student_ids'] as $id) {
            $student = $students->get($id);

            if (! $student) {
                $results[] = ['student_id' => $id, 'ok' => false, 'message' => 'Not a student you can change.'];

                continue;
            }

            $outcome = match ($data['action']) {
                'move_class' => $this->applyMove($student, (int) $data['class_id'], $data['arm_id'] ?? null, $data['reason'], $request),
                'change_status' => $this->applyStatus($student, $data['status'], $data['reason'], $request),
            };

            $results[] = ['student_id' => $id, 'name' => $student->user?->name] + $outcome;
        }

        AuditLog::create([
            'school_id' => $this->schoolId(),
            'user_id' => $request->user()->id,
            'action' => 'students.bulk_'.$data['action'],
            'auditable_type' => Student::class,
            'auditable_id' => $data['student_ids'][0],
            'new_values' => ['count' => count($data['student_ids']), 'succeeded' => count(array_filter($results, fn ($r) => $r['ok'])), 'reason' => $data['reason']] + array_intersect_key($data, array_flip(['class_id', 'arm_id', 'status'])),
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'succeeded' => count(array_filter($results, fn ($r) => $r['ok'])),
            'failed' => count(array_filter($results, fn ($r) => ! $r['ok'])),
            'results' => $results,
        ]);
    }

    private function bulkRequest(Request $request): array
    {
        abort_unless($this->directory->permissions($request->user())['can_bulk'], 403, 'Bulk changes are for the school admin.');
        $schoolId = $this->schoolId();

        return $request->validate([
            'action' => 'required|in:move_class,change_status',
            'student_ids' => 'required|array|min:1|max:500',
            'student_ids.*' => 'integer',
            'class_id' => ['required_if:action,move_class', 'nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'arm_id' => ['nullable', Rule::exists('arms', 'id')->where('school_id', $schoolId)],
            'status' => 'required_if:action,change_status|nullable|in:active,suspended,withdrawn',
            'reason' => 'required|string|min:3|max:500',
        ]);
    }

    private function bulkStudents(Request $request, array $ids)
    {
        return Student::where('school_id', $this->schoolId())->whereIn('id', $ids)->with('user:id,name', 'currentClass:id,name')->get();
    }

    /** Idempotent: asking for the status a student already has is a success that changes nothing. */
    private function applyStatus(Student $student, string $status, string $reason, Request $request): array
    {
        if (in_array($student->status, ['transferred', 'graduated'], true)) {
            return ['ok' => false, 'message' => "A {$student->status} student's record is a read-only archive."];
        }

        if ($student->status === $status) {
            return ['ok' => true, 'message' => 'Already '.$status.'.'];
        }

        DB::transaction(function () use ($student, $status, $reason, $request) {
            StudentRecordChange::because($reason, fn () => $student->update(['status' => $status]));

            if ($status === 'withdrawn') {
                StudentEnrollment::where('student_id', $student->id)->where('status', 'active')->update([
                    'status' => 'closed', 'outcome' => 'withdrawn', 'closed_on' => now()->toDateString(), 'outcome_remarks' => $reason, 'recorded_by' => $request->user()->id,
                ]);
            }

            AuditLog::create([
                'school_id' => $student->school_id, 'user_id' => $request->user()->id, 'action' => 'student.status_changed',
                'auditable_type' => Student::class, 'auditable_id' => $student->id,
                'new_values' => ['status' => $status, 'reason' => $reason], 'ip_address' => $request->ip(),
            ]);
        });

        return ['ok' => true, 'message' => "Now {$status}."];
    }

    private function applyMove(Student $student, int $classId, ?int $armId, string $reason, Request $request): array
    {
        if ($student->status !== 'active') {
            return ['ok' => false, 'message' => 'Only an active student can change class.'];
        }

        if ((int) $student->class_id === $classId && (int) $student->arm_id === (int) $armId) {
            return ['ok' => true, 'message' => 'Already in that class.'];
        }

        DB::transaction(function () use ($student, $classId, $armId, $reason, $request) {
            $fromClass = $student->class_id;
            $fromArm = $student->arm_id;

            StudentRecordChange::because($reason, fn () => $student->update(['class_id' => $classId, 'arm_id' => $armId]));

            // The enrolment for this session follows; earlier sessions are untouched.
            StudentEnrollment::where('student_id', $student->id)->where('status', 'active')->update(['class_id' => $classId, 'arm_id' => $armId]);

            StudentClassHistory::create([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'from_class_id' => $fromClass,
                'from_arm_id' => $fromArm,
                'to_class_id' => $classId,
                'to_arm_id' => $armId,
                'session_id' => TeacherScope::currentSessionId((int) $student->school_id),
                // The column's vocabulary: a move between classes or arms inside
                // the school is a "transfer" in it.
                'action' => 'transfer',
                'remarks' => $reason,
                'performed_by' => $request->user()->id,
            ]);
        });

        return ['ok' => true, 'message' => 'Moved.'];
    }

    // ==================================================================
    // Export and printing (§8.4, §8.5)
    // ==================================================================

    /**
     * A separate permission from viewing, field-limited by role, logged, and
     * rate-limited at the route.
     */
    public function export(Request $request)
    {
        $schoolId = $this->schoolId();
        $user = $request->user();
        $perm = $this->directory->permissions($user);

        abort_unless($perm['can_export'], 403, 'Your role cannot export student lists.');

        $filters = $this->validated($request);
        $request->validate([
            'fields' => 'nullable|array',
            'fields.*' => ['string', Rule::in($perm['export_fields'])],
            'student_ids' => 'nullable|array|max:5000',
            'student_ids.*' => 'integer',
        ]);

        $fields = $request->input('fields') ?: array_slice($perm['export_fields'], 0, 5);
        $sessionId = $this->directory->sessionId($schoolId, $filters['session_id'] ?? null);

        // A class teacher exports their own form class only.
        if ($perm['role'] === 'teacher' && ! in_array($user->userProfile?->role, ['school_admin'], true)) {
            $own = $this->directory->formClassIds($user);
            abort_unless(! empty($filters['class_id']) && in_array((int) $filters['class_id'], $own, true), 403, 'You can export the class list for your own class.');
        }

        $query = $this->directory->filter($this->directory->base($schoolId, $sessionId, $user), $filters, $user, $schoolId, $sessionId);

        if ($request->filled('student_ids')) {
            $query->whereIn('d.id', $request->input('student_ids'));
        }

        $total = (clone $query)->count();

        if ($total > 5000) {
            return response()->json(['message' => "That is {$total} students. Narrow the filters to 5,000 or fewer, or use the full school data export."], 422);
        }

        $rows = $this->directory->decorate($this->directory->sort($query, $filters['sort'] ?? 'class', 'asc')->get()->all(), $user, $schoolId, $sessionId);

        AuditLog::create([
            'school_id' => $schoolId, 'user_id' => $user->id, 'action' => 'students.exported',
            'auditable_type' => School::class, 'auditable_id' => $schoolId,
            'new_values' => ['rows' => count($rows), 'fields' => $fields, 'filters' => $filters],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        $labels = [
            'admission_number' => 'Admission No.', 'name' => 'Name', 'gender' => 'Gender', 'class' => 'Class', 'arm' => 'Arm',
            'status' => 'Status', 'admission_date' => 'Admission date', 'date_of_birth' => 'Date of birth',
            'guardian_name' => 'Guardian', 'guardian_phone' => 'Guardian phone',
        ];

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn ($f) => $labels[$f] ?? $f, $fields));

        foreach ($rows as $row) {
            fputcsv($out, array_map(function ($f) use ($row) {
                $v = (string) ($row[$f === 'name' ? 'display_name' : $f] ?? '');

                // A leading = + - @ would run as a formula in Excel.
                return $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v;
            }, $fields));
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="students-'.now()->format('Ymd-His').'.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Class list or attendance register for one class, as a PDF to print. */
    public function printList(Request $request)
    {
        $schoolId = $this->schoolId();
        $user = $request->user();
        $request->validate([
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'kind' => 'required|in:class_list,register',
            'session_id' => 'nullable|integer',
        ]);

        $perm = $this->directory->permissions($user);
        abort_unless($perm['can_export'] || $perm['scope'] === 'school', 403);

        $sessionId = $this->directory->sessionId($schoolId, $request->integer('session_id') ?: null);
        $rows = $this->directory->decorate(
            $this->directory->sort($this->directory->filter($this->directory->base($schoolId, $sessionId, $user), ['tab' => 'active', 'class_id' => $request->integer('class_id')], $user, $schoolId, $sessionId), 'name', 'asc')->get()->all(),
            $user, $schoolId, $sessionId
        );

        abort_if($rows === [] && $perm['scope'] === 'own_classes', 403);

        $class = DB::table('classes')->where('id', $request->integer('class_id'))->value('name');
        $session = AcademicSession::find($sessionId)?->name;
        $school = School::find($schoolId)?->name;
        $register = $request->input('kind') === 'register';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $days = $register ? implode('', array_map(fn ($d) => "<th class=\"d\">{$d}</th>", range(1, 20))) : '';
        $body = '';
        foreach ($rows as $i => $r) {
            $cells = $register ? str_repeat('<td class="d"></td>', 20) : '<td>'.$e($r['gender']).'</td><td>'.$e(trim(($r['class'] ?? '').' '.($r['arm'] ?? ''))).'</td>';
            $body .= '<tr><td>'.($i + 1).'</td><td>'.$e($r['admission_number']).'</td><td>'.$e($r['display_name']).'</td>'.$cells.'</tr>';
        }

        $head = $register ? $days : '<th>Gender</th><th>Class</th>';
        $title = $register ? 'Attendance register' : 'Class list';

        $html = "<!doctype html><html><head><meta charset=\"utf-8\"><style>
@page { margin: 12mm; } body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
table { width: 100%; border-collapse: collapse; } thead { display: table-header-group; }
th, td { border: 0.5px solid #555; padding: 3px; } th { background: #eee; } .d { width: 14px; }
</style></head><body><h2>{$e($school)}</h2><p>{$title} · {$e($class)} · {$e($session)} · {$e(count($rows))} students</p>
<table><thead><tr><th>#</th><th>Adm. No.</th><th>Name</th>{$head}</tr></thead><tbody>{$body}</tbody></table></body></html>";

        AuditLog::create(['school_id' => $schoolId, 'user_id' => $user->id, 'action' => 'students.printed_'.$request->input('kind'), 'auditable_type' => School::class, 'auditable_id' => $schoolId, 'new_values' => ['class_id' => $request->integer('class_id'), 'rows' => count($rows)]]);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', $register ? 'landscape' : 'portrait')
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false])->output();

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.str_replace(' ', '-', strtolower($title.'-'.$class)).'.pdf"']);
    }

    // ==================================================================
    // Duplicate check, settings, saved views
    // ==================================================================

    /** Before saving a new student: likely matches, for a person to judge. Nothing merges. */
    public function duplicateCheck(Request $request): JsonResponse
    {
        abort_unless($this->directory->permissions($request->user())['can_edit'], 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'date_of_birth' => 'nullable|date',
            'guardian_phone' => 'nullable|string|max:20',
            'admission_number' => 'nullable|string|max:50',
        ]);

        $matches = app(StudentEnrolmentService::class)->possibleDuplicates($this->schoolId(), $data);

        return response()->json(['possible_duplicates' => $matches, 'message' => $matches ? 'These existing students may be the same child. Check before saving; nothing is merged automatically.' : null]);
    }

    /** The school's sessions, for the list's session selector (§4.1). */
    public function sessions(): JsonResponse
    {
        $schoolId = $this->schoolId();
        $current = TeacherScope::currentSessionId($schoolId);

        return response()->json([
            'data' => AcademicSession::where('school_id', $schoolId)->orderByDesc('start_date')->get(['id', 'name', 'start_date', 'end_date'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'is_current' => $s->id === $current]),
        ]);
    }

    public function settings(): JsonResponse
    {
        $s = StudentDirectorySetting::for($this->schoolId());

        return response()->json($s->toArray() + ['mandatory_fields' => $s->mandatory(), 'available_fields' => StudentDirectorySetting::FIELDS]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mandatory_fields' => 'sometimes|array',
            'mandatory_fields.*' => ['string', Rule::in(array_keys(StudentDirectorySetting::FIELDS))],
            'name_order' => 'sometimes|in:given_first,surname_first',
            'subject_teachers_see_guardian_phone' => 'sometimes|boolean',
            'show_gender_counts' => 'sometimes|boolean',
        ]);

        $s = StudentDirectorySetting::withoutGlobalScopes()->firstOrNew(['school_id' => $this->schoolId()]);
        $s->fill($data)->save();

        AuditLog::create(['school_id' => $this->schoolId(), 'user_id' => $request->user()->id, 'action' => 'students.directory_settings', 'auditable_type' => StudentDirectorySetting::class, 'auditable_id' => $s->id, 'new_values' => $data]);

        return $this->settings();
    }

    public function savedViews(Request $request): JsonResponse
    {
        return response()->json(['data' => StudentSavedView::where('user_id', $request->user()->id)->orderBy('name')->get()]);
    }

    public function saveView(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'filters' => 'required|array', 'columns' => 'nullable|array']);

        $view = StudentSavedView::updateOrCreate(
            ['school_id' => $this->schoolId(), 'user_id' => $request->user()->id, 'name' => $data['name']],
            ['filters' => $data['filters'], 'columns' => $data['columns'] ?? null]
        );

        return response()->json(['view' => $view], 201);
    }

    public function deleteView(Request $request, int $id): JsonResponse
    {
        StudentSavedView::where('user_id', $request->user()->id)->whereKey($id)->delete();

        return response()->json(['message' => 'Removed.']);
    }

    // ==================================================================

    private function validated(Request $request): array
    {
        $schoolId = $this->schoolId();

        return $request->validate([
            'session_id' => 'nullable|integer',
            'tab' => ['nullable', Rule::in(StudentDirectoryService::TABS)],
            'search' => 'nullable|string|max:100',
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'arm_id' => ['nullable', Rule::exists('arms', 'id')->where('school_id', $schoolId)],
            'gender' => 'nullable|in:male,female,other',
            'admission_type' => 'nullable|in:new,returning,transfer_in',
            'admitted_from' => 'nullable|date',
            'admitted_to' => 'nullable|date',
            'fee_status' => 'nullable|in:paid,partial,outstanding',
            'record_status' => 'nullable|in:complete,incomplete,requires_review',
            'no_class' => 'nullable|boolean',
            'sort' => ['nullable', Rule::in(StudentDirectoryService::SORTS)],
            'dir' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|in:10,25,50,100',
        ]);
    }

    /** A student this user may see, or 404 — the same answer as a student who does not exist. */
    private function inScope(Request $request, int $id): Student
    {
        $student = Student::where('school_id', $this->schoolId())->with('user:id,name')->findOrFail($id);
        $user = $request->user();

        if ($this->directory->permissions($user)['scope'] === 'own_classes' && ! TeacherScope::teaches($user, $student)) {
            abort(404);
        }

        return $student;
    }
}
