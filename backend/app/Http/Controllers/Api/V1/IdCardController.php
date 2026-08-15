<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateIdCardRunJob;
use App\Models\IdCard;
use App\Models\IdCardPrintRun;
use App\Models\IdCardTemplate;
use App\Services\IdCard\CardImpositionService;
use App\Services\IdCard\IdCardIssuanceService;
use App\Services\IdCard\IdCardPrintService;
use App\Services\IdCard\PhotoPreflightService;
use App\Services\ReportCard\TemplateException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Issuing, printing and verifying ID cards.
 *
 * Worth being explicit about what this is not, because the name invites the
 * assumption: a SchoolPilot ID card is not an access-control credential (doc
 * §3). It opens nothing and authorises nothing. The verification endpoint
 * answers exactly one question — "is the card in my hand current, and does the
 * face on it match the register?" — which is what a gatekeeper with a phone
 * actually needs, and it needs no reader to answer.
 */
class IdCardController extends Controller
{
    public function __construct(
        private IdCardPrintService $printer,
        private IdCardIssuanceService $issuance,
        private PhotoPreflightService $photos
    ) {
    }

    private function getSchoolId(Request $request)
    {
        if ($request->user() && $request->user()->userProfile && $request->user()->userProfile->school_id) {
            return $request->user()->userProfile->school_id;
        }

        $tenant = $request->attributes->get('tenant_school');

        return $tenant ? $tenant->id : $request->attributes->get('school_id');
    }

    // ------------------------------------------------------------------
    // Preflight and print runs
    // ------------------------------------------------------------------

    /**
     * What would happen if this run were queued.
     *
     * Deliberately a separate call rather than something the queue reports
     * afterwards. Every problem it finds — a child with no photo, a class that
     * needs five sheets — is one a school wants to know about *before* the
     * paper is in the tray, and none of them need a render to find.
     */
    public function preflight(Request $request)
    {
        $data = $this->validateRunRequest($request);

        try {
            $report = $this->printer->preflight(
                $this->getSchoolId($request),
                $data['holder_type'],
                $data['filters'],
                $data['options']
            );
        } catch (TemplateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($report);
    }

    /**
     * Queue a print run.
     *
     * Refuses up front when preflight found holders it cannot print, unless
     * the school has explicitly said to print the rest anyway. Printing 38 of
     * 42 cards without saying so is how four children end up with nothing and
     * nobody notices until the cards are handed out.
     */
    public function storeRun(Request $request)
    {
        $schoolId = $this->getSchoolId($request);
        $data = $this->validateRunRequest($request);

        try {
            $report = $this->printer->preflight($schoolId, $data['holder_type'], $data['filters'], $data['options']);
        } catch (TemplateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if ($report['printable'] < 1) {
            return response()->json([
                'error' => 'Nothing in this selection can be printed yet.',
                'preflight' => $report,
            ], 422);
        }

        if ($report['blocked'] !== [] && empty($data['options']['proceed_with_blocked'])) {
            return response()->json([
                'error' => sprintf(
                    '%d of %d holders cannot be printed. Fix the problems below, or re-send with "proceed_with_blocked": true to print the rest.',
                    count($report['blocked']),
                    $report['total']
                ),
                'preflight' => $report,
            ], 422);
        }

        $run = IdCardPrintRun::create([
            'school_id' => $schoolId,
            'holder_type' => $data['holder_type'],
            // The options ride inside filters so a re-run reproduces the whole
            // original request, layout choices included, from one column.
            'filters' => array_merge($data['filters'], ['options' => $data['options']]),
            'layout' => $report['layout'],
            'template_id' => $report['template']['id'] ?? null,
            'status' => 'queued',
            'preflight_report' => $report,
            'requested_by' => $request->user()?->id,
        ]);

        GenerateIdCardRunJob::dispatch($run->id);

        return response()->json([
            'message' => 'Print run queued. Poll this run for progress, then download the PDF.',
            'run' => $run,
        ], 202);
    }

    public function indexRuns(Request $request)
    {
        $runs = IdCardPrintRun::withoutGlobalScopes()
            ->where('school_id', $this->getSchoolId($request))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'runs' => $runs->map(fn (IdCardPrintRun $run) => $this->runPayload($run)),
        ]);
    }

    public function showRun(Request $request, $id)
    {
        return response()->json(['run' => $this->runPayload($this->findRun($request, $id))]);
    }

    /**
     * Stream the sheets.
     *
     * The PDF is held on a private disk and served through here rather than
     * from a public URL: a run is a page of children's faces and names, and a
     * guessable link to that is the single worst thing this feature could ship.
     */
    public function downloadRun(Request $request, $id)
    {
        $run = $this->findRun($request, $id);

        if (!$run->isDownloadable()) {
            return response()->json([
                'error' => $run->status === 'completed'
                    ? 'This print run has expired and its PDF has been deleted. Queue it again to reprint.'
                    : 'This print run has no PDF to download yet.',
                'status' => $run->status,
            ], 404);
        }

        $disk = Storage::disk($this->printer->disk());

        if (!$disk->exists($run->pdf_path)) {
            return response()->json(['error' => 'The PDF for this run is no longer on the server.'], 404);
        }

        return response($disk->get($run->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf(
                'attachment; filename="id-cards-run-%d.pdf"',
                $run->id
            ),
        ]);
    }

    // ------------------------------------------------------------------
    // Card lifecycle
    // ------------------------------------------------------------------

    /**
     * Cards issued to one holder, newest first — the replacement history.
     */
    public function holderCards(Request $request, $holderType, $holderId)
    {
        if (!in_array($holderType, IdCard::HOLDER_TYPES, true)) {
            return response()->json(['error' => 'Unknown holder type.'], 422);
        }

        $schoolId = $this->getSchoolId($request);

        $cards = IdCard::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->forHolder($holderType, (int) $holderId)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'cards' => $cards->map(fn (IdCard $card) => $this->cardPayload($card)),
        ]);
    }

    public function revoke(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => ['required', Rule::in(IdCard::REVOCATION_REASONS)],
        ]);

        $card = $this->findCard($request, $id);

        if ($card->status === 'revoked') {
            return response()->json(['error' => 'This card is already revoked.'], 422);
        }

        $card = $this->issuance->revoke($card, $validated['reason'], $request->user()?->id);

        return response()->json([
            'message' => "Card {$card->serial} is revoked. Scanning it now shows that it is not valid.",
            'card' => $this->cardPayload($card),
        ]);
    }

    /**
     * Revoke a card and issue its successor.
     */
    public function replace(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => ['required', Rule::in(IdCard::REVOCATION_REASONS)],
        ]);

        $card = $this->findCard($request, $id);
        $replacement = $this->issuance->replace($card, $validated['reason'], $request->user()?->id);

        return response()->json([
            'message' => "Card {$card->serial} is revoked and replaced by {$replacement->serial}. Print the replacement to hand it over.",
            'card' => $this->cardPayload($replacement),
            'replaces' => $this->cardPayload($card->fresh()),
        ]);
    }

    // ------------------------------------------------------------------
    // Public verification
    // ------------------------------------------------------------------

    /**
     * What a gatekeeper's phone sees after scanning the QR.
     *
     * Public and unauthenticated by necessity — the whole point is that the
     * person checking the card is a gateman, a bus driver, or a receptionist
     * at another school, none of whom have a login. Three things follow from
     * that, and they are the design:
     *
     *  1. **HTML by default.** A phone camera opens a URL in a browser. A JSON
     *     body is the wrong answer to a scan, so JSON is opt-in.
     *  2. **Minimal disclosure.** Name, face, class or role, school, and
     *     whether the card is current. Nothing else — no date of birth, no
     *     address, no admission number, no guardian, nothing medical. All of
     *     it is already printed on the card the scanner is holding, so the
     *     page adds no disclosure beyond confirming the card is genuine.
     *  3. **An unknown token is not an error.** It renders the same "not
     *     valid" page as a revoked one, so the endpoint cannot be walked to
     *     learn which tokens exist.
     */
    public function verify(Request $request, string $token)
    {
        $card = IdCard::withoutGlobalScopes()->where('verify_token', $token)->first();

        $payload = $card ? $this->verificationPayload($card) : [
            'found' => false,
            'valid' => false,
            'status' => 'unknown',
            'headline' => 'Not a recognised card',
            'detail' => 'This code does not match any card issued through SchoolPilot.',
        ];

        if ($request->get('format') === 'json') {
            return response()->json($payload, $payload['found'] ? 200 : 404);
        }

        return response()
            ->view('id-cards.verify', ['card' => $payload], $payload['found'] ? 200 : 404)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            // The page describes a named child. It must not sit in a shared
            // proxy cache or a browser's back-button store on a shared phone.
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function verificationPayload(IdCard $card): array
    {
        $status = $card->effectiveStatus();
        $holder = $card->holder();
        $school = $card->school;

        [$headline, $detail] = match ($status) {
            'active' => ['Valid card', 'This card is current.'],
            'expired' => ['Expired card', 'This card has passed its expiry date and is no longer current.'],
            'replaced' => ['Superseded card', 'A newer card has been issued to this holder. This one is no longer current.'],
            'revoked' => ['Card withdrawn', match ($card->revoked_reason) {
                'lost' => 'This card was reported lost and is no longer valid.',
                'stolen' => 'This card was reported stolen and is no longer valid.',
                'damaged' => 'This card was replaced after damage and is no longer valid.',
                'left_school' => 'The holder is no longer at this school.',
                'data_error' => 'This card was withdrawn and reissued to correct an error on it.',
                default => 'This card has been withdrawn by the school and is no longer valid.',
            }],
            default => ['Card not valid', 'This card is not current.'],
        };

        $photo = null;

        if ($holder && $status === 'active') {
            /*
             * The face is shown only on a valid card, and only because it is
             * the check that actually catches a forgery — a card with a
             * swapped photo passes every other test on this page. Withheld
             * once the card is dead: at that point the scanner is holding a
             * withdrawn card and has no business being served the picture.
             */
            $resolved = $this->photos->resolve($holder->passport_photo_path ?? null);
            $photo = $resolved['ok'] ? $resolved['data_uri'] : null;
        }

        $isStudent = $card->holder_type === 'student';

        return [
            'found' => true,
            'valid' => $status === 'active',
            'status' => $status,
            'headline' => $headline,
            'detail' => $detail,
            'serial' => $card->serial,
            'school' => $school->name ?? '',
            'holder_name' => trim((string) ($holder->user->name ?? '')),
            'holder_type' => $card->holder_type,
            'role' => $isStudent
                ? trim(($holder->currentClass->name ?? '') . ' ' . ($holder->currentArm->name ?? ''))
                : (string) ($holder->designation ?? 'Staff'),
            'photo' => $photo,
            'issued_on' => $card->issued_on?->toDateString(),
            'expires_on' => $card->expires_on?->toDateString(),
            'checked_at' => now()->format('j M Y, H:i'),
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array{holder_type:string,filters:array,options:array}
     */
    private function validateRunRequest(Request $request): array
    {
        $validated = $request->validate([
            'holder_type' => ['required', Rule::in(IdCardTemplate::HOLDER_TYPES)],
            'class_id' => ['nullable', 'integer'],
            'arm_id' => ['nullable', 'integer'],
            'holder_ids' => ['nullable', 'array', 'max:' . IdCardPrintService::MAX_CARDS_PER_RUN],
            'holder_ids.*' => ['integer'],
            'session_id' => ['nullable', 'integer'],
            'expires_on' => ['nullable', 'date'],

            'sheet' => ['nullable', Rule::in(array_keys(CardImpositionService::SHEET_SIZES))],
            'sheet_orientation' => ['nullable', Rule::in(['portrait', 'landscape'])],
            'margin_mm' => ['nullable', 'numeric', 'min:0', 'max:40'],
            'gutter_mm' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'cut_guides' => ['nullable', Rule::in(['border', 'marks', 'none'])],
            'duplex' => ['nullable', Rule::in(['long-edge', 'short-edge', 'none'])],
            'duplex_offset_mm' => ['nullable', 'array'],
            'duplex_offset_mm.x' => ['nullable', 'numeric', 'min:-10', 'max:10'],
            'duplex_offset_mm.y' => ['nullable', 'numeric', 'min:-10', 'max:10'],

            'allow_missing_photos' => ['nullable', 'boolean'],
            'proceed_with_blocked' => ['nullable', 'boolean'],
            'include_blood_group' => ['nullable', 'boolean'],
        ]);

        return [
            'holder_type' => $validated['holder_type'],
            'filters' => array_filter([
                'class_id' => $validated['class_id'] ?? null,
                'arm_id' => $validated['arm_id'] ?? null,
                'holder_ids' => $validated['holder_ids'] ?? null,
                'session_id' => $validated['session_id'] ?? null,
                'expires_on' => $validated['expires_on'] ?? null,
            ], fn ($value) => $value !== null),
            'options' => array_filter([
                'sheet' => $validated['sheet'] ?? null,
                'sheet_orientation' => $validated['sheet_orientation'] ?? null,
                'margin_mm' => $validated['margin_mm'] ?? null,
                'gutter_mm' => $validated['gutter_mm'] ?? null,
                'cut_guides' => $validated['cut_guides'] ?? null,
                'duplex' => $validated['duplex'] ?? null,
                'duplex_offset_mm' => $validated['duplex_offset_mm'] ?? null,
                'allow_missing_photos' => $validated['allow_missing_photos'] ?? null,
                'proceed_with_blocked' => $validated['proceed_with_blocked'] ?? null,
                'include_blood_group' => $validated['include_blood_group'] ?? null,
            ], fn ($value) => $value !== null),
        ];
    }

    private function runPayload(IdCardPrintRun $run): array
    {
        return array_merge($run->toArray(), [
            'downloadable' => $run->isDownloadable(),
            'expires_in_days' => $run->expires_at
                ? max(0, now()->diffInDays($run->expires_at, false))
                : null,
        ]);
    }

    /**
     * The token is deliberately not in here.
     *
     * It is a bearer value for the public verification page, and the only
     * place it needs to exist outside the database is inside the QR on the
     * printed card. An admin asking about a card gets its status directly, so
     * echoing the token — or a URL containing it — into a listing would widen
     * its reach for no gain.
     */
    private function cardPayload(IdCard $card): array
    {
        return array_merge($card->toArray(), [
            'effective_status' => $card->effectiveStatus(),
        ]);
    }

    private function findRun(Request $request, $id): IdCardPrintRun
    {
        return IdCardPrintRun::withoutGlobalScopes()
            ->where('school_id', $this->getSchoolId($request))
            ->findOrFail($id);
    }

    private function findCard(Request $request, $id): IdCard
    {
        return IdCard::withoutGlobalScopes()
            ->where('school_id', $this->getSchoolId($request))
            ->findOrFail($id);
    }
}
