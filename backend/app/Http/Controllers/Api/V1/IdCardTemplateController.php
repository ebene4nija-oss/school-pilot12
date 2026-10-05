<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IdCard;
use App\Models\IdCardTemplate;
use App\Services\IdCard\CardImpositionService;
use App\Services\IdCard\CardBackgroundService;
use App\Services\IdCard\CardLayoutCompiler;
use App\Services\IdCard\IdCardGallery;
use App\Services\IdCard\IdCardTemplateService;
use App\Services\ReportCard\TemplateException;
use Illuminate\Http\Request;

/**
 * Per-school ID card design management.
 *
 * The flow an admin follows is the report card's, deliberately: read the
 * contract, code a bundle, import it (lands as a draft), preview it against
 * sample data, then activate it. Importing never changes what a school is
 * currently printing — activation is a separate, explicit step, because the
 * middle of a print week is not when a design should change under somebody.
 */
class IdCardTemplateController extends Controller
{
    public function __construct(
        private IdCardTemplateService $templates,
        private CardImpositionService $imposition
    ) {
    }

    private function getSchoolId(Request $request)
    {
        /*
         * Tenant first — see Controller::schoolId(), which prefers the resolved
         * tenant, falls back to the acting user's own profile, and returns null
         * for a request that is explicitly unscoped.
         *
         * The guard here used to test the user's profile *first*. A platform
         * operator has no school on their profile, so for them the guard was
         * always false and this fell through to `tenant_school` — the school
         * resolved from the host, which for an operator is the internal `admin`
         * subdomain they signed in through, not the tenant they are inspecting.
         * These endpoints therefore answered for the wrong school entirely.
         */
        $schoolId = $this->schoolId();

        if ($schoolId !== null) {
            return $schoolId;
        }

        // Host-resolved tenant, for routes reached without an authenticated
        // profile at all.
        $tenant = $request->attributes->get('tenant_school');

        return $tenant ? $tenant->id : $request->attributes->get('school_id');
    }

    public function index(Request $request)
    {
        $query = IdCardTemplate::withoutGlobalScopes()
            ->where('school_id', $this->getSchoolId($request));

        if ($request->filled('holder_type')) {
            $query->where('holder_type', $request->get('holder_type'));
        }

        $templates = $query->orderByDesc('id')->get([
            'id', 'name', 'slug', 'version', 'description', 'engine', 'holder_type',
            'card_geometry', 'regions', 'status', 'validation_report', 'checksum', 'imported_at',
        ]);

        return response()->json([
            'engine' => IdCardTemplate::ENGINE,
            'templates' => $templates,
            // One active design per holder type, so the client can show a
            // school which of its two designs is live without filtering.
            'active' => $templates->where('status', 'active')
                ->mapWithKeys(fn ($template) => [$template->holder_type => $template->id]),
        ]);
    }

    /**
     * Import a bundle, as a JSON body or an uploaded .json file.
     */
    public function import(Request $request)
    {
        if ($request->hasFile('bundle')) {
            $file = $request->file('bundle');

            if ($file->getSize() > 2 * 1024 * 1024) {
                return response()->json(['error' => 'Template bundle exceeds 2 MB.'], 422);
            }

            $bundle = json_decode(file_get_contents($file->getRealPath()), true);

            if (!is_array($bundle)) {
                return response()->json([
                    'error' => 'Bundle file is not valid JSON: ' . json_last_error_msg(),
                ], 422);
            }
        } else {
            $bundle = $request->input('bundle', $request->all());
        }

        try {
            $template = $this->templates->import($bundle, $this->getSchoolId($request), $request->user()?->id);
        } catch (TemplateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Design imported as a draft. Preview it, then activate it when you are satisfied.',
            'template' => $template,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        return response()->json(['template' => $this->findTemplate($request, $id)]);
    }

    /**
     * Render a design as HTML.
     *
     * Always against fictional sample data. Unlike a report card preview,
     * there is no real-holder mode: the only thing a real record adds to a
     * card preview is a real child's face on a designer's screen while they
     * nudge a margin, which is not a trade worth making.
     *
     * `?view=sheet` shows the imposed sheet instead of a single card — the
     * more useful preview of the two, because it is the sheet that either
     * fits the paper or doesn't.
     */
    public function preview(Request $request, $id)
    {
        $template = $this->findTemplate($request, $id);
        $context = $this->templates->sampleContext($template->holder_type);

        try {
            $sides = $this->templates->renderSides($template, $context);
            $styles = $this->templates->stylesFor($template, $template->holder_type);
            $geometry = $this->templates->geometryFor($template);

            if ($request->get('view') === 'sheet') {
                $copies = min(60, max(1, (int) $request->get('copies', $this->imposition->plan($geometry, $request->all())['per_sheet'])));

                $imposed = $this->imposition->impose(
                    array_fill(0, $copies, $sides),
                    $geometry,
                    $styles,
                    $request->all()
                );

                return response($imposed['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }

            $html = $this->singleCardDocument($sides, $styles, $geometry);
        } catch (TemplateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if ($request->get('format') === 'json') {
            return response()->json(['html' => $html]);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Make a draft the live design for its holder type.
     */
    public function activate(Request $request, $id)
    {
        $template = $this->findTemplate($request, $id);

        // Render once against sample data first: activation makes this the
        // design every card is printed from, so "it still renders" should be a
        // fact at that moment, not an assumption from import time.
        try {
            $this->templates->renderSides($template, $this->templates->sampleContext($template->holder_type));
            $this->imposition->plan($this->templates->geometryFor($template));
        } catch (TemplateException $e) {
            return response()->json([
                'error' => 'Design failed to render and cannot be activated: ' . $e->getMessage(),
            ], 422);
        }

        $template->activate();

        return response()->json([
            'message' => "\"{$template->name}\" v{$template->version} is now this school's {$template->holder_type} ID card design.",
            'template' => $template->fresh(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $template = $this->findTemplate($request, $id);

        if ($template->status === 'active') {
            return response()->json([
                'error' => 'Cannot delete the active design. Activate another design first.',
            ], 422);
        }

        /*
         * Cards printed from this design keep working: they carry their own
         * `template_checksum`, and verification reads the card row, not the
         * design. Deleting a design retires it from future runs and nothing
         * more.
         */
        $template->delete();

        return response()->json(['message' => 'Design deleted. Cards already issued from it are unaffected.']);
    }

    // ------------------------------------------------------------------
    // Gallery: ready-made designs in the school's own colours (TASK 18)
    // ------------------------------------------------------------------

    /** The six designs, and which of them this school already uses. */
    public function gallery(Request $request)
    {
        $schoolId = $this->getSchoolId($request);
        $mine = IdCardTemplate::withoutGlobalScopes()->where('school_id', $schoolId)
            ->where('slug', 'like', IdCardGallery::SLUG_PREFIX.'%')
            ->orderByDesc('version')->get(['id', 'slug', 'status', 'version', 'regions']);

        return response()->json([
            'designs' => array_map(function (array $d) use ($mine) {
                $latest = $mine->firstWhere('slug', IdCardGallery::SLUG_PREFIX.$d['key']);
                $colours = $latest?->regions[0] ?? [];

                return $d + [
                    // The school's last choice of colours, so a redesign starts from them.
                    'school_primary' => $colours['primary'] ?? null,
                    'school_accent' => $colours['accent'] ?? null,
                    'template_id' => $latest?->id,
                    'live' => $latest?->status === 'active',
                ];
            }, app(IdCardGallery::class)->list()),
        ]);
    }

    /**
     * A gallery design in chosen colours, with this school's name, crest and
     * contact details, a working sample QR code, and a fictional holder — no
     * real child's face while choosing colours. `long_name=1` tries the
     * longest names a school is likely to have.
     */
    public function galleryPreview(Request $request, string $key)
    {
        $schoolId = $this->getSchoolId($request);

        try {
            $bundle = app(IdCardGallery::class)->bundle($key, $request->query('primary'), $request->query('accent'));
            $template = new IdCardTemplate([
                'holder_type' => $bundle['holder_type'],
                'front' => $bundle['front'],
                'back' => $bundle['back'],
                'styles' => $bundle['styles'],
                'card_geometry' => $this->templates->normaliseGeometry($bundle['card']),
            ]);

            $context = $this->previewContext((int) $schoolId, $bundle['holder_type'], $request->boolean('long_name'));

            $html = $this->singleCardDocument(
                $this->templates->renderSides($template, $context),
                $this->templates->stylesFor($template, $bundle['holder_type']),
                $this->templates->geometryFor($template),
            );
        } catch (TemplateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if ($request->get('format') === 'json') {
            return response()->json(['html' => $html]);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Use a gallery design: imported as this school's own draft (a new
     * version each time colours change), and made live straight away when
     * asked. Cards already printed keep their own design.
     */
    public function galleryUse(Request $request, string $key)
    {
        $data = $request->validate([
            'primary' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'activate' => 'boolean',
        ]);
        $schoolId = (int) $this->getSchoolId($request);

        try {
            $template = $this->templates->import(
                app(IdCardGallery::class)->bundle($key, $data['primary'] ?? null, $data['accent'] ?? null),
                $schoolId,
                $request->user()?->id,
            );
            if ($data['activate'] ?? false) {
                $this->templates->renderSides($template, $this->templates->sampleContext($template->holder_type));
                $this->imposition->plan($this->templates->geometryFor($template));
                $template->activate();
            }
        } catch (TemplateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        \App\Models\AuditLog::create([
            'school_id' => $schoolId, 'user_id' => $request->user()?->id, 'action' => 'id_card.gallery_design_used',
            'auditable_type' => IdCardTemplate::class, 'auditable_id' => $template->id,
            'new_values' => ['design' => $key, 'version' => $template->version, 'activated' => (bool) ($data['activate'] ?? false)],
        ]);

        return response()->json([
            'message' => ($data['activate'] ?? false)
                ? "\"{$template->name}\" is now your {$template->holder_type} ID card design. Cards already printed are unchanged."
                : "\"{$template->name}\" saved as a draft. Make it live when you are ready.",
            'template' => $template->fresh(),
        ], 201);
    }

    /**
     * Sample data for choosing a design: this school's name, crest and contact
     * details, a working sample QR code, and a fictional holder — never a real
     * child's face. Optionally the longest names a school is likely to have.
     */
    private function previewContext(int $schoolId, string $holderType, bool $longName): array
    {
        $context = $this->templates->sampleContext($holderType);
        $school = \App\Models\School::find($schoolId);
        $context['school'] = array_merge($context['school'], array_filter([
            'name' => $school?->name, 'address' => $school?->address, 'phone' => $school?->phone, 'email' => $school?->email,
            'logo_url' => app(\App\Services\IdCard\IdCardPrintService::class)->schoolLogo($schoolId),
        ]));
        $context['verification']['qr_code_url'] = app(\App\Services\QrCodeService::class)->dataUriFor($context['verification']['verify_url'], 4) ?? '';
        if ($longName) {
            $name = $holderType === 'staff' ? 'Mrs. Oluwaseyi Chukwuemeka-Adebayo' : 'Oluwadamilare Chukwuemeka Adebayo-Okonkwo';
            $context['holder']['name'] = $name;
            $context['holder']['name_fit'] = IdCardGallery::nameFit($name);
        }

        return $context;
    }

    // ------------------------------------------------------------------
    // Designer: the school's own artwork with live fields placed on it
    // ------------------------------------------------------------------

    /** What can be placed on a card, with sample values to show while designing. */
    public function designerFields()
    {
        $sample = ['student' => $this->templates->sampleContext('student'), 'staff' => $this->templates->sampleContext('staff')];

        return response()->json([
            'fields' => collect(CardLayoutCompiler::FIELDS)->map(fn ($f, $key) => [
                'key' => $key,
                'label' => $f[0],
                'caption' => $f[2] === '{{ holder.id_label }}' ? null : $f[2],
                'for' => $f[3],
                'sample' => [
                    'student' => $this->sampleValue($sample['student'], $key),
                    'staff' => $this->sampleValue($sample['staff'], $key),
                ],
            ])->values(),
            'card_mm' => ['landscape' => [85.6, 54], 'portrait' => [54, 85.6]],
            'print_px' => CardBackgroundService::PRINT_PX,
        ]);
    }

    private function sampleValue(array $context, string $key): string
    {
        [$group, $field] = explode('.', $key);
        if ($key === 'holder.class') {
            return trim(($context['holder']['class'] ?? '').' '.($context['holder']['arm'] ?? ''));
        }

        return (string) ($context[$group][$field] ?? '');
    }

    /** Upload artwork for one side. The server redraws it; the original is not kept. */
    public function uploadBackground(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:png,jpg,jpeg|max:6144',
            'orientation' => 'required|in:landscape,portrait',
        ]);

        try {
            $stored = app(CardBackgroundService::class)->store($request->file('image'), (int) $this->getSchoolId($request), $request->input('orientation'));
        } catch (TemplateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($stored, 201);
    }

    /** An uploaded background, for the designer to draw under the fields. */
    public function background(Request $request, string $ref)
    {
        $bytes = app(CardBackgroundService::class)->bytes((int) $this->getSchoolId($request), $ref);
        abort_if($bytes === null, 404);

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** The layout as it will print, rendered by the same engine as a print run's cards. */
    public function designerPreview(Request $request)
    {
        $data = $this->designerInput($request);
        $schoolId = (int) $this->getSchoolId($request);

        try {
            $compiled = app(CardLayoutCompiler::class)->compile($data['layout'], $data['name'] ?? 'Draft', $data['holder_type'], $this->assetsFor($schoolId, $data));
            $bundle = $compiled['bundle'];
            $template = new IdCardTemplate([
                'school_id' => $schoolId,
                'holder_type' => $bundle['holder_type'],
                'front' => $bundle['front'],
                'back' => $bundle['back'],
                'styles' => $this->sanitizerCss($bundle['styles']),
                'card_geometry' => $this->templates->normaliseGeometry($bundle['card']),
                'assets' => $this->assetsFor($schoolId, $data),
            ]);
            $html = $this->singleCardDocument(
                $this->templates->renderSides($template, $this->previewContext($schoolId, $bundle['holder_type'], $request->boolean('long_name'))),
                $this->templates->stylesFor($template, $bundle['holder_type']),
                $this->templates->geometryFor($template),
            );
        } catch (TemplateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['html' => $html, 'warnings' => $compiled['warnings']]);
    }

    /**
     * Save the layout as a design: a draft, or live at once. Saving again
     * under the same name makes a new version; cards already printed keep
     * the design they were printed with.
     */
    public function designerSave(Request $request)
    {
        $data = $this->designerInput($request, true);
        $schoolId = (int) $this->getSchoolId($request);

        try {
            $compiled = app(CardLayoutCompiler::class)->compile($data['layout'], $data['name'], $data['holder_type'], $this->assetsFor($schoolId, $data));
            $template = $this->templates->import(
                $compiled['bundle'] + ['slug' => 'designer-'.\Illuminate\Support\Str::slug($data['name']), 'assets' => $this->assetsFor($schoolId, $data)],
                $schoolId,
                $request->user()?->id,
            );
            if ($compiled['warnings'] !== []) {
                $report = $template->validation_report;
                $report['warnings'] = array_values(array_unique(array_merge($report['warnings'] ?? [], $compiled['warnings'])));
                $template->update(['validation_report' => $report]);
            }
            if ($data['activate'] ?? false) {
                $this->templates->renderSides($template, $this->templates->sampleContext($template->holder_type));
                $this->imposition->plan($this->templates->geometryFor($template));
                $template->activate();
            }
        } catch (TemplateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        \App\Models\AuditLog::create([
            'school_id' => $schoolId, 'user_id' => $request->user()?->id, 'action' => 'id_card.designer_saved',
            'auditable_type' => IdCardTemplate::class, 'auditable_id' => $template->id,
            'new_values' => ['name' => $template->name, 'version' => $template->version, 'activated' => (bool) ($data['activate'] ?? false)],
        ]);

        return response()->json([
            'message' => ($data['activate'] ?? false)
                ? "\"{$template->name}\" is now your {$template->holder_type} ID card design. Cards already printed are unchanged."
                : "\"{$template->name}\" v{$template->version} saved as a draft.",
            'template' => $template->fresh(),
            'warnings' => $compiled['warnings'],
        ], 201);
    }

    private function designerInput(Request $request, bool $saving = false): array
    {
        $data = $request->validate([
            'name' => ($saving ? 'required' : 'nullable').'|string|min:2|max:80',
            'holder_type' => 'required|in:student,staff',
            'layout' => 'required|array',
            'layout.orientation' => 'required|in:landscape,portrait',
            'assets' => 'nullable|array',
            'assets.front_background' => 'nullable|string|size:32',
            'assets.back_background' => 'nullable|string|size:32',
            'activate' => 'boolean',
        ]);

        // validate() keeps only the nested keys it names; the compiler checks
        // every element itself, so the whole layout is passed on.
        $data['layout'] = (array) $request->input('layout');

        return $data;
    }

    /** @throws TemplateException */
    private function assetsFor(int $schoolId, array $data): array
    {
        $assets = array_filter((array) ($data['assets'] ?? []));
        foreach ($assets as $ref) {
            if (! app(CardBackgroundService::class)->exists($schoolId, (string) $ref)) {
                throw new TemplateException('A background image for this design is missing. Upload it again.');
            }
        }

        return $assets;
    }

    private function sanitizerCss(string $css): string
    {
        return app(\App\Services\ReportCard\HtmlSanitizer::class)->sanitizeCss($css, IdCardTemplateService::MAX_STYLES_BYTES)['css'];
    }

    /**
     * The contract, served from the API so a designer building against a
     * particular deployment gets that deployment's vocabulary rather than a
     * document that may have drifted from it.
     */
    public function contract()
    {
        return response()->json([
            'engine' => IdCardTemplate::ENGINE,
            'documentation' => '/docs/id-card-template-contract.md',
            'limits' => [
                'max_side_bytes' => IdCardTemplateService::MAX_SIDE_BYTES,
                'max_styles_bytes' => IdCardTemplateService::MAX_STYLES_BYTES,
            ],
            'holder_types' => IdCardTemplate::HOLDER_TYPES,
            'card_sizes' => IdCardTemplateService::CARD_SIZES,
            'sheet_sizes' => CardImpositionService::SHEET_SIZES,
            'revocation_reasons' => IdCard::REVOCATION_REASONS,
            'sample_context' => [
                'student' => $this->templates->sampleContext('student'),
                'staff' => $this->templates->sampleContext('staff'),
            ],
            'default_design' => [
                'student' => [
                    'front' => $this->templates->defaultFront('student'),
                    'back' => $this->templates->defaultBack('student'),
                    'styles' => $this->templates->defaultStyles('student'),
                ],
                'staff' => [
                    'front' => $this->templates->defaultFront('staff'),
                    'back' => $this->templates->defaultBack('staff'),
                    'styles' => $this->templates->defaultStyles('staff'),
                ],
            ],
        ]);
    }

    /**
     * Both sides of one card, side by side, at true size on screen.
     */
    private function singleCardDocument(array $sides, string $styles, array $geometry): string
    {
        $cards = sprintf(
            '<div class="preview-slot">%s</div>',
            $sides['front']
        );

        if (!empty($sides['back'])) {
            $cards .= sprintf('<div class="preview-slot">%s</div>', $sides['back']);
        }

        $radius = $geometry['corner_radius_mm'] > 0
            ? sprintf('border-radius:%.2fmm;', $geometry['corner_radius_mm'])
            : '';

        return sprintf(
            <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ID card preview</title>
<style>
body { margin: 0; padding: 8mm; background: #eef1f5; font-family: sans-serif; }
.preview-slot {
  width: %.3fmm;
  height: %.3fmm;
  overflow: hidden;
  background: #fff;
  margin: 0 6mm 6mm 0;
  display: inline-block;
  vertical-align: top;
  box-shadow: 0 1px 4px rgba(16,24,40,.18);
  %s
}
%s
</style>
</head>
<body>
%s
</body>
</html>
HTML,
            $geometry['width_mm'],
            $geometry['height_mm'],
            $radius,
            $styles,
            $cards
        );
    }

    private function findTemplate(Request $request, $id): IdCardTemplate
    {
        return IdCardTemplate::withoutGlobalScopes()
            ->where('school_id', $this->getSchoolId($request))
            ->findOrFail($id);
    }
}
