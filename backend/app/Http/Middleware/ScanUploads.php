<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Services\Security\UploadScanner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every file uploaded to the API is checked before any controller sees it,
 * so no upload endpoint — today's or a future one — can forget to.
 */
class ScanUploads
{
    public function __construct(private UploadScanner $scanner)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->files($request->allFiles()) as $field => $file) {
            if (! $file->isValid()) {
                continue; // Laravel's own validation reports a failed upload
            }
            if ($reason = $this->scanner->check($file)) {
                AuditLog::create([
                    'school_id' => $request->user()?->userProfile?->school_id, 'user_id' => $request->user()?->id, 'action' => 'upload.blocked',
                    'auditable_type' => 'upload', 'auditable_id' => 0,
                    'new_values' => ['file' => mb_substr($file->getClientOriginalName(), 0, 200), 'path' => $request->path(), 'reason' => $reason],
                    'ip_address' => $request->ip(),
                ]);

                return response()->json(['message' => $reason, 'errors' => [$field => [$reason]]], 422);
            }
        }

        return $next($request);
    }

    /** @return iterable<string, UploadedFile> */
    private function files(array $files, string $prefix = ''): iterable
    {
        foreach ($files as $key => $value) {
            $field = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if ($value instanceof UploadedFile) {
                yield $field => $value;
            } elseif (is_array($value)) {
                yield from $this->files($value, $field);
            }
        }
    }
}
