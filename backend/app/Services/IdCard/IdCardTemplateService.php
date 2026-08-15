<?php

namespace App\Services\IdCard;

use App\Models\IdCard;
use App\Models\IdCardTemplate;
use App\Models\School;
use App\Services\ReportCard\HtmlSanitizer;
use App\Services\ReportCard\TemplateException;
use App\Services\ReportCard\TemplateRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Import, validation and rendering of school-authored ID card designs.
 *
 * The pipeline is the report card's, reused wholesale: the same restricted
 * dialect (TemplateRenderer), the same whitelist sanitiser (HtmlSanitizer),
 * the same import -> dry-run -> draft -> activate flow. A school-uploaded
 * design is untrusted markup rendered on shared infrastructure whichever
 * document it becomes, so it gets the same treatment.
 *
 * What is different is the shape of the output. A report card is one page and
 * renders to a complete HTML document. An ID card is a small two-sided object
 * that is meaningless on its own — it only becomes printable once dozens of
 * them are imposed onto a sheet. So this class renders *fragments* and hands
 * them, with the geometry, to CardImpositionService. The only place it emits a
 * whole document is the single-card preview, which exists for a designer
 * iterating on a layout.
 */
class IdCardTemplateService
{
    public const MAX_SIDE_BYTES = 200000;
    public const MAX_STYLES_BYTES = 100000;

    /**
     * Named card stocks, in millimetres, landscape.
     *
     * CR80 is the bank-card size every Nigerian print shop and pouch laminator
     * already stocks, which is the whole point: a school prints on paper it can
     * buy on the same street.
     */
    public const CARD_SIZES = [
        'CR80' => ['width' => 85.6, 'height' => 54.0],   // standard ID / bank card
        'CR79' => ['width' => 83.9, 'height' => 51.0],   // adhesive insert
        'CR100' => ['width' => 98.5, 'height' => 67.0],  // oversized, common for staff
    ];

    /**
     * A design that omits these is not a SchoolPilot ID card.
     *
     * The holder's name and the verification code are both non-negotiable. A
     * card with no name identifies nobody; a card with no verification code is
     * a laminated photo, and the ability to check one against the register is
     * the entire reason this is a product feature and not a Word template.
     */
    private const REQUIRED_TOKENS = [
        'holder.name' => 'the holder\'s name ({{ holder.name }})',
        'verification.' => 'the verification code ({{ verification.qr_code_url }} or {{ verification.verify_url }}), on either side',
    ];

    /** Cheap pre-scan: things that are never an honest mistake. */
    private const HARD_REJECT_PATTERNS = [
        '/<\s*script\b/i' => 'a <script> tag',
        '/<\s*iframe\b/i' => 'an <iframe> tag',
        '/<\s*object\b/i' => 'an <object> tag',
        '/<\s*embed\b/i' => 'an <embed> tag',
        '/\son[a-z]+\s*=/i' => 'an inline event handler (onclick, onerror, …)',
        '/javascript\s*:/i' => 'a javascript: URL',
        '/<\?php/i' => 'PHP code',
        '/@php\b/i' => 'a Blade @php directive',
        '/\{\{\{/' => 'unescaped output ({{{ … }}}), which this engine does not support',
    ];

    public function __construct(
        private TemplateRenderer $renderer,
        private HtmlSanitizer $sanitizer
    ) {
    }

    // ------------------------------------------------------------------
    // Import
    // ------------------------------------------------------------------

    /**
     * Validate and store a bundle as a draft.
     *
     * @throws TemplateException
     */
    public function import(array $bundle, int $schoolId, ?int $userId = null): IdCardTemplate
    {
        $engine = $bundle['engine'] ?? null;

        if ($engine !== IdCardTemplate::ENGINE) {
            throw new TemplateException(sprintf(
                'Bundle declares engine "%s"; this server renders "%s". Update the bundle to the current contract.',
                $engine ?? 'none',
                IdCardTemplate::ENGINE
            ));
        }

        $name = trim((string) ($bundle['name'] ?? ''));

        if ($name === '') {
            throw new TemplateException('Bundle is missing a "name".');
        }

        $holderType = strtolower(trim((string) ($bundle['holder_type'] ?? 'student')));

        if (!in_array($holderType, IdCardTemplate::HOLDER_TYPES, true)) {
            throw new TemplateException(
                'Bundle "holder_type" must be one of: ' . implode(', ', IdCardTemplate::HOLDER_TYPES) . '.'
            );
        }

        $front = (string) ($bundle['front'] ?? '');

        if (trim($front) === '') {
            throw new TemplateException('Bundle is missing a "front" (the card face markup).');
        }

        $back = (string) ($bundle['back'] ?? '');

        foreach (['front' => $front, 'back' => $back] as $label => $markup) {
            if (strlen($markup) > self::MAX_SIDE_BYTES) {
                throw new TemplateException("Card \"{$label}\" exceeds " . self::MAX_SIDE_BYTES . ' bytes.');
            }
        }

        $styles = (string) ($bundle['styles'] ?? '');

        if (strlen($styles) > self::MAX_STYLES_BYTES) {
            throw new TemplateException('Card stylesheet exceeds ' . self::MAX_STYLES_BYTES . ' bytes.');
        }

        $this->rejectObviouslyUnsafe($front . "\n" . $back);
        // Checked against both sides joined: a school is free to put the QR on
        // the back, which is where most of them belong.
        $this->assertRequiredTokens($front . "\n" . $back);

        $warnings = array_merge(
            $this->renderer->lint($front),
            $back !== '' ? $this->renderer->lint($back) : []
        );

        $geometry = $this->normaliseGeometry($bundle['card'] ?? $bundle['card_geometry'] ?? []);

        // Dry run: proves the design renders, and shows the admin exactly what
        // the sanitiser will take out, before it is ever activated.
        $sample = $bundle['sample_data'] ?? $this->sampleContext($holderType);
        $removals = [];
        $renderedBytes = 0;

        foreach (array_filter(['front' => $front, 'back' => $back]) as $markup) {
            $rendered = $this->renderer->render($markup, $sample);
            $sanitised = $this->sanitizer->sanitize($rendered);
            $removals = array_merge($removals, $sanitised['removals']);
            $renderedBytes += strlen($sanitised['html']);
        }

        $styleResult = $this->sanitizer->sanitizeCss($styles, self::MAX_STYLES_BYTES);

        $slug = Str::slug($bundle['slug'] ?? $name);
        $version = (int) ($bundle['version'] ?? 0);

        if ($version < 1) {
            $version = 1 + (int) IdCardTemplate::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('slug', $slug)
                ->max('version');
        }

        $exists = IdCardTemplate::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('slug', $slug)
            ->where('version', $version)
            ->exists();

        if ($exists) {
            throw new TemplateException(sprintf(
                'Version %d of "%s" already exists. Bump the version in the bundle to import a revision.',
                $version,
                $slug
            ));
        }

        return IdCardTemplate::create([
            'school_id' => $schoolId,
            'name' => $name,
            'slug' => $slug,
            'version' => $version,
            'description' => $bundle['description'] ?? null,
            'engine' => IdCardTemplate::ENGINE,
            'holder_type' => $holderType,
            'front' => $front,
            'back' => $back !== '' ? $back : null,
            'styles' => $styleResult['css'],
            'card_geometry' => $geometry,
            'regions' => array_values((array) ($bundle['regions'] ?? [])),
            'validation_report' => [
                'warnings' => array_values(array_unique($warnings)),
                'markup_removals' => array_values(array_unique($removals)),
                'style_removals' => $styleResult['removals'],
                'sample_render_bytes' => $renderedBytes,
                'double_sided' => $back !== '',
                'validated_at' => now()->toIso8601String(),
            ],
            'checksum' => hash('sha256', $front . '|' . $back . '|' . $styleResult['css']),
            'status' => 'draft',
            'imported_by' => $userId,
            'imported_at' => now(),
        ]);
    }

    private function rejectObviouslyUnsafe(string $markup): void
    {
        foreach (self::HARD_REJECT_PATTERNS as $pattern => $description) {
            if (preg_match($pattern, $markup)) {
                throw new TemplateException(
                    "Template contains {$description}, which is not permitted. ID card designs are markup and CSS only — see the template contract for the supported tags and tokens."
                );
            }
        }
    }

    private function assertRequiredTokens(string $markup): void
    {
        $missing = [];

        foreach (self::REQUIRED_TOKENS as $token => $description) {
            if (!str_contains($markup, $token)) {
                $missing[] = $description;
            }
        }

        if ($missing !== []) {
            throw new TemplateException('Design is missing required content: ' . implode('; ', $missing) . '.');
        }
    }

    /**
     * Resolve declared geometry into absolute millimetres.
     *
     * Stored resolved rather than as a named size, because a card that was cut
     * to 85.6mm in 2026 must still measure 85.6mm if the named-size table is
     * ever edited. Orientation swaps the axes rather than rotating at render
     * time — a portrait card is a 54x85.6 box, not a rotated landscape one.
     *
     * @return array{size:string,width_mm:float,height_mm:float,orientation:string,bleed_mm:float,corner_radius_mm:float,photo_box_mm:array{width:float,height:float}}
     */
    public function normaliseGeometry(array $settings): array
    {
        $size = strtoupper((string) ($settings['size'] ?? 'CR80'));
        $base = self::CARD_SIZES[$size] ?? self::CARD_SIZES['CR80'];

        if (!isset(self::CARD_SIZES[$size])) {
            $size = isset($settings['width_mm'], $settings['height_mm']) ? 'CUSTOM' : 'CR80';
        }

        $width = (float) ($settings['width_mm'] ?? $base['width']);
        $height = (float) ($settings['height_mm'] ?? $base['height']);

        // Anything outside this is not a card anybody laminates or carries.
        $width = min(120.0, max(40.0, $width));
        $height = min(120.0, max(40.0, $height));

        $orientation = strtolower((string) ($settings['orientation'] ?? 'landscape'));

        if (!in_array($orientation, ['landscape', 'portrait'], true)) {
            $orientation = 'landscape';
        }

        if ($orientation === 'portrait' && $width > $height) {
            [$width, $height] = [$height, $width];
        }

        if ($orientation === 'landscape' && $height > $width) {
            [$width, $height] = [$height, $width];
        }

        $photoBox = $settings['photo_box_mm'] ?? [];

        return [
            'size' => $size,
            'width_mm' => round($width, 2),
            'height_mm' => round($height, 2),
            'orientation' => $orientation,
            // Bleed only matters at a commercial printer; an office printer
            // ignores it. Capped low because a large bleed on a small card is
            // always a mistake in the bundle.
            'bleed_mm' => min(5.0, max(0.0, (float) ($settings['bleed_mm'] ?? 0))),
            'corner_radius_mm' => min(6.0, max(0.0, (float) ($settings['corner_radius_mm'] ?? 3.0))),
            // Declared, not measured — it tells the photo preflight what DPI
            // the photo will actually be printed at.
            'photo_box_mm' => [
                'width' => min(60.0, max(5.0, (float) ($photoBox['width'] ?? PhotoPreflightService::DEFAULT_BOX_MM['width']))),
                'height' => min(60.0, max(5.0, (float) ($photoBox['height'] ?? PhotoPreflightService::DEFAULT_BOX_MM['height']))),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /**
     * Render one card's two sides as sanitised HTML fragments.
     *
     * Sanitising the *rendered output* rather than the stored template is the
     * same ordering the report card engine uses, for the same reason: an HTML
     * parser relocates stray text, which would silently move a block tag out
     * of the element it belongs to. Rendering first means the parser only ever
     * sees finished markup, and the renderer has already escaped every value
     * that came from the database.
     *
     * @return array{front:string,back:string|null}
     * @throws TemplateException
     */
    public function renderSides(?IdCardTemplate $template, array $context): array
    {
        $holderType = $context['holder']['type'] ?? 'student';

        $front = $template?->front ?? $this->defaultFront($holderType);
        $back = $template ? $template->back : $this->defaultBack($holderType);

        $renderSide = function (?string $markup) use ($context): ?string {
            if ($markup === null || trim($markup) === '') {
                return null;
            }

            return $this->sanitizer->sanitize($this->renderer->render($markup, $context))['html'];
        };

        return [
            'front' => $renderSide($front) ?? '',
            'back' => $renderSide($back),
        ];
    }

    public function stylesFor(?IdCardTemplate $template, string $holderType = 'student'): string
    {
        $css = $template?->styles ?? $this->defaultStyles($holderType);

        return $this->sanitizer->sanitizeCss($css, self::MAX_STYLES_BYTES)['css'];
    }

    public function geometryFor(?IdCardTemplate $template): array
    {
        return $this->normaliseGeometry($template?->card_geometry ?? []);
    }

    /** The live design for a school's students or staff, or null for the default. */
    public function activeTemplateFor(int $schoolId, string $holderType): ?IdCardTemplate
    {
        return IdCardTemplate::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('holder_type', $holderType)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();
    }

    // ------------------------------------------------------------------
    // Data context
    // ------------------------------------------------------------------

    /**
     * Everything a card design can see.
     *
     * `holder.*` is canonical and identical in shape for a student and a
     * member of staff, so one dialect covers both and a school that wants two
     * visibly different designs writes two bundles rather than one with
     * branching in it. Fields that do not apply to a holder type are present
     * and empty rather than absent, so `{{ holder.class }}` on a staff card
     * prints nothing instead of failing.
     *
     * Nothing here is reachable except what is put here. A design cannot query
     * for data it was not given.
     *
     * @param Model $holder a Student or Staff row
     * @param array $extra  verification, photo, logo, and per-run options
     */
    public function buildContext(IdCard $card, Model $holder, array $extra = []): array
    {
        $school = School::find($card->school_id);
        $isStudent = $card->holder_type === 'student';

        $name = trim((string) ($holder->user->name ?? ''));

        $context = [
            'school' => [
                'name' => $school->name ?? '',
                'address' => $school->address ?? '',
                'phone' => $school->phone ?? '',
                'email' => $school->email ?? '',
                // Resolved to a data URI by the caller; dompdf cannot fetch.
                'logo_url' => $extra['school_logo'] ?? '',
                'subdomain' => $school->subdomain ?? '',
            ],
            'holder' => [
                'type' => $card->holder_type,
                'name' => $name,
                'photo_url' => $extra['photo'] ?? '',
                'gender' => $holder->gender ?? '',
                // A single labelled identifier, so one design can print
                // "Admission No." for a child and "Staff ID" for a teacher
                // without knowing which it is rendering.
                'id_label' => $isStudent ? 'Admission No.' : 'Staff ID',
                'id_value' => $isStudent ? ($holder->admission_number ?? '') : ($holder->staff_id ?? ''),
                'class' => $isStudent ? ($holder->currentClass->name ?? '') : '',
                'arm' => $isStudent ? ($holder->currentArm->name ?? '') : '',
                'designation' => $isStudent ? '' : ($holder->designation ?? ''),
                'admission_number' => $isStudent ? ($holder->admission_number ?? '') : '',
                'staff_id' => $isStudent ? '' : ($holder->staff_id ?? ''),
            ],
            'card' => [
                'serial' => $card->serial,
                // Plain date strings, not Carbon instances. The dialect can
                // only traverse arrays, so an object here would be printed via
                // its __toString ("2026-09-14 00:00:00") whenever a design
                // omits the `date` filter.
                'issued_on' => $card->issued_on?->toDateString() ?? '',
                'expires_on' => $card->expires_on?->toDateString() ?? '',
                'session' => $card->session->name ?? '',
                'status' => $card->effectiveStatus(),
            ],
            'verification' => $extra['verification'] ?? [],
            'emergency' => [
                'phone' => $extra['emergency_phone'] ?? ($school->phone ?? ''),
            ],
            'generated_at' => now()->toDateString(),
        ];

        /*
         * Blood group is off by default and opt-in per print run.
         *
         * It is one of the fields §12 singles out for application-level
         * encryption, and an ID card is the least controlled place any field
         * can end up — it gets lost in a market, photographed, picked up by a
         * stranger. Nigerian school cards do commonly carry it, and in a real
         * emergency it is genuinely useful, so the school gets to make that
         * call for itself. It is never the default, and it is never inferred.
         */
        if (!empty($extra['include_blood_group']) && $isStudent) {
            $context['holder']['blood_group'] = (string) ($holder->blood_group ?? '');
        }

        return array_replace_recursive($context, $extra['overrides'] ?? []);
    }

    /**
     * Fictional data for previews and import dry-runs.
     *
     * Deliberately not a real record: a designer checking a corner radius must
     * not have a child's face and admission number on their screen to do it.
     */
    public function sampleContext(string $holderType = 'student'): array
    {
        $isStudent = $holderType === 'student';

        return [
            'school' => [
                'name' => 'Graceland College',
                'address' => '14 Awolowo Way, Ikeja, Lagos',
                'phone' => '+234 801 234 5678',
                'email' => 'info@graceland.example',
                'logo_url' => '',
                'subdomain' => 'graceland',
            ],
            'holder' => [
                'type' => $holderType,
                'name' => $isStudent ? 'Adaeze Nwosu' : 'Mr. Tunde Bakare',
                'initials' => $isStudent ? 'Adaeze Nwosu' : 'Tunde Bakare',
                'photo_url' => '',
                'gender' => $isStudent ? 'female' : 'male',
                'id_label' => $isStudent ? 'Admission No.' : 'Staff ID',
                'id_value' => $isStudent ? 'GC/2024/0187' : 'GC/STF/0042',
                'class' => $isStudent ? 'JSS 2' : '',
                'arm' => $isStudent ? 'Gold' : '',
                'designation' => $isStudent ? '' : 'Head of Mathematics',
                'admission_number' => $isStudent ? 'GC/2024/0187' : '',
                'staff_id' => $isStudent ? '' : 'GC/STF/0042',
            ],
            'card' => [
                'serial' => $isStudent ? 'GC/STU/2026/0187' : 'GC/STF/2026/0042',
                'issued_on' => '2026-09-14',
                'expires_on' => '2027-07-30',
                'session' => '2026/2027',
                'status' => 'active',
            ],
            'verification' => [
                'token' => 'SAMPLE-TOKEN-0000',
                'verify_url' => 'https://example.test/api/v1/verify-id/SAMPLE-TOKEN-0000',
                'qr_code_url' => '',
            ],
            'emergency' => ['phone' => '+234 801 234 5678'],
            'generated_at' => '2026-09-14',
        ];
    }

    // ------------------------------------------------------------------
    // Shipped default design
    // ------------------------------------------------------------------

    /**
     * The default card face.
     *
     * A school that imports nothing still gets a correct, printable card, and
     * this doubles as the worked example the contract document points at. The
     * layout is deliberately conservative: one photo, the name large enough to
     * read across a gate, the identifier, and the QR. Everything a Nigerian
     * school ID card is expected to carry and nothing it isn't.
     */
    public function defaultFront(string $holderType = 'student'): string
    {
        return <<<'TPL'
<div class="card face">
  <div class="band">
    {{#if school.logo_url}}<img class="crest" src="{{ school.logo_url }}" alt="">{{/if}}
    <div class="band-text">
      <div class="school-name">{{ school.name | upper }}</div>
      <div class="school-line">{{ school.address }}</div>
    </div>
  </div>

  <div class="body">
    <div class="photo-frame">
      {{#if holder.photo_url}}
      <img class="photo" src="{{ holder.photo_url }}" alt="">
      {{else}}
      <div class="photo-missing">PHOTO</div>
      {{/if}}
    </div>

    <div class="details">
      <div class="holder-name">{{ holder.name | upper }}</div>
      {{#if holder.class}}<div class="holder-role">{{ holder.class }} {{ holder.arm }}</div>{{/if}}
      {{#if holder.designation}}<div class="holder-role">{{ holder.designation }}</div>{{/if}}
      <table class="fields">
        <tr><th>{{ holder.id_label }}</th><td>{{ holder.id_value | default:"—" }}</td></tr>
        <tr><th>Session</th><td>{{ card.session | default:"—" }}</td></tr>
        <tr><th>Valid to</th><td>{{ card.expires_on | date:"M Y" | default:"until revoked" }}</td></tr>
      </table>
    </div>

    <div class="qr-frame">
      {{#if verification.qr_code_url}}
      <img class="qr" src="{{ verification.qr_code_url }}" alt="">
      {{/if}}
      <div class="serial">{{ card.serial }}</div>
    </div>
  </div>
</div>
TPL;
    }

    /**
     * The default reverse.
     *
     * The back is where the honest disclaimer goes. This card is not a key and
     * does not open anything (doc §3); saying so in print is what stops a
     * school treating it as one, and stops a finder assuming they are holding
     * something valuable.
     */
    public function defaultBack(string $holderType = 'student'): string
    {
        return <<<'TPL'
<div class="card back">
  <div class="back-title">{{ school.name | upper }}</div>

  <div class="conditions">
    <p>This card remains the property of the school and must be returned on request.</p>
    <p>It confirms identity only. It is not a key, a payment card, or an access pass.</p>
    <p>If found, please return it to the school or call the number below.</p>
  </div>

  <table class="back-fields">
    <tr><th>Holder</th><td>{{ holder.name }}</td></tr>
    <tr><th>{{ holder.id_label }}</th><td>{{ holder.id_value | default:"—" }}</td></tr>
    {{#if holder.blood_group}}<tr><th>Blood group</th><td>{{ holder.blood_group }}</td></tr>{{/if}}
    <tr><th>Issued</th><td>{{ card.issued_on | date:"j M Y" }}</td></tr>
    <tr><th>Serial</th><td>{{ card.serial }}</td></tr>
  </table>

  <div class="back-foot">
    <div class="contact">
      <div>{{ school.phone }}</div>
      <div>{{ school.email }}</div>
    </div>
    <div class="verify">
      {{#if verification.qr_code_url}}<img class="qr-small" src="{{ verification.qr_code_url }}" alt="">{{/if}}
      <div class="verify-note">Scan to check this card is current</div>
    </div>
  </div>
</div>
TPL;
    }

    /**
     * Default stylesheet.
     *
     * Written in millimetres throughout. dompdf resolves mm reliably and a
     * card is a physical object — a photo window specified in pixels stops
     * being 22mm wide the moment the sheet DPI changes.
     */
    public function defaultStyles(string $holderType = 'student'): string
    {
        $accent = $holderType === 'staff' ? '#1f3a5f' : '#0b6b4f';

        return <<<CSS
.card { width: 100%; height: 100%; font-family: DejaVu Sans, sans-serif; color: #12141a; background: #ffffff; overflow: hidden; }
.card .band { background: {$accent}; color: #ffffff; padding: 1.6mm 2.4mm; }
.card .crest { width: 7mm; height: 7mm; object-fit: contain; vertical-align: middle; margin-right: 1.6mm; }
.card .band-text { display: inline-block; vertical-align: middle; }
.card .school-name { font-size: 7pt; font-weight: bold; letter-spacing: 0.3pt; line-height: 1.15; }
.card .school-line { font-size: 4.4pt; opacity: 0.85; line-height: 1.2; }
.card .body { padding: 2mm 2.4mm; }
.card .photo-frame { float: left; width: 22mm; margin-right: 2.4mm; }
.card .photo { width: 22mm; height: 28mm; object-fit: cover; border: 0.3mm solid {$accent}; }
.card .photo-missing { width: 22mm; height: 28mm; border: 0.3mm dashed #9aa4b2; color: #9aa4b2; font-size: 5pt; text-align: center; line-height: 28mm; }
.card .details { float: left; width: 36mm; }
.card .holder-name { font-size: 8pt; font-weight: bold; line-height: 1.1; }
.card .holder-role { font-size: 5.6pt; color: {$accent}; font-weight: bold; margin-bottom: 1mm; }
.card .fields { width: 100%; border-collapse: collapse; font-size: 4.8pt; }
.card .fields th { text-align: left; color: #667085; font-weight: normal; padding: 0.2mm 0; width: 12mm; }
.card .fields td { padding: 0.2mm 0; font-weight: bold; }
.card .qr-frame { float: right; width: 17mm; text-align: center; }
.card .qr { width: 15mm; height: 15mm; }
.card .serial { font-size: 3.8pt; color: #667085; margin-top: 0.6mm; word-wrap: break-word; }
.card.back { padding: 2.4mm; }
.card .back-title { font-size: 5.6pt; font-weight: bold; color: {$accent}; border-bottom: 0.3mm solid {$accent}; padding-bottom: 0.8mm; }
.card .conditions { font-size: 4pt; color: #475467; line-height: 1.35; margin: 1.4mm 0; }
.card .conditions p { margin: 0 0 0.6mm; }
.card .back-fields { width: 100%; border-collapse: collapse; font-size: 4.4pt; }
.card .back-fields th { text-align: left; color: #667085; font-weight: normal; width: 16mm; padding: 0.3mm 0; }
.card .back-fields td { font-weight: bold; padding: 0.3mm 0; }
.card .back-foot { margin-top: 1.6mm; }
.card .contact { float: left; font-size: 4.2pt; color: #475467; line-height: 1.3; }
.card .verify { float: right; text-align: center; }
.card .qr-small { width: 11mm; height: 11mm; }
.card .verify-note { font-size: 3.4pt; color: #667085; }
CSS;
    }
}
