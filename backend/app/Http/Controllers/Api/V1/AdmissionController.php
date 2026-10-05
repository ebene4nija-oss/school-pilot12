<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Models\AdmissionApplication;
use App\Models\AdmissionAssessment;
use App\Models\AdmissionDocument;
use App\Models\AdmissionSetting;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\DuplicateApplicant;
use App\Services\Sections\HeadResolver;
use App\Support\InAppNotifier;
use App\Support\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admissions (decision D4; TASK 22). The school admin sees every section; a
 * section head sees and decides their own section's applications. Owners keep
 * consolidated oversight through the admin role.
 */
class AdmissionController extends Controller
{
    public function __construct(private AdmissionService $admissions, private HeadResolver $heads)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => 'nullable|string', 'section' => ['nullable', Rule::in(array_keys(Sections::SECTIONS))], 'search' => 'nullable|string|max:100']);
        $sections = $this->sections($request->user());

        $query = AdmissionApplication::where('school_id', $this->schoolId())
            ->when($sections !== null, fn ($q) => $q->whereIn('section', $sections))
            ->when($request->filled('section'), fn ($q) => $q->where('section', $request->input('section')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('applicant_name', 'like', '%'.$request->input('search').'%')->orWhere('reference', 'like', '%'.$request->input('search').'%')));

        $counts = AdmissionApplication::where('school_id', $this->schoolId())
            ->when($sections !== null, fn ($q) => $q->whereIn('section', $sections))
            ->selectRaw('section, status, COUNT(*) as n')->groupBy('section', 'status')->get()
            ->groupBy('section')->map(fn ($g) => $g->pluck('n', 'status'));

        $page = $query->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn ($a) => $this->row($a)),
            'meta' => ['total' => $page->total(), 'last_page' => $page->lastPage()],
            'counts' => $counts,
            'statuses' => AdmissionApplication::STATUSES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateApplication($request);
        $this->assertSection($request->user(), $data['section']);

        return $this->attempt(fn () => ['application' => $this->row($this->admissions->create($this->schoolId(), $data, $request->user()))], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $app = $this->find($request, $id);

        return response()->json($this->row($app) + [
            'guardians' => $app->guardians,
            'documents' => $app->documents()->get(['id', 'type', 'status', 'note'])->map(fn ($d) => $d->toArray() + ['label' => AdmissionSetting::DOCUMENTS[$d->type] ?? $d->type]),
            'assessments' => $app->assessments()->get(),
            'events' => $app->events()->with('user:id,name')->get()->map(fn ($e) => ['action' => $e->action, 'from' => $e->from_status, 'to' => $e->to_status, 'by' => $e->user?->name, 'note' => $e->note, 'at' => $e->created_at?->toIso8601String()]),
            'possible_duplicates' => in_array($app->status, ['accepted', 'offered'], true)
                ? app(\App\Services\StudentEnrolmentService::class)->possibleDuplicates($app->school_id, ['name' => (string) $app->applicant_name, 'date_of_birth' => $app->date_of_birth?->toDateString(), 'guardian_phone' => $app->guardians[0]['phone'] ?? null])
                : [],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $app = $this->find($request, $id);

        if (! in_array($app->status, ['submitted', 'under_review', 'assessment', 'waitlisted'], true)) {
            return response()->json(['message' => 'Details are fixed once a decision is made.'], 409);
        }

        $data = $request->validate([
            'applicant_name' => 'sometimes|string|max:255',
            'date_of_birth' => 'sometimes|nullable|date|before:today',
            'gender' => 'sometimes|nullable|in:male,female,other',
            'previous_school' => 'sometimes|nullable|string|max:255',
            'previous_class' => 'sometimes|nullable|string|max:100',
            'intended_class_id' => ['sometimes', 'nullable', Rule::exists('classes', 'id')->where('school_id', $this->schoolId())],
            'guardians' => 'sometimes|array|min:1|max:2',
            'guardians.*.name' => 'required_with:guardians|string|max:255',
            'guardians.*.phone' => 'required_with:guardians|string|max:20',
            'guardians.*.email' => 'nullable|email',
            'guardians.*.relationship' => 'nullable|string|max:20',
        ]);

        $app->update($data);
        $this->admissions->event($app, 'edited', $app->status, $app->status, $request->user());

        return response()->json(['application' => $this->row($app->fresh())]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $app = $this->find($request, $id);
        abort_unless($app->status === 'submitted', 409, 'Already under review.');

        return response()->json(['application' => $this->row($this->admissions->transition($app, 'under_review', $request->user(), 'review_started'))]);
    }

    public function document(Request $request, int $id): JsonResponse
    {
        $app = $this->find($request, $id);
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(AdmissionSetting::DOCUMENTS))], 'status' => 'required|in:pending,received,verified,rejected', 'note' => 'nullable|string|max:255']);

        AdmissionDocument::updateOrCreate(['application_id' => $app->id, 'type' => $data['type']], ['school_id' => $app->school_id, 'status' => $data['status'], 'note' => $data['note'] ?? null, 'updated_by' => $request->user()->id]);
        $this->admissions->event($app, 'document_'.$data['status'], $app->status, $app->status, $request->user(), $data['type']);

        return response()->json(['message' => 'Saved.']);
    }

    /** Schedule an entrance test or interview, or record its result (baseline phase 3). */
    public function assessment(Request $request, int $id): JsonResponse
    {
        $app = $this->find($request, $id);
        $data = $request->validate([
            'kind' => 'required|in:entrance_test,interview',
            'scheduled_at' => 'nullable|date',
            'location' => 'nullable|string|max:255',
            'cbt_exam_id' => ['nullable', Rule::exists('cbt_exams', 'id')->where('school_id', $this->schoolId())],
        ]);

        $row = AdmissionAssessment::create($data + ['school_id' => $app->school_id, 'application_id' => $app->id, 'recorded_by' => $request->user()->id]);

        if (in_array($app->status, ['submitted', 'under_review'], true)) {
            $this->admissions->transition($app, 'assessment', $request->user(), $data['kind'].'_scheduled');
        }

        return response()->json(['assessment' => $row], 201);
    }

    public function recordAssessment(Request $request, int $assessmentId): JsonResponse
    {
        $row = AdmissionAssessment::where('school_id', $this->schoolId())->findOrFail($assessmentId);
        $this->find($request, $row->application_id);

        $data = $request->validate(['score' => 'nullable|numeric|min:0', 'max_score' => 'nullable|numeric|min:1|gte:score', 'notes' => 'nullable|string|max:2000']);
        $row->update($data + ['recorded_by' => $request->user()->id]);

        return response()->json(['assessment' => $row->fresh()]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $app = $this->find($request, $id);
        $data = $request->validate([
            'decision' => 'required|in:offer,reject,waitlist',
            'reason' => 'nullable|string|max:1000',
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $this->schoolId())],
        ]);

        return $this->attempt(function () use ($app, $request, $data) {
            $app = $this->admissions->decide($app, $request->user(), $data['decision'], $data['reason'] ?? null, $data['class_id'] ?? $app->intended_class_id);
            AuditLog::create(['school_id' => $app->school_id, 'user_id' => $request->user()->id, 'action' => 'admission.'.$data['decision'], 'auditable_type' => AdmissionApplication::class, 'auditable_id' => $app->id, 'new_values' => $data]);

            return ['application' => $this->row($app)];
        });
    }

    /** The parent's answer to the offer, recorded by staff. */
    public function respond(Request $request, int $id): JsonResponse
    {
        $app = $this->find($request, $id);
        $request->validate(['accepted' => 'required|boolean']);

        return $this->attempt(fn () => ['application' => $this->row($this->admissions->respond($app, $request->user(), $request->boolean('accepted')))]);
    }

    public function enrol(Request $request, int $id): JsonResponse
    {
        $app = $this->find($request, $id);
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'arm_id' => ['nullable', Rule::exists('arms', 'id')->where('school_id', $schoolId)],
            'admission_number' => ['nullable', 'string', 'max:50', Rule::unique('students', 'admission_number')->where('school_id', $schoolId)],
            'confirm_not_duplicate' => 'boolean',
        ]);

        try {
            $result = $this->admissions->enrol($app, $request->user(), $data, $request->boolean('confirm_not_duplicate'));
        } catch (DuplicateApplicant $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'possible_duplicate', 'possible_duplicates' => $e->candidates], 409);
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => "Enrolled as {$result['student']->admission_number}.", 'student_id' => $result['student']->id], 201);
    }

    public function withdraw(Request $request, int $id): JsonResponse
    {
        $app = $this->find($request, $id);
        $request->validate(['reason' => 'required|string|min:3|max:500']);
        abort_unless(in_array($app->status, AdmissionApplication::ACTIVE, true), 409, 'This application is already closed.');

        return response()->json(['application' => $this->row($this->admissions->transition($app, 'withdrawn', $request->user(), 'withdrawn', $request->input('reason')))]);
    }

    public function offerLetter(Request $request, int $id)
    {
        $app = $this->find($request, $id);
        abort_unless(in_array($app->status, ['offered', 'accepted', 'enrolled'], true), 409, 'There is no offer on this application.');

        $school = School::find($app->school_id);
        $class = DB::table('classes')->where('id', $app->offered_class_id)->value('name');
        $note = AdmissionSetting::for($app->school_id)->offer_letter_note;
        $guardian = $app->guardians[0]['name'] ?? 'Parent or guardian';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:20mm}body{font-family:DejaVu Sans,sans-serif;font-size:11px;line-height:1.5}h1{font-size:16px}</style></head><body>'
            .'<h1>'.$e($school?->name).'</h1><p>'.$e($school?->address).'</p><p>'.$e(now()->format('j F Y')).'</p>'
            .'<p>Dear '.$e($guardian).',</p><p><b>Offer of admission: '.$e($app->applicant_name).'</b> (reference '.$e($app->reference).')</p>'
            .'<p>We are pleased to offer '.$e($app->applicant_name).' a place in <b>'.$e($class).'</b>. Please confirm acceptance with the school by <b>'.$e($app->offer_expires_at?->format('j F Y')).'</b>, after which the offer lapses.</p>'
            .($note ? '<p>'.nl2br($e($note)).'</p>' : '')
            .'<p>Yours faithfully,</p><p>______________________<br>For the school</p></body></html>';

        $this->admissions->event($app, 'offer_letter_printed', $app->status, $app->status, $request->user());

        return response(\Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false])->output(), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"offer-{$app->reference}.pdf\"",
        ]);
    }

    public function settings(): JsonResponse
    {
        $s = AdmissionSetting::for($this->schoolId());

        $school = School::find($this->schoolId());

        return response()->json($s->toArray() + [
            'document_types' => AdmissionSetting::DOCUMENTS,
            'sections' => Sections::SECTIONS,
            'required_documents' => $s->required_documents ?: AdmissionSetting::DEFAULT_DOCUMENTS,
            // Offered as starting values for the admissions contacts.
            'school_contacts' => ['phone' => $school?->phone, 'email' => $school?->email],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'public_form_enabled' => 'sometimes|boolean',
            'open_sections' => 'sometimes|array',
            'open_sections.*' => [Rule::in(array_keys(Sections::SECTIONS))],
            'required_documents' => 'sometimes|array',
            'required_documents.*' => 'array',
            'required_documents.*.*' => [Rule::in(array_keys(AdmissionSetting::DOCUMENTS))],
            'retention_days' => 'sometimes|integer|min:30|max:3650',
            'offer_validity_days' => 'sometimes|integer|min:1|max:90',
            'offer_letter_note' => 'sometimes|nullable|string|max:2000',

            // How parents reach the school about admissions (school's choice).
            'admissions_status' => 'sometimes|nullable|in:open,closed',
            'admission_session' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}\/\d{4}$/'],
            'instructions' => 'sometimes|nullable|string|max:1000',
            'closed_message' => 'sometimes|nullable|string|max:500',
            'whatsapp_enabled' => 'sometimes|boolean',
            'whatsapp_number' => 'sometimes|nullable|string|max:20',
            'whatsapp_message' => 'sometimes|nullable|string|max:300',
            'email_enabled' => 'sometimes|boolean',
            'admissions_email' => 'sometimes|nullable|email|max:255',
            'email_subject' => 'sometimes|nullable|string|max:150',
            'phone_enabled' => 'sometimes|boolean',
            'admissions_phone' => 'sometimes|nullable|string|max:20',
        ], [
            'admission_session.regex' => 'Write the session like 2026/2027.',
        ]);

        foreach (['whatsapp_number' => true, 'admissions_phone' => false] as $field => $mobileOnly) {
            if (! empty($data[$field])) {
                $number = \App\Services\SmsService::normaliseNumber($data[$field]);
                $pattern = $mobileOnly ? '/^\+234[789][01]\d{8}$/' : '/^\+234\d{9,10}$/';

                if (! preg_match($pattern, $number)) {
                    return response()->json(['message' => 'Check the phone numbers.', 'errors' => [
                        $field => [$mobileOnly ? 'Enter a Nigerian WhatsApp number, e.g. 0803 123 4567.' : 'Enter a Nigerian phone number, e.g. 0803 123 4567.'],
                    ]], 422);
                }

                $data[$field] = $number;
            }
        }

        $s = AdmissionSetting::withoutGlobalScopes()->firstOrNew(['school_id' => $this->schoolId()]);
        $s->fill($data);

        // Checked on the result, not the request: turning WhatsApp on in one
        // save and adding its number in the next would otherwise slip through.
        $errors = [];
        $methods = ['public_form_enabled' => null, 'whatsapp_enabled' => 'whatsapp_number', 'email_enabled' => 'admissions_email', 'phone_enabled' => 'admissions_phone'];

        foreach ($methods as $switch => $contact) {
            if ($contact && $s->{$switch} && blank($s->{$contact})) {
                $errors[$contact] = ['Add the contact for this method, or turn it off.'];
            }
        }

        if ($s->admissions_status === 'open' && ! collect(array_keys($methods))->contains(fn ($m) => $s->{$m})) {
            $errors['admissions_status'] = ['Turn on at least one way for parents to reach you before opening admissions.'];
        }

        if ($errors !== []) {
            return response()->json(['message' => 'Admissions settings were not saved.', 'errors' => $errors], 422);
        }

        $s->save();
        AuditLog::create(['school_id' => $this->schoolId(), 'user_id' => $request->user()->id, 'action' => 'admission.settings_updated', 'auditable_type' => AdmissionSetting::class, 'auditable_id' => $s->id, 'new_values' => $data]);

        return $this->settings();
    }

    // ==================================================================
    // Public online form (off unless the school turns it on)
    // ==================================================================

    public function publicForm(Request $request): JsonResponse
    {
        $school = $this->publicSchool($request);
        $s = AdmissionSetting::for($school->id);

        return response()->json([
            'school' => $school->name,
            'session' => $s->admission_session,
            'instructions' => $s->instructions,
            'sections' => collect($s->open_sections ?: array_keys(Sections::SECTIONS))->mapWithKeys(fn ($k) => [$k => Sections::SECTIONS[$k] ?? $k]),
            'required_documents' => collect($s->open_sections ?: [])->mapWithKeys(fn ($k) => [$k => array_map(fn ($t) => AdmissionSetting::DOCUMENTS[$t], $s->documentsFor($k))]),
            'privacy' => 'We use these details only to consider this application. If the child is not admitted, the details are removed after '.$s->retention_days.' days.',
        ]);
    }

    public function publicApply(Request $request): JsonResponse
    {
        $school = $this->publicSchool($request);
        $data = $this->validateApplication($request, public: true);

        try {
            $app = $this->admissions->create($school->id, $data, null, 'online');
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $admins = DB::table('user_profiles')->where('school_id', $school->id)->where('role', 'school_admin')->pluck('user_id')->all();
        InAppNotifier::send($school->id, $admins, 'admission', 'New online application', "Application {$app->reference} is waiting for review.");

        return response()->json(['message' => 'Application received. Keep this reference to ask about it.', 'reference' => $app->reference], 201);
    }

    /** Status by reference and guardian phone; the same answer when nothing matches. */
    public function publicStatus(Request $request): JsonResponse
    {
        $school = $this->publicSchool($request);
        $request->validate(['reference' => 'required|string|max:30', 'phone' => 'required|string|max:20']);

        $app = AdmissionApplication::withoutGlobalScopes()->where('school_id', $school->id)->where('reference', strtoupper(trim($request->input('reference'))))->whereNull('purged_at')->first();
        $phone = \App\Services\GuardianDirectory::canonicalPhone($request->input('phone'));
        $match = $app && collect($app->guardians ?? [])->contains(fn ($g) => \App\Services\GuardianDirectory::canonicalPhone($g['phone'] ?? null) === $phone);

        if (! $match) {
            return response()->json(['message' => 'No application matches that reference and phone number.'], 404);
        }

        return response()->json(['reference' => $app->reference, 'status' => AdmissionApplication::STATUSES[$app->status] ?? $app->status, 'offer_expires_at' => $app->status === 'offered' ? $app->offer_expires_at?->toDateString() : null]);
    }

    private function publicSchool(Request $request): School
    {
        $school = $request->attributes->get('tenant_school');
        abort_unless($school && AdmissionSetting::for($school->id)->acceptsOnline(), 404, 'This school is not taking applications online.');

        return $school;
    }

    // ==================================================================

    private function validateApplication(Request $request, bool $public = false): array
    {
        $schoolId = $public ? $request->attributes->get('tenant_school')?->id : $this->schoolId();

        return $request->validate([
            'section' => ['required', Rule::in(array_keys(Sections::SECTIONS))],
            'intended_class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'applicant_name' => 'required|string|min:3|max:255',
            'date_of_birth' => 'required|date|before:today',
            'gender' => 'required|in:male,female,other',
            'previous_school' => 'nullable|string|max:255',
            'previous_class' => 'nullable|string|max:100',
            'guardians' => 'required|array|min:1|max:2',
            'guardians.*.name' => 'required|string|max:255',
            'guardians.*.phone' => ['required', 'string', 'max:20', 'regex:/^\+?[\d\s()-]{7,20}$/'],
            'guardians.*.email' => 'nullable|email|max:255',
            'guardians.*.relationship' => 'nullable|in:father,mother,guardian,parent,other',
        ]);
    }

    /** null = every section; else the sections this head may handle. */
    private function sections(User $user): ?array
    {
        if ($user->userProfile?->role === 'school_admin') {
            return null;
        }

        $levels = $this->heads->levelsHeadedBy($user, $this->schoolId());

        return $levels === null ? null : array_values(array_unique(array_map(fn ($l) => Sections::sectionOf($l), $levels)));
    }

    private function assertSection(User $user, string $section): void
    {
        $allowed = $this->sections($user);
        abort_unless($allowed === null || in_array($section, $allowed, true), 403, 'You handle admissions only for the section you head.');
    }

    private function find(Request $request, int $id): AdmissionApplication
    {
        $app = AdmissionApplication::where('school_id', $this->schoolId())->findOrFail($id);
        $allowed = $this->sections($request->user());
        abort_unless($allowed === null || in_array($app->section, $allowed, true), 404);

        return $app;
    }

    private function row(AdmissionApplication $a): array
    {
        return [
            'id' => $a->id,
            'reference' => $a->reference,
            'section' => $a->section,
            'applicant_name' => $a->applicant_name,
            'date_of_birth' => $a->date_of_birth?->toDateString(),
            'gender' => $a->gender,
            'previous_school' => $a->previous_school,
            'previous_class' => $a->previous_class,
            'intended_class' => $a->intended_class_id ? DB::table('classes')->where('id', $a->intended_class_id)->value('name') : null,
            'offered_class' => $a->offered_class_id ? DB::table('classes')->where('id', $a->offered_class_id)->value('name') : null,
            'offered_class_id' => $a->offered_class_id,
            'status' => $a->status,
            'status_label' => AdmissionApplication::STATUSES[$a->status] ?? $a->status,
            'source' => $a->source,
            'offer_expires_at' => $a->offer_expires_at?->toIso8601String(),
            'student_id' => $a->student_id,
            'purged' => $a->purged_at !== null,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    private function attempt(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return response()->json($fn(), $status);
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
