<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IdCard;
use App\Models\IdCardTemplate;
use App\Services\IdCard\CardImpositionService;
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
        if ($request->user() && $request->user()->userProfile && $request->user()->userProfile->school_id) {
            return $request->user()->userProfile->school_id;
        }

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
