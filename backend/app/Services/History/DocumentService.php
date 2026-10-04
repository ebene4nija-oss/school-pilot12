<?php

namespace App\Services\History;

use App\Models\AcademicDocument;
use App\Models\AuditLog;
use App\Models\PreviousSchoolRecord;
use App\Models\School;
use App\Models\Student;
use App\Models\Transfer;
use App\Models\User;
use App\Services\QrCodeService;
use Illuminate\Support\Facades\DB;
use App\Exceptions\DomainRefusal;

/**
 * Official documents: transcripts, transfer certificates, summaries
 * (history instructions §5).
 *
 * A document is its payload, frozen at issue. The payload is built from
 * locked term snapshots — never live data — so a transcript matches the
 * report cards issued, and changing the grading scale later changes nothing.
 * The payload's SHA-256 goes on the printed QR code with the reference; a
 * document altered after issue no longer matches and fails verification.
 */
class DocumentService
{
    public function __construct(private AcademicRecordService $records, private QrCodeService $qr)
    {
    }

    public function issue(string $type, Student $student, User $issuer, ?Transfer $transfer = null, ?string $signatory = null): AcademicDocument
    {
        if (! isset(AcademicDocument::TYPES[$type])) {
            throw new DomainRefusal('Unknown document type.');
        }

        $payload = $this->payloadFor($type, $student, $transfer);

        return DB::transaction(function () use ($type, $student, $issuer, $transfer, $signatory, $payload) {
            $reference = $this->nextReference((int) $student->school_id);
            $payload['reference'] = $reference;
            $payload['issued_at'] = now()->toIso8601String();
            $payload['signatory'] = $signatory ?? $issuer->name;

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $hash = hash('sha256', $json);

            $document = AcademicDocument::create([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'transfer_id' => $transfer?->id,
                'type' => $type,
                'reference' => $reference,
                'payload' => $json,
                'payload_hash' => $hash,
                'signature' => $this->sign($hash),
                'issued_by' => $issuer->id,
                'signatory_name' => $signatory ?? $issuer->name,
                'issued_at' => now(),
            ]);

            AuditLog::create([
                'school_id' => $student->school_id,
                'user_id' => $issuer->id,
                'action' => 'document.issued',
                'auditable_type' => AcademicDocument::class,
                'auditable_id' => $document->id,
                'new_values' => ['type' => $type, 'reference' => $reference, 'hash' => $hash],
            ]);

            return $document;
        });
    }

    public function revoke(AcademicDocument $document, User $user, string $reason, ?AcademicDocument $replacement = null): void
    {
        $document->update(['revoked_at' => now(), 'revoked_reason' => $reason, 'replaced_by_id' => $replacement?->id]);

        AuditLog::create([
            'school_id' => $document->school_id,
            'user_id' => $user->id,
            'action' => 'document.revoked',
            'auditable_type' => AcademicDocument::class,
            'auditable_id' => $document->id,
            'new_values' => ['reason' => $reason, 'replaced_by' => $replacement?->reference],
        ]);
    }

    /**
     * Public verification. Says only whether the school issued it, whether it
     * matches what was issued, the issue date, and whether it was revoked or
     * replaced (§5.3). Never the student's results.
     */
    public function verify(string $reference, ?string $hash): array
    {
        $doc = AcademicDocument::withoutGlobalScopes()->where('reference', $reference)->first();

        if (! $doc || ! hash_equals($doc->signature, $this->sign($doc->payload_hash)) || ! hash_equals($doc->payload_hash, hash('sha256', $doc->payload))) {
            return ['found' => false, 'message' => 'No document with this reference was issued through SchoolPilot.'];
        }

        $replacement = $doc->replaced_by_id ? AcademicDocument::withoutGlobalScopes()->find($doc->replaced_by_id) : null;

        return [
            'found' => true,
            'reference' => $doc->reference,
            'type' => AcademicDocument::TYPES[$doc->type] ?? $doc->type,
            'issuing_school' => School::find($doc->school_id)?->name,
            'issued_at' => $doc->issued_at->toDateString(),
            'matches_issued_copy' => $hash === null ? null : hash_equals($doc->payload_hash, $hash),
            'status' => $doc->revoked_at ? ($replacement ? 'replaced' : 'revoked') : 'valid',
            'replaced_by' => $replacement?->reference,
            'message' => match (true) {
                $hash !== null && ! hash_equals($doc->payload_hash, $hash) => 'This document does not match the copy the school issued. It may have been altered.',
                $doc->revoked_at !== null => 'The school has withdrawn this document.',
                default => 'This document was issued by the school named above and matches the issued copy.',
            },
        ];
    }

    /** The PDF, rendered from the frozen payload, with the verification QR. */
    public function pdf(AcademicDocument $document): string
    {
        $c = $document->content();
        $verifyUrl = rtrim((string) config('app.url'), '/')."/api/v1/verify-document/{$document->reference}?h={$document->payload_hash}";
        $qr = $this->qr->dataUriFor($verifyUrl) ?? '';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $body = match ($document->type) {
            'transcript', 'subject_history', 'transfer_summary' => $this->transcriptHtml($c, $e),
            'attendance_summary' => $this->attendanceHtml($c, $e),
            default => $this->letterHtml($document->type, $c, $e),
        };

        $title = $e(AcademicDocument::TYPES[$document->type] ?? 'Document');
        $revoked = $document->revoked_at ? '<p class="void">WITHDRAWN '.$e($document->revoked_at->toDateString()).'</p>' : '';

        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 16mm 14mm; }
body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #111; }
h1 { font-size: 16px; margin: 0; } h2 { font-size: 12px; margin: 10px 0 4px; } h3 { font-size: 10.5px; margin: 8px 0 2px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
th, td { border: 0.5px solid #666; padding: 2px 4px; text-align: left; }
th { background: #eee; }
.meta td { border: none; padding: 1px 4px; }
.foot { margin-top: 14px; width: 100%; } .foot td { border: none; vertical-align: bottom; }
.small { font-size: 8px; color: #444; }
.void { color: #b00; font-size: 20px; font-weight: bold; text-align: center; }
</style></head><body>
{$revoked}
<h1>{$e($c['school']['name'] ?? '')}</h1>
<h2>{$title}</h2>
<table class="meta">
<tr><td><b>Student</b></td><td>{$e($c['student']['name'] ?? '')}</td><td><b>Admission no.</b></td><td>{$e($c['student']['admission_number'] ?? '')}</td></tr>
<tr><td><b>Reference</b></td><td>{$e($document->reference)}</td><td><b>Issued</b></td><td>{$e($document->issued_at->format('j F Y'))}</td></tr>
</table>
{$body}
<table class="foot"><tr>
<td>______________________<br>{$e($document->signatory_name)}<br><span class="small">Authorised signatory</span></td>
<td style="text-align:right"><img src="{$qr}" width="90" height="90"><br><span class="small">Verify: {$e($document->reference)}</span></td>
</tr></table>
<p class="small">Fingerprint {$e(substr($document->payload_hash, 0, 16))}… Scan the code to confirm this document was issued by the school and has not been altered.</p>
</body></html>
HTML;

        AuditLog::create([
            'school_id' => $document->school_id,
            'user_id' => auth()->id(),
            'action' => 'document.downloaded',
            'auditable_type' => AcademicDocument::class,
            'auditable_id' => $document->id,
        ]);

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'isHtml5ParserEnabled' => true, 'dpi' => 96])
            ->output();
    }

    // ------------------------------------------------------------------

    private function payloadFor(string $type, Student $student, ?Transfer $transfer): array
    {
        $schoolId = (int) $student->school_id;
        $school = School::find($schoolId);
        $snapshots = $this->records->currentSnapshots($schoolId, $student->id);

        $base = [
            'format' => 'schoolpilot-document-v1',
            'type' => $type,
            'school' => ['id' => $schoolId, 'name' => $school?->name, 'address' => $school?->address],
            'student' => [
                'name' => $student->user?->name,
                'admission_number' => $student->admission_number,
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'status' => $student->status,
            ],
        ];

        $terms = $snapshots->map(function ($s) {
            $f = $s->figures();

            return [
                'session' => $s->session_name,
                'term' => $s->term_name,
                'class' => $s->class_name,
                'subjects' => array_map(fn ($sub) => [
                    'subject' => $sub['subject'],
                    'components' => $sub['components'],
                    'total' => $sub['total'],
                    'grade' => $sub['grade'],
                    'state' => $sub['state'],
                ], $f['subjects'] ?? []),
                'average' => $f['average'] ?? null,
                'grade' => $f['grade'] ?? null,
                'position' => $f['position'] ?? null,
                'class_size' => $f['class_size'] ?? null,
                'attendance' => $f['attendance'] ?? null,
                'grading_scale' => $f['grading_scale'] ?? [],
                'scheme' => $f['scheme']['parts'] ?? [],
                'provenance' => $s->provenance,
                'version' => $s->version,
                'partial_term' => $s->partial_term,
            ];
        })->values()->all();

        $previous = PreviousSchoolRecord::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->get()
            ->map(fn ($r) => ['school' => $r->source_school_name, 'provenance' => $r->provenance, 'verification' => $r->verification_status, 'records' => $r->content()])
            ->all();

        $promotions = DB::table('student_enrollments')
            ->leftJoin('academic_sessions', 'academic_sessions.id', '=', 'student_enrollments.session_id')
            ->leftJoin('classes', 'classes.id', '=', 'student_enrollments.class_id')
            ->where('student_enrollments.school_id', $schoolId)
            ->where('student_enrollments.student_id', $student->id)
            ->orderBy('academic_sessions.start_date')
            ->get(['academic_sessions.name as session', 'classes.name as class', 'student_enrollments.outcome'])
            ->map(fn ($r) => (array) $r)
            ->all();

        return match ($type) {
            'transcript', 'subject_history' => $base + ['terms' => $terms, 'previous_schools' => $previous, 'promotions' => $promotions],
            'transfer_summary' => $base + ['terms' => array_slice($terms, -3), 'promotions' => $promotions],
            'attendance_summary' => $base + ['attendance' => array_map(fn ($t) => ['session' => $t['session'], 'term' => $t['term'], 'attendance' => $t['attendance']], $terms)],
            'transfer_certificate' => $base + [
                'transfer_reference' => $transfer?->reference,
                'last_class' => $student->currentClass?->name,
                'destination' => $transfer?->other_school_name,
                'leaving_date' => ($transfer?->requested_transfer_date ?? now())->toDateString(),
                'promotions' => $promotions,
            ],
            'clearance_certificate' => $base + [
                'transfer_reference' => $transfer?->reference,
                'items' => $transfer ? $transfer->clearanceItems()->get(['item', 'status', 'override_reason'])->toArray() : [],
            ],
            'conduct_reference' => $base + ['last_class' => $student->currentClass?->name, 'principal_comments' => array_values(array_filter(array_map(fn ($s) => $s->figures()['principal_comment'] ?? null, $snapshots->all())))],
            default => $base,
        };
    }

    private function transcriptHtml(array $c, callable $e): string
    {
        $html = '';

        foreach ($c['terms'] ?? [] as $t) {
            $parts = $t['scheme'] ?? [];
            $head = '<tr><th>Subject</th>'.implode('', array_map(fn ($p) => '<th>'.$e($p['name']).'</th>', $parts)).'<th>Total</th><th>Grade</th></tr>';
            $rows = '';

            foreach ($t['subjects'] as $s) {
                $cells = '';
                foreach ($parts as $p) {
                    $cells .= '<td>'.$e($s['state'] === 'scored' ? ($s['components'][$p['key']] ?? '') : '').'</td>';
                }
                $total = $s['state'] === 'scored' ? $s['total'] : strtoupper($s['state'] === 'absent' ? 'ABS' : ($s['state'] === 'exempt' ? 'EX' : '—'));
                $rows .= '<tr><td>'.$e($s['subject']).'</td>'.$cells.'<td>'.$e($total).'</td><td>'.$e($s['grade']).'</td></tr>';
            }

            $scale = implode(', ', array_map(fn ($b) => "{$b['grade']} {$b['min']}–{$b['max']}", $t['grading_scale'] ?? []));
            $flags = ($t['partial_term'] ? ' · part of the term' : '').($t['provenance'] !== 'system_generated' ? ' · '.$t['provenance'] : '').($t['version'] > 1 ? ' · corrected (v'.$t['version'].')' : '');

            $html .= '<h3>'.$e("{$t['session']} · {$t['term']} · {$t['class']}").'<span class="small">'.$e($flags).'</span></h3>';
            $html .= '<table>'.$head.$rows.'</table>';
            $html .= '<p class="small">Average '.$e($t['average'] ?? '—').' · grade '.$e($t['grade'] ?? '—')
                .($t['position'] ? ' · position '.$e($t['position']).' of '.$e($t['class_size']) : '')
                .' · grading scale used: '.$e($scale).'</p>';
        }

        foreach ($c['previous_schools'] ?? [] as $p) {
            $html .= '<h3>Records received from '.$e($p['school']).' <span class="small">('.$e($p['provenance']).', '.$e($p['verification']).')</span></h3>';
        }

        if (! empty($c['promotions'])) {
            $html .= '<h3>Progression</h3><table><tr><th>Session</th><th>Class</th><th>Outcome</th></tr>';
            foreach ($c['promotions'] as $p) {
                $html .= '<tr><td>'.$e($p['session']).'</td><td>'.$e($p['class']).'</td><td>'.$e($p['outcome'] ?? 'in progress').'</td></tr>';
            }
            $html .= '</table>';
        }

        return $html !== '' ? $html : '<p>No locked term results are on record yet.</p>';
    }

    private function attendanceHtml(array $c, callable $e): string
    {
        $rows = '';
        foreach ($c['attendance'] ?? [] as $a) {
            $x = $a['attendance'] ?? [];
            $rows .= '<tr><td>'.$e($a['session'].' '.$a['term']).'</td><td>'.$e($x['days'] ?? '—').'</td><td>'.$e($x['present'] ?? '—').'</td><td>'.$e($x['absent'] ?? '—').'</td><td>'.$e($x['late'] ?? '—').'</td><td>'.$e(isset($x['rate']) ? $x['rate'].'%' : '—').'</td></tr>';
        }

        return '<table><tr><th>Term</th><th>Days</th><th>Present</th><th>Absent</th><th>Late</th><th>Rate</th></tr>'.$rows.'</table>';
    }

    private function letterHtml(string $type, array $c, callable $e): string
    {
        return match ($type) {
            'transfer_certificate' => '<p>This is to certify that <b>'.$e($c['student']['name']).'</b> (admission number '.$e($c['student']['admission_number'])
                .') was a student of this school, last in '.$e($c['last_class'] ?? '—').', and left on '.$e($c['leaving_date'] ?? '')
                .($c['destination'] ? ' to join '.$e($c['destination']) : '').'.</p><p>Transfer reference: '.$e($c['transfer_reference'] ?? '—').'</p>',
            'clearance_certificate' => '<table><tr><th>Item</th><th>Status</th><th>Note</th></tr>'.implode('', array_map(
                fn ($i) => '<tr><td>'.$e($i['item']).'</td><td>'.$e($i['status']).'</td><td>'.$e($i['override_reason'] ?? '').'</td></tr>',
                $c['items'] ?? []
            )).'</table>',
            'conduct_reference' => '<p>'.implode('</p><p>', array_map($e, $c['principal_comments'] ?? ['No principal comments on record.'])).'</p>',
            default => '',
        };
    }

    private function nextReference(int $schoolId): string
    {
        $n = AcademicDocument::withoutGlobalScopes()->where('school_id', $schoolId)->count() + 1;

        do {
            $ref = sprintf('DOC-%d-%s-%05d', $schoolId, now()->format('Y'), $n++);
        } while (AcademicDocument::withoutGlobalScopes()->where('reference', $ref)->exists());

        return $ref;
    }

    /** HMAC with the application key: only this platform can produce a valid signature. */
    public function sign(string $hash): string
    {
        return hash_hmac('sha256', $hash, (string) config('app.key'));
    }
}
