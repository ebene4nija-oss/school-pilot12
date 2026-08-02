<?php

namespace App\Services;

use App\Models\ScoreEntry;
use Exception;

class ReportCardPdfService
{
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

        $verificationToken = md5("student_{$studentId}_term_{$termId}_" . config('app.key'));
        $verificationUrl = config('app.url') . "/api/v1/verify-result/{$verificationToken}";

        return [
            'status' => 'ready',
            'student_id' => $studentId,
            'term_id' => $termId,
            'verification_token' => $verificationToken,
            'qr_code_url' => "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($verificationUrl),
            'scores' => $scoreEntries->toArray(),
        ];
    }
}
