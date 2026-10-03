<?php

namespace App\Services\Results;

/**
 * Broadsheet files, always rendered from an approved snapshot (spec §12):
 * an export is a record of what was approved, never of a draft in progress.
 *
 * Personal data on the sheet is what the broadsheet needs and no more:
 * admission number, name, and gender only when the school's layout asks for
 * it. No dates of birth, no guardians, no contact details (NDPA, doc §12).
 */
class BroadsheetExporter
{
    /**
     * CSV that Excel opens directly. A UTF-8 byte-order mark makes Excel read
     * "₦" and accented names correctly.
     */
    public function csv(array $snapshot, string $kind = 'class', ?int $subjectId = null): string
    {
        $calc = $snapshot['calculation'];
        $rows = $kind === 'subject' ? $this->subjectRows($calc, $subjectId) : $this->classRows($calc);

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");

        foreach ($this->preamble($snapshot) as $line) {
            fputcsv($out, [$line]);
        }

        fputcsv($out, []);

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => $this->safeCell($v), $row));
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @param array{paper?: string, orientation?: string, school_name?: string} $layout */
    public function pdf(array $snapshot, string $kind = 'class', ?int $subjectId = null, array $layout = []): string
    {
        $calc = $snapshot['calculation'];
        $rows = $kind === 'subject' ? $this->subjectRows($calc, $subjectId) : $this->classRows($calc);
        $header = array_shift($rows);

        $paper = in_array($layout['paper'] ?? 'a4', ['a4', 'a3'], true) ? $layout['paper'] : 'a4';
        $orientation = ($layout['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $head = implode('', array_map(fn ($h) => '<th>'.$e($h).'</th>', $header));
        $body = implode('', array_map(
            fn ($r) => '<tr>'.implode('', array_map(fn ($c) => '<td>'.$e($c).'</td>', $r)).'</tr>',
            $rows
        ));

        $approvals = '';
        foreach ($snapshot['approvals'] ?? [] as $a) {
            $approvals .= '<td class="sig">'.$e($a['label']).'<br><strong>'.$e($a['name']).'</strong><br>'.$e($a['at']).'</td>';
        }

        $preamble = implode('<br>', array_map($e, array_slice($this->preamble($snapshot), 1)));
        $footer = $e($calc['config']['confidentiality_footer'] ?? 'Confidential: for school records.');
        $school = $e($layout['school_name'] ?? 'School');

        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 18mm 10mm 16mm 10mm; }
body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #111; }
h1 { font-size: 14px; margin: 0; }
.meta { margin: 4px 0 8px; font-size: 9px; }
table.grid { width: 100%; border-collapse: collapse; }
table.grid thead { display: table-header-group; }
table.grid th, table.grid td { border: 0.5px solid #555; padding: 2px 3px; text-align: center; }
table.grid th { background: #eee; }
table.grid td:nth-child(2), table.grid td:nth-child(3) { text-align: left; }
table.sigs { width: 100%; margin-top: 14px; }
td.sig { padding-top: 18px; border-top: 0.5px solid #333; width: 25%; font-size: 8px; }
#footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7.5px; color: #555; }
#footer .page:after { content: "Page " counter(page); }
</style></head><body>
<div id="footer">{$footer} &nbsp;·&nbsp; Printed {$e(now()->format('j M Y, H:i'))} &nbsp;·&nbsp; <span class="page"></span></div>
<h1>{$school}</h1>
<div class="meta">{$preamble}</div>
<table class="grid"><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>
<table class="sigs"><tr>{$approvals}</tr></table>
</body></html>
HTML;

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper($paper, $orientation)
            ->setOptions([
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'dpi' => 96,
            ])
            ->output();
    }

    /** @return array<int, string> */
    private function preamble(array $snapshot): array
    {
        $calc = $snapshot['calculation'];
        $b = $snapshot['broadsheet'];

        return [
            'Broadsheet '.$b['reference'].' (version '.$b['version'].')',
            'Class: '.$calc['class']['name'].'  ·  Session: '.($calc['term']['session'] ?? '').'  ·  '.$calc['term']['name'],
            'Assessment scheme: '.$calc['scheme']['name'].' ('.implode(', ', array_map(fn ($p) => $p['name'].' '.$p['max'], $calc['scheme']['parts'])).')',
            'Approved '.($snapshot['approved_at'] ?? ''),
        ];
    }

    /** Header row first. */
    private function classRows(array $calc): array
    {
        $config = $calc['config'];
        $mode = $config['display_mode'];
        $numeric = $config['numeric_averages'];
        $ranked = $config['ranking_basis'] !== 'none';

        $header = ['No.', 'Admission No.', 'Student'];
        if ($config['show_gender']) {
            $header[] = 'Gender';
        }

        foreach ($calc['subjects'] as $subject) {
            $label = $subject['code'] ?: $subject['name'];

            if (in_array($mode, ['breakdown', 'detailed'], true)) {
                foreach ($calc['scheme']['parts'] as $part) {
                    $header[] = "{$label} {$part['name']}";
                }
                $header[] = "{$label} Total";
                $header[] = "{$label} Grade";
            } elseif ($mode === 'score_grade') {
                $header[] = "{$label}";
                $header[] = "{$label} Grade";
            } else {
                $header[] = $label;
            }
        }

        if ($numeric) {
            array_push($header, 'Total', 'Average');
        }
        $header[] = 'Grade';
        if ($ranked) {
            $header[] = 'Position';
        }
        if ($config['show_attendance']) {
            $header[] = 'Attendance %';
        }

        $rows = [$header];

        foreach ($calc['students'] as $i => $s) {
            $row = [$i + 1, $s['admission_number'], $s['name']];
            if ($config['show_gender']) {
                $row[] = $s['gender'];
            }

            foreach ($calc['subjects'] as $subject) {
                $r = $s['results'][$subject['id']] ?? null;
                $shown = $this->shownScore($r);

                if (in_array($mode, ['breakdown', 'detailed'], true)) {
                    foreach ($calc['scheme']['parts'] as $part) {
                        $row[] = $r && $r['state'] === 'scored' ? ($r['components'][$part['key']] ?? '') : '';
                    }
                    $row[] = $shown;
                    $row[] = $r['grade'] ?? '';
                } elseif ($mode === 'score_grade') {
                    $row[] = $shown;
                    $row[] = $r['grade'] ?? '';
                } elseif ($mode === 'grade') {
                    $row[] = $r['grade'] ?? $this->stateLabel($r);
                } else {
                    $row[] = $shown;
                }
            }

            if ($numeric) {
                $row[] = $s['total'] ?? '';
                $row[] = $s['average'] !== null ? number_format($s['average'], 2) : '';
            }
            $row[] = $s['grade'] ?? '';
            if ($ranked) {
                $row[] = $s['position'] ? $this->ordinal($s['position']) : '';
            }
            if ($config['show_attendance']) {
                $row[] = $s['attendance']['rate'] ?? '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function subjectRows(array $calc, ?int $subjectId): array
    {
        $subject = collect($calc['subjects'])->firstWhere('id', $subjectId);
        $header = ['No.', 'Admission No.', 'Student'];

        foreach ($calc['scheme']['parts'] as $part) {
            $header[] = "{$part['name']} ({$part['max']})";
        }
        array_push($header, 'Total', 'Grade', 'Position', 'Remark');

        $rows = [$header];
        $n = 0;

        foreach ($calc['students'] as $s) {
            $r = $s['results'][$subjectId] ?? null;

            if (! $r || $r['state'] === 'not_offered') {
                continue;
            }

            $row = [++$n, $s['admission_number'], $s['name']];
            foreach ($calc['scheme']['parts'] as $part) {
                $row[] = $r['state'] === 'scored' ? ($r['components'][$part['key']] ?? '') : '';
            }
            array_push($row, $this->shownScore($r), $r['grade'] ?? '', $r['position'] ? $this->ordinal($r['position']) : '', $r['remark'] ?? '');
            $rows[] = $row;
        }

        if ($subject === null) {
            return [['No subject selected']];
        }

        return $rows;
    }

    private function shownScore(?array $r): string
    {
        if (! $r) {
            return '';
        }

        return $r['state'] === 'scored' ? (string) $r['total'] : $this->stateLabel($r);
    }

    private function stateLabel(?array $r): string
    {
        return match ($r['state'] ?? null) {
            'absent' => 'ABS',
            'exempt' => 'EX',
            'not_entered' => '—',
            default => '',
        };
    }

    private function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }

    /** A leading =, +, - or @ would run as a formula when Excel opens the file. */
    private function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && ! is_numeric($value) ? "'".$value : $value;
    }
}
