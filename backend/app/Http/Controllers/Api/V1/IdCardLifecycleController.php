<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\IdCardPrintRun;
use App\Models\IdCardSetting;
use App\Models\Staff;
use App\Models\Student;
use App\Services\IdCard\IdCardIssuanceService;
use App\Services\IdCard\IdCardLifecycleService;
use App\Services\IdCard\IdCardPrintService;
use App\Services\IdCard\PhotoPreflightService;
use App\Services\QrCodeService;
use App\Support\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ID card production, issuance, renewal and digital cards (TASK 18), next
 * to the template and print-run endpoints in IdCardController and
 * IdCardTemplateController.
 */
class IdCardLifecycleController extends Controller
{
    public function __construct(private IdCardLifecycleService $lifecycle, private IdCardIssuanceService $issuance)
    {
    }

    public function settings(): JsonResponse
    {
        return response()->json(IdCardSetting::for($this->schoolId()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled_types' => 'sometimes|array|min:1', 'enabled_types.*' => 'in:student,staff',
            'valid_only_when_issued' => 'sometimes|boolean',
            'staff_validity_months' => 'sometimes|nullable|integer|min:1|max:120',
            'expiry_warning_days' => 'sometimes|integer|min:1|max:180',
            'digital_cards_enabled' => 'sometimes|boolean',
            'parents_see_digital_card' => 'sometimes|boolean',
            'log_verifications' => 'sometimes|boolean',
        ]);
        $s = IdCardSetting::updateOrCreate(['school_id' => $this->schoolId()], $data);
        $this->audit($request, 'id_cards.settings_updated', IdCardSetting::class, $s->id, $data);

        return response()->json($s->fresh());
    }

    public function dashboard(Request $request): JsonResponse
    {
        $data = $request->validate(['holder_type' => 'nullable|in:student,staff', 'class_id' => 'nullable|integer']);
        $type = $data['holder_type'] ?? 'student';

        return response()->json([
            'holder_type' => $type,
            'summary' => $this->lifecycle->summary($this->schoolId(), $type, $type === 'student' ? ($data['class_id'] ?? null) : null),
            'awaiting_print_confirmation' => IdCardPrintRun::withoutGlobalScopes()->where('school_id', $this->schoolId())->where('status', 'completed')->where('physical_status', 'not_confirmed')->count(),
            'recent' => DB::table('id_card_status_events as e')->join('id_cards as c', 'c.id', '=', 'e.card_id')->where('e.school_id', $this->schoolId())
                ->orderByDesc('e.id')->limit(15)->get(['e.event', 'e.note', 'e.created_at', 'c.serial', 'c.holder_type']),
        ]);
    }

    /** Search and filter (§19). Names come from the central records. */
    public function cards(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'holder_type' => 'nullable|in:student,staff', 'status' => 'nullable|in:active,revoked,expired,replaced',
            'production_status' => ['nullable', Rule::in(IdCard::PRODUCTION)], 'class_id' => 'nullable|integer', 'q' => 'nullable|string|max:100',
        ]);
        $type = $data['holder_type'] ?? 'student';

        $holders = $type === 'student'
            ? Student::where('school_id', $schoolId)->with('user:id,name', 'currentClass:id,name')
                ->when($data['class_id'] ?? null, fn ($q, $c) => $q->where('class_id', $c))
                ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('admission_number', 'like', "%{$term}%")->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"))))->limit(2000)->get()
            : Staff::where('school_id', $schoolId)->with('user:id,name')
                ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('staff_id', 'like', "%{$term}%")->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"))))->limit(2000)->get();

        $query = IdCard::where('school_id', $schoolId)->where('holder_type', $type)
            ->when($data['production_status'] ?? null, fn ($q, $p) => $q->where('production_status', $p))
            ->when(($data['status'] ?? null) && $data['status'] !== 'expired', fn ($q) => $q->where('status', $data['status']));
        if (! empty($data['q']) && $found = IdCard::where('school_id', $schoolId)->where('serial', $data['q'])->first()) {
            $query->whereKey($found->id);
        } elseif (! empty($data['q']) || ! empty($data['class_id'])) {
            $query->whereIn('holder_id', $holders->pluck('id')->all() ?: [0]);
        }

        $cards = $query->orderByDesc('id')->limit(500)->get();
        if (($data['status'] ?? null) === 'expired') {
            $cards = $cards->filter(fn ($c) => $c->effectiveStatus() === 'expired');
        }
        $byId = $holders->keyBy('id');
        $missing = $cards->pluck('holder_id')->diff($byId->keys());
        if ($missing->isNotEmpty()) {
            $byId = $byId->union(($type === 'student' ? Student::with('user:id,name', 'currentClass:id,name') : Staff::with('user:id,name'))->whereIn('id', $missing)->get()->keyBy('id'));
        }

        return response()->json(['data' => $cards->values()->map(fn ($c) => [
            'id' => $c->id, 'serial' => $c->serial, 'holder_id' => $c->holder_id, 'holder_type' => $c->holder_type,
            'name' => $byId[$c->holder_id]->user->name ?? null,
            'identifier' => $type === 'student' ? ($byId[$c->holder_id]->admission_number ?? null) : ($byId[$c->holder_id]->staff_id ?? null),
            'group' => $type === 'student' ? ($byId[$c->holder_id]->currentClass->name ?? null) : ($byId[$c->holder_id]->designation ?? null),
            'status' => $c->effectiveStatus(), 'production_status' => $c->production_status, 'revoked_reason' => $c->revoked_reason,
            'issued_on' => $c->issued_on?->toDateString(), 'expires_on' => $c->expires_on?->toDateString(),
            'printed_at' => $c->printed_at?->toIso8601String(), 'handed_over_at' => $c->handed_over_at?->toIso8601String(), 'collected_by' => $c->collected_by,
            'replaces_id' => $c->replaces_id, 'misprints' => $c->misprints,
        ])]);
    }

    public function events(int $id): JsonResponse
    {
        $card = IdCard::where('school_id', $this->schoolId())->findOrFail($id);

        return response()->json(['data' => DB::table('id_card_status_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')->where('e.card_id', $card->id)
            ->orderBy('e.id')->get(['e.event', 'e.from', 'e.to', 'e.note', 'e.created_at', 'u.name as by'])]);
    }

    public function confirmPrinted(Request $request, int $id): JsonResponse
    {
        $run = IdCardPrintRun::withoutGlobalScopes()->where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate(['misprinted_card_ids' => 'nullable|array', 'misprinted_card_ids.*' => 'integer']);

        try {
            $result = $this->lifecycle->confirmPrinted($run, $request->user()->id, $data['misprinted_card_ids'] ?? []);
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
        $this->audit($request, 'id_cards.print_confirmed', IdCardPrintRun::class, $run->id, $result);

        return response()->json($result + ['message' => "{$result['printed']} card(s) marked printed".($result['misprinted'] ? "; {$result['misprinted']} misprint(s) left for the next run." : '.')]);
    }

    /** Hand over one or many printed cards; each outcome is reported (§19). */
    public function handOver(Request $request): JsonResponse
    {
        $data = $request->validate(['card_ids' => 'required|array|min:1|max:500', 'card_ids.*' => 'integer', 'collected_by' => 'nullable|string|max:120']);
        $cards = IdCard::where('school_id', $this->schoolId())->whereIn('id', $data['card_ids'])->get()->keyBy('id');
        $results = [];
        foreach ($data['card_ids'] as $id) {
            $card = $cards->get($id);
            try {
                abort_unless($card, 404);
                $this->lifecycle->handOver($card, $request->user()->id, $data['collected_by'] ?? null);
                $results[] = ['card_id' => $id, 'ok' => true];
            } catch (DomainRefusal $e) {
                $results[] = ['card_id' => $id, 'ok' => false, 'reason' => $e->getMessage()];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $results[] = ['card_id' => $id, 'ok' => false, 'reason' => 'Not found.'];
            }
        }
        $done = count(array_filter($results, fn ($r) => $r['ok']));
        $this->audit($request, 'id_cards.handed_over', IdCard::class, 0, ['cards' => $done]);

        return response()->json(['handed_over' => $done, 'results' => $results]);
    }

    /** Cards that will lapse within the window, for holders still at the school (§14). */
    public function renewals(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $days = (int) $request->input('days', IdCardSetting::for($schoolId)->expiry_warning_days);
        $type = $request->input('holder_type', 'student');

        $activeHolders = $type === 'student' ? Student::where('school_id', $schoolId)->where('status', 'active')->pluck('id') : Staff::where('school_id', $schoolId)->pluck('id');
        $cards = IdCard::where('school_id', $schoolId)->where('holder_type', $type)->whereIn('holder_id', $activeHolders->all() ?: [0])
            ->whereIn('status', ['active', 'expired'])->whereNotNull('expires_on')->whereDate('expires_on', '<=', Carbon::today()->addDays($days))->get()
            // Skip anyone who already has a newer valid card.
            ->filter(fn ($c) => ! IdCard::where('school_id', $schoolId)->forHolder($type, $c->holder_id)->where('id', '>', $c->id)->valid()->exists());

        return response()->json(['days' => $days, 'data' => $cards->values()->map(fn ($c) => ['id' => $c->id, 'serial' => $c->serial, 'holder_id' => $c->holder_id, 'expires_on' => $c->expires_on->toDateString(), 'status' => $c->effectiveStatus()])]);
    }

    /** New cards for the new cycle; the old one is kept in history, superseded. */
    public function renew(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate(['card_ids' => 'required|array|min:1|max:500', 'card_ids.*' => 'integer', 'expires_on' => 'nullable|date|after:today']);
        $renewed = 0;
        foreach (IdCard::where('school_id', $schoolId)->whereIn('id', $data['card_ids'])->whereIn('status', ['active', 'expired'])->get() as $old) {
            DB::transaction(function () use ($old, $request, $data, $schoolId, &$renewed) {
                $new = $this->issuance->issue($schoolId, $old->holder_type, $old->holder_id, [
                    'session_id' => TeacherScope::currentSessionId($schoolId), 'expires_on' => $data['expires_on'] ?? null,
                    'issued_by' => $request->user()->id, 'replaces_id' => $old->id, 'force_new' => true,
                ]);
                $old->update(['status' => 'replaced']);
                $this->lifecycle->event($old, 'renewed', 'active', 'replaced', "Renewed as {$new->serial}", $request->user()->id);
                $renewed++;
            });
        }
        $this->audit($request, 'id_cards.renewed', IdCard::class, 0, ['cards' => $renewed]);

        return response()->json(['renewed' => $renewed, 'message' => "{$renewed} card(s) renewed. Add them to a print run to print."]);
    }

    /** Staff check a card by its number, with the same minimal answer as a QR scan (§9). */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['serial' => 'required|string|max:64']);
        $card = IdCard::where('school_id', $this->schoolId())->where('serial', trim($data['serial']))->first();
        $payload = $card ? app(IdCardController::class)->verificationPayload($card) : ['found' => false, 'valid' => false, 'status' => 'unknown', 'headline' => 'Not a recognised card'];
        $this->lifecycle->logVerification($card, $payload['status'], $request->user()->id, $this->schoolId());

        return response()->json($payload, $card ? 200 : 404);
    }

    public function verificationLog(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('id_card_verification_logs as l')->leftJoin('id_cards as c', 'c.id', '=', 'l.card_id')->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.school_id', $this->schoolId())->orderByDesc('l.id')->limit(300)->get(['l.outcome', 'l.created_at', 'c.serial', 'u.name as checked_by'])]);
    }

    // ==================================================================
    // Digital cards (§15): the live state, never a cached image.
    // ==================================================================

    public function mine(Request $request): JsonResponse
    {
        $settings = IdCardSetting::for($this->schoolId());
        abort_unless($settings->digital_cards_enabled, 404, 'This school does not issue digital ID cards.');
        $user = $request->user();
        $holder = Student::where('user_id', $user->id)->first();
        $type = 'student';
        if (! $holder) {
            $holder = Staff::where('user_id', $user->id)->firstOrFail();
            $type = 'staff';
        }

        return response()->json(['card' => $this->digital($type, $holder->id)]);
    }

    public function children(Request $request): JsonResponse
    {
        $settings = IdCardSetting::for($this->schoolId());
        abort_unless($settings->digital_cards_enabled && $settings->parents_see_digital_card, 404, 'This school does not share digital ID cards with parents.');
        $children = Student::whereHas('guardians', fn ($q) => $q->where('guardians.user_id', $request->user()->id))->get();

        return response()->json(['children' => $children->map(fn ($s) => ['student_id' => $s->id, 'card' => $this->digital('student', $s->id)])]);
    }

    private function digital(string $type, int $holderId): ?array
    {
        $card = IdCard::where('school_id', $this->schoolId())->forHolder($type, $holderId)->orderByDesc('id')->first();
        if (! $card) {
            return null;
        }
        $payload = app(IdCardController::class)->verificationPayload($card);
        $status = $this->lifecycle->verificationStatus($card);

        return [
            'serial' => $card->serial, 'status' => $status, 'valid' => $status === 'active', 'headline' => $payload['headline'],
            'holder_name' => $payload['holder_name'], 'role' => $payload['role'], 'school' => $payload['school'],
            'photo' => $payload['photo'], 'issued_on' => $payload['issued_on'], 'expires_on' => $payload['expires_on'],
            // Only a live card carries a scannable code.
            'qr' => $status === 'active' ? app(QrCodeService::class)->dataUriFor(app(IdCardPrintService::class)->verifyUrl($card), 4) : null,
        ];
    }

    private function audit(Request $request, string $action, string $type, int $id, array $values): void
    {
        AuditLog::create(['school_id' => $this->schoolId(), 'user_id' => $request->user()->id, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => $id, 'new_values' => $values]);
    }
}
