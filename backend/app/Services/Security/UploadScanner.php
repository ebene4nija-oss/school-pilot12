<?php

namespace App\Services\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Looks inside every uploaded file before any controller keeps it.
 *
 * Checking the extension alone lets a program renamed "notes.pdf" through.
 * This reads the content: programs and scripts, Office files carrying
 * macros, PDFs that run JavaScript or launch programs, archives holding
 * programs, and image or document files with server code hidden in them.
 *
 * Where the school's server runs ClamAV (free software, no hardware), every
 * file is also sent to it. With ClamAV configured, an upload is refused when
 * ClamAV cannot be reached, unless CLAMAV_FAIL_OPEN is set.
 */
class UploadScanner
{
    /** Never accepted, whatever the endpoint. */
    private const BLOCKED_EXTENSIONS = [
        'exe', 'dll', 'com', 'scr', 'pif', 'msi', 'msp', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'hta',
        'jar', 'sh', 'bash', 'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'pl', 'py', 'rb', 'cgi', 'asp', 'aspx', 'jsp',
        'html', 'htm', 'xhtml', 'svg', 'swf', 'lnk', 'reg', 'app', 'dmg', 'iso', 'docm', 'dotm', 'xlsm', 'xltm', 'xlam', 'pptm', 'potm', 'ppsm',
    ];

    /** Plain-text formats, where code-like text is just text. */
    private const TEXT_EXTENSIONS = ['txt', 'csv', 'json', 'rtf'];

    /** @return string|null why the file is refused */
    public function check(UploadedFile $file): ?string
    {
        $name = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension());

        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            return "Files of type .{$ext} can't be uploaded.";
        }

        $path = $file->getRealPath();
        if (! $path || ! is_readable($path)) {
            return 'The file could not be read.';
        }
        if ((int) filesize($path) === 0) {
            return null; // nothing in it to harm anyone; endpoints validate emptiness themselves
        }
        // The server's own antivirus may have quarantined it already.
        $head = @file_get_contents($path, false, null, 0, 8);
        $body = $head === false ? false : $this->sample($path);
        if ($head === false || $body === false) {
            return 'The file could not be read. The server antivirus may have blocked it.';
        }

        if ($why = $this->executable($head, $body, $ext)) {
            return $why;
        }
        if ($ext === 'pdf' && preg_match('#/(JavaScript|JS|Launch)\b#', $body)) {
            return 'This PDF contains scripts or commands. Save it again as a plain PDF and upload that.';
        }
        if (str_starts_with($head, "\xD0\xCF\x11\xE0") && str_contains($body, "_\x00V\x00B\x00A\x00_\x00P\x00R\x00O\x00J\x00E\x00C\x00T")) {
            return 'This Office file contains macros. Save it without macros and upload that.';
        }
        if (str_starts_with($head, "PK\x03\x04") && ($why = $this->archive($path, $body))) {
            return $why;
        }

        return $this->clamav($path, $name);
    }

    private function executable(string $head, string $body, string $ext): ?string
    {
        $text = in_array($ext, self::TEXT_EXTENSIONS, true);
        // 'MZ' is also how a CSV could happen to begin; text files are judged on the rest.
        $program = (! $text && str_starts_with($head, 'MZ')) || str_starts_with($head, "\x7FELF")
            || in_array(substr($head, 0, 4), ["\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCE\xFA\xED\xFE", "\xCF\xFA\xED\xFE"], true)
            || str_starts_with($head, '#!');
        if ($program) {
            return 'This file is a program, not a document. It was not uploaded.';
        }
        if (! $text && preg_match('/<\?php|<%@|<script[\s>]/i', $body)) {
            return 'This file has code hidden inside it. It was not uploaded.';
        }

        return null;
    }

    /** Office documents and zip files: no programs or macro projects inside. */
    private function archive(string $path, string $body): ?string
    {
        // Entry names sit uncompressed in a zip, so a macro project is found
        // even where PHP's zip extension is not installed.
        if (stripos($body, 'vbaProject.bin') !== false) {
            return 'This Office file contains macros. Save it without macros and upload that.';
        }
        if (! class_exists(\ZipArchive::class)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = strtolower((string) $zip->getNameIndex($i));
                if (str_ends_with($entry, 'vbaproject.bin')) {
                    return 'This Office file contains macros. Save it without macros and upload that.';
                }
                if (in_array(pathinfo($entry, PATHINFO_EXTENSION), array_diff(self::BLOCKED_EXTENSIONS, ['html', 'htm', 'xhtml', 'svg', 'js']), true)) {
                    return 'This archive contains a program. It was not uploaded.';
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    /** ClamAV over clamd's INSTREAM protocol, when the server has it. */
    private function clamav(string $path, string $name): ?string
    {
        $host = config('services.clamav.host');
        if (! $host) {
            return null;
        }
        $failOpen = (bool) config('services.clamav.fail_open', false);

        $socket = @stream_socket_client(str_contains($host, '/') ? "unix://{$host}" : "tcp://{$host}:".config('services.clamav.port', 3310), $errno, $err, 5);
        if (! $socket) {
            Log::warning('ClamAV unreachable during upload scan.', ['error' => $err]);

            return $failOpen ? null : 'Uploads are paused while the virus scanner is unavailable. Try again shortly.';
        }

        try {
            stream_set_timeout($socket, 30);
            fwrite($socket, "zINSTREAM\0");
            $fh = fopen($path, 'rb');
            while (! feof($fh)) {
                $chunk = fread($fh, 8192);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fclose($fh);
            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\n ");
        } finally {
            fclose($socket);
        }

        if (str_ends_with($reply, 'OK')) {
            return null;
        }
        if (str_contains($reply, 'FOUND')) {
            Log::warning('Upload blocked by ClamAV.', ['file' => $name, 'result' => $reply]);

            return 'The virus scanner found a threat in this file. It was not uploaded.';
        }
        Log::warning('ClamAV gave no verdict.', ['reply' => $reply]);

        return $failOpen ? null : 'The file could not be scanned. Try again shortly.';
    }

    /** The start and end of the file, where these markers live. */
    private function sample(string $path): string|false
    {
        $size = (int) filesize($path);
        $limit = 4 * 1024 * 1024;
        if ($size <= $limit * 2) {
            return @file_get_contents($path);
        }
        $start = @file_get_contents($path, false, null, 0, $limit);
        $end = @file_get_contents($path, false, null, $size - $limit);

        return $start === false || $end === false ? false : $start.$end;
    }
}
