<?php

namespace App\Services;

use App\Models\ReportCardToken;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Term;
use App\Services\ReportCard\ReportCardTemplateService;
use Exception;

class ReportCardPdfService
{
    public function __construct(
        private ReportCardTemplateService $templates,
        private QrCodeService $qr
    ) {
    }

    /**
     * Generate PDF Report Card Payload for a given student
     */
    public function generateReportCard(int $studentId, int $termId): array
    {
        $scoreEntries = ScoreEntry::where('student_id', $studentId)
            ->where('term_id', $termId)
            ->get();

        // Enforce Hard Guardrail: Refuse PDF generation if any comment is in pending_approval
        foreach ($scoreEntries as $entry) {
            if ($entry->ai_comment_status === 'pending_approval') {
                throw new Exception("Report card cannot be generated: AI comment for entry ID {$entry->id} is still pending_approval.");
            }
        }

        // Persisted, random, revocable — see ReportCardToken. The old
        // md5(student+term+APP_KEY) was never written anywhere, so the QR on a
        // printed card pointed at a token the verifier could not find.
        $student = Student::withoutGlobalScopes()->findOrFail($studentId);
        $token = ReportCardToken::issueFor($student->school_id, $studentId, $termId);

        $verificationUrl = config('app.url') . "/api/v1/verify-result/{$token->qr_token}";

        return [
            'status' => 'ready',
            'student_id' => $studentId,
            'term_id' => $termId,
            'verification_token' => $token->qr_token,
            // Generated locally as a data: URI. The previous value handed every
            // student's verification URL to a third-party QR service on every
            // print, which is an avoidable NDPA disclosure (doc §12).
            'qr_code_url' => $this->qr->dataUriFor($verificationUrl) ?? '',
            'verify_url' => $verificationUrl,
            'scores' => $scoreEntries->toArray(),
        ];
    }

    /**
     * Render the report card through the school's own imported design,
     * falling back to the shipped default when they have not imported one.
     *
     * The pending_approval guardrail is enforced here as well as in
     * generateReportCard(): this is a second, independent path to a printed
     * card, and "an AI comment never reaches a report card unreviewed" has to
     * hold on every path, not just the first one written.
     */
    public function renderHtml(int $studentId, int $termId, array $extra = []): string
    {
        $student = Student::withoutGlobalScopes()->with(['user', 'currentClass', 'currentArm'])->findOrFail($studentId);
        $term = Term::withoutGlobalScopes()->with('session')->findOrFail($termId);

        $pending = ScoreEntry::withoutGlobalScopes()
            ->where('student_id', $studentId)
            ->where('term_id', $termId)
            ->where('ai_comment_status', 'pending_approval')
            ->first();

        if ($pending) {
            throw new Exception("Report card cannot be generated: AI comment for entry ID {$pending->id} is still pending_approval.");
        }

        $meta = $this->generateReportCard($studentId, $termId);

        // Reuse the code generateReportCard already produced rather than
        // encoding the same matrix twice for every card in a 400-card run.
        // Empty string if generation failed — the template then prints the
        // verification URL as text instead of a broken image.
        $extra['verification'] = [
            'token' => $meta['verification_token'],
            'verify_url' => $meta['verify_url'],
            'qr_code_url' => $meta['qr_code_url'],
        ];

        $template = $this->templates->activeTemplateFor($student->school_id);
        $context = $this->templates->buildContext($student, $term, $extra);

        return $this->templates->render($template, $context);
    }

    /**
     * Rendered PDF bytes.
     *
     * dompdf is locked down here rather than in config: remote fetching off
     * (a school template must not be able to make the server request an
     * arbitrary URL at print time) and no PHP evaluation inside the document.
     */
    public function renderPdf(int $studentId, int $termId, array $extra = []): string
    {
        $html = $this->renderHtml($studentId, $termId, $extra);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setOptions([
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
                'isHtml5ParserEnabled' => true,
            ]);

        return $pdf->output();
    }
}
