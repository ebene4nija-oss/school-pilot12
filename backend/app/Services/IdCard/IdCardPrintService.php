<?php

namespace App\Services\IdCard;

use App\Models\IdCard;
use App\Models\IdCardPrintRun;
use App\Models\IdCardTemplate;
use App\Models\School;
use App\Models\Staff;
use App\Models\Student;
use App\Services\QrCodeService;
use App\Services\ReportCard\TemplateException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Turns "print JSS 2's ID cards" into a PDF.
 *
 * The order of operations is the point of this class. Every holder is resolved
 * and every photo is checked *before* anything is rendered, because the
 * alternative — discovering on sheet eleven that four children have no photo —
 * costs a school a ream of paper and an afternoon. A run that cannot produce
 * usable cards for everyone in it stops at preflight and says who is missing,
 * unless the school has explicitly said to print the rest anyway.
 */
class IdCardPrintService
{
    /**
     * A ceiling on one run.
     *
     * Each card carries an embedded photo and its own QR, so a run's memory
     * cost is roughly linear and real: 400 cards is already tens of megabytes
     * of markup before dompdf starts. A school printing a whole 1,200-pupil
     * roll splits it by class, which is how they hand the sheets out anyway.
     */
    public const MAX_CARDS_PER_RUN = 400;

    public function __construct(
        private IdCardTemplateService $templates,
        private IdCardIssuanceService $issuance,
        private PhotoPreflightService $photos,
        private CardImpositionService $imposition,
        private QrCodeService $qr
    ) {
    }

    // ------------------------------------------------------------------
    // Preflight
    // ------------------------------------------------------------------

    /**
     * Find out what would happen, without rendering anything.
     *
     * @return array{
     *     holder_type:string, eligible:int, blocked:array, warnings:array,
     *     total:int, layout:array, template:array|null, truncated:bool
     * }
     */
    public function preflight(int $schoolId, string $holderType, array $filters, array $options = []): array
    {
        $holders = $this->resolveHolders($schoolId, $holderType, $filters);
        $truncated = count($holders) > self::MAX_CARDS_PER_RUN;
        $holders = array_slice($holders, 0, self::MAX_CARDS_PER_RUN);

        $template = $this->templates->activeTemplateFor($schoolId, $holderType);
        $geometry = $this->templates->geometryFor($template);
        $plan = $this->imposition->plan($geometry, $options);

        $eligible = [];
        $blocked = [];
        $warnings = [];

        foreach ($holders as $holder) {
            $name = trim((string) ($holder->user->name ?? ''));
            $label = $name !== '' ? $name : ($holderType === 'staff' ? ($holder->staff_id ?? '') : ($holder->admission_number ?? ''));

            if ($name === '') {
                $blocked[] = [
                    'holder_id' => $holder->id,
                    'label' => $label ?: ('#' . $holder->id),
                    'reason' => 'no_name',
                    'detail' => PhotoPreflightService::describeProblem('no_name'),
                ];
                continue;
            }

            $photo = $this->photos->resolve($holder->passport_photo_path ?? null, $geometry['photo_box_mm']);

            if (!$photo['ok']) {
                $blocked[] = [
                    'holder_id' => $holder->id,
                    'label' => $label,
                    'reason' => $photo['problem'],
                    'detail' => PhotoPreflightService::describeProblem((string) $photo['problem']),
                ];
                continue;
            }

            foreach ($photo['warnings'] as $warning) {
                $warnings[] = ['holder_id' => $holder->id, 'label' => $label, 'detail' => $warning];
            }

            $eligible[] = $holder->id;
        }

        $printable = count($eligible) + (!empty($options['allow_missing_photos']) ? $this->photoOnlyBlocks($blocked) : 0);

        return [
            'holder_type' => $holderType,
            'total' => count($holders) + ($truncated ? 1 : 0),
            'eligible' => count($eligible),
            'printable' => $printable,
            'blocked' => $blocked,
            'warnings' => $warnings,
            'truncated' => $truncated,
            'max_per_run' => self::MAX_CARDS_PER_RUN,
            'layout' => $plan,
            'estimated_sheets' => $plan['per_sheet'] > 0
                ? (int) ceil($printable / $plan['per_sheet']) * (($template?->isDoubleSided() ?? true) && $plan['duplex'] !== 'none' ? 2 : 1)
                : 0,
            'template' => $template ? [
                'id' => $template->id,
                'name' => $template->name,
                'version' => $template->version,
                'double_sided' => $template->isDoubleSided(),
            ] : null,
        ];
    }

    /**
     * How many of the blocked entries are blocked *only* by a missing photo —
     * which `allow_missing_photos` can wave through. A holder with no name is
     * never printable whatever the school ticks.
     */
    private function photoOnlyBlocks(array $blocked): int
    {
        return count(array_filter(
            $blocked,
            fn (array $entry) => in_array($entry['reason'], ['no_photo', 'photo_not_found'], true)
        ));
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /**
     * Render a queued run into a stored PDF.
     *
     * @throws TemplateException
     */
    public function render(IdCardPrintRun $run): IdCardPrintRun
    {
        $schoolId = $run->school_id;
        $holderType = $run->holder_type;
        $filters = (array) ($run->filters ?? []);
        $options = (array) ($filters['options'] ?? []);

        $run->update(['status' => 'rendering']);

        $template = $run->template_id
            ? IdCardTemplate::withoutGlobalScopes()->where('school_id', $schoolId)->find($run->template_id)
            : $this->templates->activeTemplateFor($schoolId, $holderType);

        $geometry = $this->templates->geometryFor($template);
        $styles = $this->templates->stylesFor($template, $holderType);

        $holders = array_slice(
            $this->resolveHolders($schoolId, $holderType, $filters),
            0,
            self::MAX_CARDS_PER_RUN
        );

        // The crest is the same bytes on every card in the run; resolving it
        // once keeps 400 identical base64 blobs from being built 400 times.
        $logo = $this->schoolLogo($schoolId);

        $cards = [];
        $issued = [];
        $skipped = [];

        foreach ($holders as $holder) {
            $name = trim((string) ($holder->user->name ?? ''));

            if ($name === '') {
                $skipped[] = ['holder_id' => $holder->id, 'reason' => 'no_name'];
                continue;
            }

            $photo = $this->photos->resolve($holder->passport_photo_path ?? null, $geometry['photo_box_mm']);

            if (!$photo['ok'] && empty($options['allow_missing_photos'])) {
                $skipped[] = ['holder_id' => $holder->id, 'reason' => $photo['problem']];
                continue;
            }

            $card = $this->issuance->issue($schoolId, $holderType, $holder->id, [
                'session_id' => $filters['session_id'] ?? null,
                'expires_on' => $filters['expires_on'] ?? null,
                'issued_by' => $run->requested_by,
            ]);

            $cards[] = $this->renderCard($card, $holder, $template, [
                'photo' => $photo['data_uri'] ?? '',
                'school_logo' => $logo,
                'include_blood_group' => !empty($options['include_blood_group']),
            ]);

            $card->update([
                'template_id' => $template?->id,
                'template_checksum' => $template?->checksum,
                'photo_fingerprint' => $photo['fingerprint'],
                'print_count' => $card->print_count + 1,
                'last_printed_at' => now(),
            ]);

            $issued[] = $card->id;
        }

        if ($cards === []) {
            $run->update([
                'status' => 'preflight_failed',
                'error' => 'No holder in this run had both a name and a usable photo, so there was nothing to print.',
                'preflight_report' => array_merge((array) $run->preflight_report, ['skipped' => $skipped]),
                'completed_at' => now(),
            ]);

            return $run->fresh();
        }

        $imposed = $this->imposition->impose($cards, $geometry, $styles, $options);
        $pdf = $this->toPdf($imposed['html']);

        $path = sprintf('id-cards/%d/run-%d-%s.pdf', $schoolId, $run->id, now()->format('Ymd-His'));
        Storage::disk($this->disk())->put($path, $pdf);

        $run->update([
            'status' => 'completed',
            'template_id' => $template?->id,
            'layout' => $imposed['plan'],
            'card_count' => count($cards),
            'sheet_count' => $imposed['sheet_count'],
            'pdf_path' => $path,
            'pdf_bytes' => strlen($pdf),
            'expires_at' => now()->addDays(IdCardPrintRun::RETENTION_DAYS),
            'preflight_report' => array_merge((array) $run->preflight_report, [
                'skipped' => $skipped,
                'issued_card_ids' => $issued,
            ]),
            'completed_at' => now(),
        ]);

        return $run->fresh();
    }

    /**
     * One card, both sides, rendered and sanitised.
     *
     * @return array{front:string,back:string|null}
     */
    public function renderCard(IdCard $card, Model $holder, ?IdCardTemplate $template, array $extra = []): array
    {
        $verifyUrl = $this->verifyUrl($card);

        $context = $this->templates->buildContext($card, $holder, array_merge($extra, [
            'verification' => [
                'token' => $card->verify_token,
                'verify_url' => $verifyUrl,
                // Generated on this server, as a data URI. Handing every
                // child's verification URL to a hosted QR service on every
                // print is an avoidable disclosure (doc §12), and the PDF
                // renderer could not fetch the result anyway.
                'qr_code_url' => $this->qr->dataUriFor($verifyUrl, 3) ?? '',
            ],
        ]));

        return $this->templates->renderSides($template, $context);
    }

    public function verifyUrl(IdCard $card): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/v1/verify-id/' . $card->verify_token;
    }

    // ------------------------------------------------------------------
    // Holders
    // ------------------------------------------------------------------

    /**
     * Who is in this run.
     *
     * Ordered by name rather than by id so a printed stack comes off the
     * printer in the order a class register is read, which is how the cards
     * get handed out.
     *
     * @return array<int,Model>
     */
    public function resolveHolders(int $schoolId, string $holderType, array $filters): array
    {
        $ids = array_filter(array_map('intval', (array) ($filters['holder_ids'] ?? [])));

        if ($holderType === 'staff') {
            $query = Staff::withoutGlobalScopes()
                ->with('user')
                ->where('school_id', $schoolId);

            if ($ids !== []) {
                $query->whereIn('id', $ids);
            }

            return $query->get()
                ->sortBy(fn (Staff $staff) => (string) ($staff->user->name ?? ''))
                ->values()
                ->all();
        }

        $query = Student::withoutGlobalScopes()
            ->with(['user', 'currentClass', 'currentArm'])
            ->where('school_id', $schoolId);

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        if (!empty($filters['class_id'])) {
            $query->where('class_id', (int) $filters['class_id']);
        }

        if (!empty($filters['arm_id'])) {
            $query->where('arm_id', (int) $filters['arm_id']);
        }

        // A withdrawn student does not get a fresh card unless asked for by id.
        if ($ids === []) {
            $query->where('status', 'active');
        }

        return $query->get()
            ->sortBy(fn (Student $student) => (string) ($student->user->name ?? ''))
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function schoolLogo(int $schoolId): string
    {
        $school = School::find($schoolId);
        $resolved = $this->photos->resolve($school->logo_url ?? null, ['width' => 10.0, 'height' => 10.0]);

        // A missing crest is cosmetic — the design's {{#if}} handles it — so it
        // never blocks a run the way a missing face does.
        return $resolved['ok'] ? (string) $resolved['data_uri'] : '';
    }

    /**
     * dompdf, locked down the same way the report card renderer locks it down:
     * no remote fetching (a school design must not make our server request a
     * URL of its choosing at print time) and no code execution in the document.
     */
    private function toPdf(string $html): string
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setOptions([
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'dpi' => 96,
            ])
            ->output();
    }

    /**
     * Private storage, always.
     *
     * A sheet of children's faces and names must not be reachable by anyone
     * who guesses a URL; downloads go through the authenticated controller,
     * which checks the school on the run.
     */
    public function disk(): string
    {
        return config('filesystems.default') === 'public' ? 'local' : config('filesystems.default', 'local');
    }
}
