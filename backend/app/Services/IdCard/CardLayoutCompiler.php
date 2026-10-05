<?php

namespace App\Services\IdCard;

use App\Models\IdCardTemplate;
use App\Services\ReportCard\TemplateException;

/**
 * Turns a layout built in the drag-and-drop designer into an ordinary design
 * bundle (TASK 18), so it is imported, sanitised, previewed, printed and
 * verified like every other design.
 *
 * A layout is the card's size and orientation plus, per side, an optional
 * uploaded background and a list of elements placed in millimetres from the
 * top-left corner: live fields (name, class, admission number…), the photo,
 * the QR code, the crest, free text and coloured boxes.
 *
 * Every element compiles to a table pinned from the top-left. Those are the
 * constructs dompdf draws reliably, images included (it drops images in a
 * pinned <div> and misplaces anything pinned from the bottom). Colour boxes
 * hold no image and are plain blocks, so a thin rule prints thin. The school
 * never writes markup, so free text is escaped and may not contain template
 * braces: a layout can only show the data offered here.
 */
class CardLayoutCompiler
{
    /**
     * The live fields a school can place. Only data SchoolPilot already holds
     * for a card (doc §12) — nothing here adds a personal-data field.
     *
     * key => [label shown in the designer, template expression, caption, holder type or null for both]
     */
    public const FIELDS = [
        'holder.name' => ['Name', '{{ holder.name }}', null, null],
        'holder.id_value' => ['Admission / staff number', '{{ holder.id_value | default:"—" }}', '{{ holder.id_label }}', null],
        'holder.class' => ['Class and arm', '{{ holder.class }} {{ holder.arm }}', 'Class', 'student'],
        'holder.designation' => ['Job title', '{{ holder.designation }}', 'Role', 'staff'],
        'card.session' => ['Session', '{{ card.session | default:"—" }}', 'Session', null],
        'card.expires_on' => ['Valid to', '{{ card.expires_on | date:"M Y" | default:"until revoked" }}', 'Valid to', null],
        'card.issued_on' => ['Issued on', '{{ card.issued_on | date:"j M Y" }}', 'Issued', null],
        'card.serial' => ['Card serial', '{{ card.serial }}', 'Serial', null],
        'school.name' => ['School name', '{{ school.name }}', null, null],
        'school.address' => ['School address', '{{ school.address }}', null, null],
        'school.phone' => ['School phone', '{{ school.phone }}', null, null],
        'school.email' => ['School email', '{{ school.email }}', null, null],
    ];

    public const TYPES = ['field', 'text', 'photo', 'qr', 'logo', 'box'];

    private const MAX_ELEMENTS_PER_SIDE = 40;
    private const MIN_QR_MM = 12.0;
    private const SAFE_EDGE_MM = 2.0;

    /**
     * @param  array  $layout  {orientation, front: {background?, background_colour?, elements: []}, back: {...}}
     * @return array{bundle: array, warnings: array<int,string>}
     *
     * @throws TemplateException with a message an administrator can act on
     */
    public function compile(array $layout, string $name, string $holderType, array $assets = []): array
    {
        $orientation = ($layout['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
        [$width, $height] = $orientation === 'portrait' ? [54.0, 85.6] : [85.6, 54.0];
        $warnings = [];
        $css = [];
        $sides = [];
        $hasName = false;
        $hasQr = false;

        foreach (['front', 'back'] as $side) {
            $spec = (array) ($layout[$side] ?? []);
            $elements = array_values((array) ($spec['elements'] ?? []));
            $hasBackground = ! empty($assets["{$side}_background"]);

            if ($side === 'back' && $elements === [] && ! $hasBackground) {
                continue; // single-sided card
            }
            if (count($elements) > self::MAX_ELEMENTS_PER_SIDE) {
                throw new TemplateException("The {$side} has more than ".self::MAX_ELEMENTS_PER_SIDE.' items. Remove some.');
            }

            $colour = $this->colour($spec['background_colour'] ?? '#ffffff', "{$side} background colour");
            $css[] = ".card.side-{$side} { position: relative; width: 100%; height: 100%; overflow: hidden; background-color: {$colour}; font-family: DejaVu Sans, sans-serif; color: #12141a; }";
            $css[] = ".card.side-{$side} .el { position: absolute; border-collapse: collapse; }";
            $css[] = ".card.side-{$side} .el td { padding: 0; vertical-align: top; word-wrap: break-word; }";

            $markup = '';
            foreach ($elements as $i => $el) {
                $el = (array) $el;
                $type = (string) ($el['type'] ?? '');
                if (! in_array($type, self::TYPES, true)) {
                    throw new TemplateException("Item ".($i + 1)." on the {$side} is not something a card can show.");
                }
                $cls = "e-{$side}-{$i}";
                [$x, $y, $w, $h] = $this->box($el, $width, $height, $type === 'box' ? 0.3 : 2.0);
                $label = $this->describe($el);

                if ($type === 'qr') {
                    $s = max(self::MIN_QR_MM, min($w, $h));
                    [$w, $h] = [$s, $s];
                    if ($x + $w > $width) {
                        $x = $width - $w;
                    }
                    if ($y + $h > $height) {
                        $y = $height - $h;
                    }
                    $hasQr = true;
                }
                if ($type !== 'box' && ($x < self::SAFE_EDGE_MM || $y < self::SAFE_EDGE_MM || $x + $w > $width - self::SAFE_EDGE_MM || $y + $h > $height - self::SAFE_EDGE_MM)) {
                    $warnings[] = ucfirst($side).": {$label} is within ".self::SAFE_EDGE_MM.'mm of the edge and may be trimmed when cards are cut.';
                }

                $css[] = sprintf('.card.side-%s .%s { left: %.2fmm; top: %.2fmm; width: %.2fmm; height: %.2fmm; }', $side, $cls, $x, $y, $w, $h);

                [$inner, $extraClass, $rules] = $this->element($type, $el, $side, $cls, $w, $h, $holderType, $warnings, $label);
                foreach ($rules as $rule) {
                    $css[] = $rule;
                }
                if ($type === 'field' && ($el['field'] ?? '') === 'holder.name') {
                    $hasName = true;
                }

                $markup .= $type === 'box'
                    ? "  <div class=\"el {$cls}\"></div>\n"
                    : "  <table class=\"el {$cls}{$extraClass}\"><tr><td>{$inner}</td></tr></table>\n";
            }

            $sides[$side] = "<div class=\"card side-{$side}\">\n{$markup}</div>";
        }

        if (! $hasName) {
            throw new TemplateException('Add the holder\'s name to the card (Fields → Name). A card with no name identifies nobody.');
        }
        if (! $hasQr) {
            throw new TemplateException('Add the QR code to the front or back. It is how a card is checked against the school\'s records.');
        }

        return [
            'bundle' => [
                'engine' => IdCardTemplate::ENGINE,
                'name' => $name,
                'holder_type' => $holderType,
                'front' => $sides['front'],
                'back' => $sides['back'] ?? '',
                'styles' => implode("\n", $css),
                'card' => ['size' => 'CR80', 'orientation' => $orientation, 'corner_radius_mm' => 3],
                'regions' => [['designer' => $layout]],
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** Position and size in mm, kept on the card. */
    private function box(array $el, float $width, float $height, float $min): array
    {
        $num = fn ($v, $d) => is_numeric($v) ? (float) $v : $d;
        $w = min($width, max($min, $num($el['w'] ?? null, 20)));
        $h = min($height, max($min, $num($el['h'] ?? null, 6)));
        $x = min($width - $w, max(0.0, $num($el['x'] ?? null, 0)));
        $y = min($height - $h, max(0.0, $num($el['y'] ?? null, 0)));

        return [round($x, 2), round($y, 2), round($w, 2), round($h, 2)];
    }

    /** @return array{0:string,1:string,2:array<int,string>} inner markup, extra class, CSS rules */
    private function element(string $type, array $el, string $side, string $cls, float $w, float $h, string $holderType, array &$warnings, string $label): array
    {
        $sel = ".card.side-{$side} .{$cls}";

        switch ($type) {
            case 'photo':
                return [
                    '{{#if holder.photo_url}}<img src="{{ holder.photo_url }}" alt="" style="width:'.$w.'mm;height:'.$h.'mm">{{else}}<div class="ph">PHOTO</div>{{/if}}',
                    '',
                    // Padding centres the word; line-height does not in dompdf.
                    ["{$sel} .ph { width: {$w}mm; padding-top: ".round(max(0, $h / 2 - 1), 2).'mm; height: '.round(max(1, $h / 2 + 1), 2)."mm; border: 0.3mm dashed #98a2b3; background: #f9fafb; color: #98a2b3; font-size: 5pt; text-align: center; }"],
                ];
            case 'qr':
                return [
                    '{{#if verification.qr_code_url}}<img src="{{ verification.qr_code_url }}" alt="" style="width:'.$w.'mm;height:'.$h.'mm">{{/if}}',
                    '',
                    // Always on white, whatever is behind it: a code on a coloured background does not scan.
                    ["{$sel} td { background: #ffffff; }"],
                ];
            case 'logo':
                return ['{{#if school.logo_url}}<img src="{{ school.logo_url }}" alt="" style="width:'.$w.'mm;height:'.$h.'mm">{{/if}}', '', []];
            case 'box':
                $colour = $this->colour($el['colour'] ?? '#0b6b4f', "{$label} colour");

                // A plain block, not a table: dompdf gives a table cell at least a
                // line of height, which turns a 0.6mm rule into a 2mm bar.
                return ['', '', ["{$sel} { background: {$colour}; }"]];
        }

        // field or text
        $size = is_numeric($el['font_size'] ?? null) ? (float) $el['font_size'] : 7.0;
        $size = round(min(24.0, max(4.0, $size)), 1);
        if ($size < 5) {
            $warnings[] = ucfirst($side).": {$label} is smaller than 5pt and may be hard to read.";
        }
        $colour = $this->colour($el['colour'] ?? '#12141a', "{$label} colour");
        $align = in_array($el['align'] ?? 'left', ['left', 'center', 'right'], true) ? $el['align'] ?? 'left' : 'left';
        $weight = ! empty($el['bold']) ? 'bold' : 'normal';
        $upper = ! empty($el['upper']);
        $rules = ["{$sel} td { width: {$w}mm; font-size: {$size}pt; line-height: 1.15; color: {$colour}; font-weight: {$weight}; text-align: {$align}; }"];
        $extra = '';

        if ($type === 'text') {
            $text = trim((string) ($el['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 160) {
                throw new TemplateException(ucfirst($side).': each text box needs between 1 and 160 characters.');
            }
            if (str_contains($text, '{{') || str_contains($text, '}}')) {
                throw new TemplateException(ucfirst($side).': text boxes cannot contain {{ or }}. Use Fields for live details.');
            }
            $out = htmlspecialchars($upper ? mb_strtoupper($text) : $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return [nl2br($out, false), $extra, $rules];
        }

        $key = (string) ($el['field'] ?? '');
        $field = self::FIELDS[$key] ?? throw new TemplateException(ucfirst($side).": \"{$key}\" is not a field a card can show.");
        if ($field[3] !== null && $field[3] !== $holderType) {
            $warnings[] = ucfirst($side).": {$field[0]} is blank on {$holderType} cards.";
        }
        // Filters chain left to right, so upper goes last in every expression.
        $expr = $upper ? str_replace(' }}', ' | upper }}', $field[1]) : $field[1];
        $caption = ! empty($el['show_caption']) && $field[2] !== null
            ? '<span class="cap">'.$field[2].'</span> '
            : '';
        if ($caption !== '') {
            $rules[] = "{$sel} .cap { font-weight: normal; opacity: 0.75; }";
        }

        if ($key === 'holder.name') {
            // A long name shrinks rather than overflows (the PDF engine cannot fit text).
            $extra = ' fit-{{ holder.name_fit }}';
            $rules[] = ".card.side-{$side} .{$cls}.fit-long td { font-size: ".round($size * 0.82, 1).'pt; }';
            $rules[] = ".card.side-{$side} .{$cls}.fit-very-long td { font-size: ".round($size * 0.68, 1).'pt; }';
        }

        return [$caption.$expr, $extra, $rules];
    }

    private function describe(array $el): string
    {
        return match ($el['type'] ?? '') {
            'field' => self::FIELDS[$el['field'] ?? '']['0'] ?? 'A field',
            'text' => 'a text box',
            'photo' => 'the photo',
            'qr' => 'the QR code',
            'logo' => 'the crest',
            'box' => 'a colour box',
            default => 'an item',
        };
    }

    /** @throws TemplateException */
    private function colour(mixed $value, string $what): string
    {
        $value = strtolower(trim((string) $value));
        if (! preg_match('/^#[0-9a-f]{6}$/', $value)) {
            throw new TemplateException("The {$what} must be a colour like #0b6b4f.");
        }

        return $value;
    }
}
