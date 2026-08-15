<?php

namespace App\Services\IdCard;

use App\Services\ReportCard\TemplateException;

/**
 * Lays rendered cards out on printable sheets.
 *
 * This is the part of the feature that makes the promise in doc §3 true. "A
 * generated, printable PDF, printed at any print shop or office printer the
 * school already uses" is not satisfied by a PDF of 400 pages each 85.6mm
 * wide — no school office printer takes card stock, and no bursar is feeding
 * paper through one card at a time. What a school can actually do is print
 * A4 sheets on the machine in the front office and cut them, so that is what
 * comes out of here: ten cards to a sheet, with cut guides, and — the part
 * that is easy to get wrong and ruins the whole run — backs that land on the
 * right fronts when the stack is turned over.
 *
 * The duplex mirroring is the reason this is a service and not twenty lines
 * in a Blade view. A duplex printer flipping on the long edge mirrors the
 * sheet horizontally, so the back of the card printed top-left comes out
 * top-right. Lay the backs out in reading order and every card in the run is
 * somebody else's, which is only discovered after the guillotine.
 */
class CardImpositionService
{
    /** Sheet stock, in millimetres, portrait. */
    public const SHEET_SIZES = [
        'A4' => ['width' => 210.0, 'height' => 297.0],
        'A3' => ['width' => 297.0, 'height' => 420.0],
        'LETTER' => ['width' => 215.9, 'height' => 279.4],
        'LEGAL' => ['width' => 215.9, 'height' => 355.6],
    ];

    /**
     * Almost no office printer images closer than 5mm to the paper edge, and
     * a card laid in that strip prints with a white bite out of it. Clamped
     * rather than warned about: the alternative is a wasted run.
     */
    public const MIN_SHEET_MARGIN_MM = 5.0;

    /** Length and standoff of a corner crop tick. */
    private const MARK_LENGTH_MM = 3.0;
    private const MARK_GAP_MM = 1.0;

    /**
     * Work out the grid before anything is rendered.
     *
     * Separated from impose() because the admin UI shows a school what it is
     * about to get — "42 cards, 5 sheets, 10 per sheet, double-sided" — and
     * that answer must not cost a full render to obtain.
     *
     * @return array{
     *     sheet: string, sheet_width_mm: float, sheet_height_mm: float, sheet_orientation: string,
     *     columns: int, rows: int, per_sheet: int, margin_mm: float, gutter_mm: float,
     *     offset_x_mm: float, offset_y_mm: float, card_width_mm: float, card_height_mm: float,
     *     cut_guides: string, duplex: string, duplex_offset_mm: array{x:float,y:float}
     * }
     * @throws TemplateException
     */
    public function plan(array $geometry, array $options = []): array
    {
        $sheetName = strtoupper((string) ($options['sheet'] ?? 'A4'));
        $sheet = self::SHEET_SIZES[$sheetName] ?? self::SHEET_SIZES['A4'];

        if (!isset(self::SHEET_SIZES[$sheetName])) {
            $sheetName = 'A4';
        }

        $orientation = strtolower((string) ($options['sheet_orientation'] ?? 'portrait'));

        if (!in_array($orientation, ['portrait', 'landscape'], true)) {
            $orientation = 'portrait';
        }

        $sheetWidth = $orientation === 'landscape' ? $sheet['height'] : $sheet['width'];
        $sheetHeight = $orientation === 'landscape' ? $sheet['width'] : $sheet['height'];

        $margin = max(self::MIN_SHEET_MARGIN_MM, (float) ($options['margin_mm'] ?? 10.0));
        $gutter = min(20.0, max(0.0, (float) ($options['gutter_mm'] ?? 0.0)));

        $cutGuides = strtolower((string) ($options['cut_guides'] ?? 'border'));

        if (!in_array($cutGuides, ['border', 'marks', 'none'], true)) {
            $cutGuides = 'border';
        }

        /*
         * Crop marks live outside the trim, so they need somewhere to live.
         * Rather than refuse the combination, open a gutter wide enough for
         * two marks to sit back to back — a school that asked for marks gets
         * marks, and gets told in the layout record that the gutter moved.
         */
        if ($cutGuides === 'marks') {
            $gutter = max($gutter, (self::MARK_LENGTH_MM + self::MARK_GAP_MM) * 2);
        }

        $cardWidth = (float) $geometry['width_mm'];
        $cardHeight = (float) $geometry['height_mm'];
        $bleed = (float) ($geometry['bleed_mm'] ?? 0.0);

        // Bleed is printed and then trimmed off, so it occupies sheet space on
        // every edge of every card.
        $slotWidth = $cardWidth + $bleed * 2;
        $slotHeight = $cardHeight + $bleed * 2;

        $usableWidth = $sheetWidth - $margin * 2;
        $usableHeight = $sheetHeight - $margin * 2;

        $columns = (int) floor(($usableWidth + $gutter) / ($slotWidth + $gutter));
        $rows = (int) floor(($usableHeight + $gutter) / ($slotHeight + $gutter));

        if ($columns < 1 || $rows < 1) {
            throw new TemplateException(sprintf(
                'A %.1f x %.1fmm card does not fit on %s %s with %.1fmm margins. Use a larger sheet, a smaller margin, or a smaller card.',
                $slotWidth,
                $slotHeight,
                $sheetName,
                $orientation,
                $margin
            ));
        }

        // Centre the block of cards in the usable area rather than pushing it
        // into the top-left corner: a centred grid survives the few
        // millimetres of feed skew an office printer introduces.
        $gridWidth = $columns * $slotWidth + ($columns - 1) * $gutter;
        $gridHeight = $rows * $slotHeight + ($rows - 1) * $gutter;

        $duplex = strtolower((string) ($options['duplex'] ?? 'long-edge'));

        if (!in_array($duplex, ['long-edge', 'short-edge', 'none'], true)) {
            $duplex = 'long-edge';
        }

        $duplexOffset = $options['duplex_offset_mm'] ?? [];

        return [
            'sheet' => $sheetName,
            'sheet_width_mm' => $sheetWidth,
            'sheet_height_mm' => $sheetHeight,
            'sheet_orientation' => $orientation,
            'columns' => $columns,
            'rows' => $rows,
            'per_sheet' => $columns * $rows,
            'margin_mm' => $margin,
            'gutter_mm' => $gutter,
            'offset_x_mm' => round($margin + ($usableWidth - $gridWidth) / 2, 3),
            'offset_y_mm' => round($margin + ($usableHeight - $gridHeight) / 2, 3),
            'card_width_mm' => $cardWidth,
            'card_height_mm' => $cardHeight,
            'slot_width_mm' => $slotWidth,
            'slot_height_mm' => $slotHeight,
            'bleed_mm' => $bleed,
            'corner_radius_mm' => (float) ($geometry['corner_radius_mm'] ?? 0.0),
            'cut_guides' => $cutGuides,
            'duplex' => $duplex,
            /*
             * Duplex registration drift. Every printer lays the second side
             * down a fraction out of true; a school that measures its own
             * offset once can correct every subsequent run instead of
             * accepting cards whose backs sit 2mm high.
             */
            'duplex_offset_mm' => [
                'x' => min(10.0, max(-10.0, (float) ($duplexOffset['x'] ?? 0))),
                'y' => min(10.0, max(-10.0, (float) ($duplexOffset['y'] ?? 0))),
            ],
        ];
    }

    /**
     * Build the complete print document.
     *
     * @param array<int,array{front:string,back:string|null}> $cards
     * @param string $cardCss the school's (sanitised) card stylesheet
     * @return array{html:string,sheet_count:int,plan:array}
     * @throws TemplateException
     */
    public function impose(array $cards, array $geometry, string $cardCss, array $options = []): array
    {
        $plan = $this->plan($geometry, $options);
        $cards = array_values($cards);

        if ($cards === []) {
            throw new TemplateException('There are no cards to print.');
        }

        $perSheet = $plan['per_sheet'];
        $doubleSided = $plan['duplex'] !== 'none'
            && collect($cards)->contains(fn (array $card) => trim((string) ($card['back'] ?? '')) !== '');

        $chunks = array_chunk($cards, $perSheet);
        $sheets = [];

        foreach ($chunks as $chunk) {
            // Pad to a full sheet so the back grid keeps its geometry: a
            // half-empty last sheet must still put each back opposite its own
            // front, which only works if the empty slots are real.
            $padded = array_pad($chunk, $perSheet, null);

            $sheets[] = $this->renderSheet(
                array_map(fn ($card) => $card === null ? null : $card['front'], $padded),
                $plan,
                'front'
            );

            if ($doubleSided) {
                $backs = array_map(fn ($card) => $card === null ? null : ($card['back'] ?? null), $padded);

                $sheets[] = $this->renderSheet(
                    $this->orderForDuplex($backs, $plan),
                    $plan,
                    'back'
                );
            }
        }

        return [
            'html' => $this->document($sheets, $plan, $cardCss),
            'sheet_count' => count($sheets),
            'plan' => $plan,
        ];
    }

    /**
     * Reorder a sheet's backs so they land on their own fronts after the flip.
     *
     * Long-edge (the default on essentially every duplex printer, and the
     * "flip on long edge" checkbox in a print dialog) turns the sheet about
     * its vertical axis: what was in column 0 comes back in the last column,
     * so each row is reversed. Short-edge turns it about the horizontal axis,
     * which reverses the rows and leaves the columns alone.
     *
     * @param array<int,string|null> $backs in front-sheet reading order
     * @return array<int,string|null> in back-sheet reading order
     */
    public function orderForDuplex(array $backs, array $plan): array
    {
        $columns = $plan['columns'];
        $rows = $plan['rows'];

        if ($plan['duplex'] === 'none') {
            return $backs;
        }

        $ordered = [];

        for ($row = 0; $row < $rows; $row++) {
            for ($column = 0; $column < $columns; $column++) {
                [$sourceRow, $sourceColumn] = $plan['duplex'] === 'long-edge'
                    ? [$row, $columns - 1 - $column]
                    : [$rows - 1 - $row, $column];

                $ordered[] = $backs[$sourceRow * $columns + $sourceColumn] ?? null;
            }
        }

        return $ordered;
    }

    /**
     * One sheet, as absolutely-positioned slots on a page-sized canvas.
     *
     * Absolute positioning rather than a table: imposition is a coordinate
     * problem, and a table's cell heights are advisory the moment content
     * overflows. A slot that is exactly 54mm tall regardless of what the
     * school's design does inside it is the whole requirement, since a card
     * that grows by two millimetres does not just look wrong — it moves every
     * card below it off the cut line.
     *
     * @param array<int,string|null> $faces
     */
    private function renderSheet(array $faces, array $plan, string $side): string
    {
        $offsetX = $plan['offset_x_mm'];
        $offsetY = $plan['offset_y_mm'];

        if ($side === 'back') {
            $offsetX += $plan['duplex_offset_mm']['x'];
            $offsetY += $plan['duplex_offset_mm']['y'];
        }

        $slots = '';

        foreach ($faces as $index => $face) {
            $row = intdiv($index, $plan['columns']);
            $column = $index % $plan['columns'];

            $left = $offsetX + $column * ($plan['slot_width_mm'] + $plan['gutter_mm']);
            $top = $offsetY + $row * ($plan['slot_height_mm'] + $plan['gutter_mm']);

            // An empty slot still gets its crop marks. The operator cuts a
            // whole sheet on one set of guides; marks that stop halfway down
            // the last sheet are worse than no marks at all.
            if ($plan['cut_guides'] === 'marks') {
                $slots .= $this->cropMarks($left, $top, $plan);
            }

            if ($face === null) {
                continue;
            }

            $slots .= sprintf(
                '<div class="slot" style="left:%.3fmm;top:%.3fmm;">%s</div>',
                $left,
                $top,
                $face
            );
        }

        return '<div class="sheet">' . $slots . '</div>';
    }

    /**
     * Four corner ticks, sitting in the gutter just outside the trim.
     *
     * Each tick is a bare div with two borders, which is the one way to draw a
     * hairline in dompdf that comes out the same on every backend. Marks are
     * preferred over a printed border by anyone cutting with a guillotine: a
     * border that survives a slightly-off cut is a visible black edge on the
     * finished card, whereas a mark outside the trim is cut away by
     * definition.
     */
    private function cropMarks(float $left, float $top, array $plan): string
    {
        $width = $plan['slot_width_mm'];
        $height = $plan['slot_height_mm'];
        $length = self::MARK_LENGTH_MM;
        $gap = self::MARK_GAP_MM;

        $marks = [
            // [x, y, which two borders]
            [$left - $gap - $length, $top - $gap - $length, 'border-right border-bottom'],
            [$left + $width + $gap, $top - $gap - $length, 'border-left border-bottom'],
            [$left - $gap - $length, $top + $height + $gap, 'border-right border-top'],
            [$left + $width + $gap, $top + $height + $gap, 'border-left border-top'],
        ];

        $html = '';

        foreach ($marks as [$x, $y, $classes]) {
            $html .= sprintf(
                '<div class="mark %s" style="left:%.3fmm;top:%.3fmm;width:%.3fmm;height:%.3fmm;"></div>',
                $classes,
                $x,
                $y,
                $length,
                $length
            );
        }

        return $html;
    }

    /**
     * Wrap the sheets in a document dompdf will paginate one sheet per page.
     *
     * `@page { margin: 0 }` is deliberate. Imposition already knows exactly
     * where every card goes in absolute millimetres; a page margin on top of
     * that would shift the grid by an amount the plan does not know about, and
     * the cards would no longer be where the cut guides say they are.
     */
    private function document(array $sheets, array $plan, string $cardCss): string
    {
        $body = implode("\n", $sheets);

        $radius = $plan['corner_radius_mm'] > 0
            ? sprintf('border-radius:%.2fmm;', $plan['corner_radius_mm'])
            : '';

        $guide = match ($plan['cut_guides']) {
            'border' => 'border: 0.2mm solid #b7c0cc;',
            default => '',
        };

        return sprintf(
            <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>ID cards</title>
<style>
@page { size: %s %s; margin: 0; }
html, body { margin: 0; padding: 0; }
.sheet {
  position: relative;
  width: %.3fmm;
  height: %.3fmm;
  page-break-after: always;
  overflow: hidden;
}
/* The last sheet must not emit a trailing blank page. */
.sheet:last-child { page-break-after: auto; }
.slot {
  position: absolute;
  width: %.3fmm;
  height: %.3fmm;
  overflow: hidden;
  %s
  %s
}
.mark { position: absolute; }
.mark.border-top { border-top: 0.2mm solid #111111; }
.mark.border-bottom { border-bottom: 0.2mm solid #111111; }
.mark.border-left { border-left: 0.2mm solid #111111; }
.mark.border-right { border-right: 0.2mm solid #111111; }

/* ---- school-supplied card stylesheet ---- */
%s
</style>
</head>
<body>
%s
</body>
</html>
HTML,
            strtolower($plan['sheet']),
            $plan['sheet_orientation'],
            $plan['sheet_width_mm'],
            $plan['sheet_height_mm'],
            $plan['slot_width_mm'],
            $plan['slot_height_mm'],
            $guide,
            $radius,
            $cardCss,
            $body
        );
    }
}
