<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A child's passport photo, kept private (doc §12).
 *
 * Photos used to go to the public disk, where anyone holding the link could
 * open them without signing in. They now live on the private disk and are
 * served only through GET /students/{id}/photo, to someone allowed to see the
 * student. Location and camera metadata are stripped on the way in: a phone
 * photo carries GPS for where it was taken, often the child's home.
 *
 * Paths are stored relative to storage/app ("private/students/photos/…"), the
 * root ID cards and report cards already resolve photos from, so both keep
 * embedding them without a URL.
 */
class StudentPhotoService
{
    public const DIR = 'students/photos';

    private const PRIVATE_PREFIX = 'private/';

    public function __construct(private ImageMetadataStripper $stripper)
    {
    }

    /** Store an uploaded photo privately; returns the path to save on the student. */
    public function store(UploadedFile $file): string
    {
        return $this->storeBytes((string) file_get_contents($file->getRealPath()), (string) $file->getMimeType());
    }

    public function storeBytes(string $bytes, string $mime): string
    {
        $clean = $this->stripper->strip($bytes, $mime);
        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $relative = self::DIR.'/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->put($relative, $clean);

        return self::PRIVATE_PREFIX.$relative;
    }

    /** The file on disk for a stored path, private or (older) public. */
    public function absolutePath(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        if (str_starts_with($path, self::PRIVATE_PREFIX)) {
            $relative = substr($path, strlen(self::PRIVATE_PREFIX));

            return Storage::disk('local')->exists($relative) ? Storage::disk('local')->path($relative) : null;
        }

        return Storage::disk('public')->exists($path) ? Storage::disk('public')->path($path) : null;
    }

    public function isPrivate(?string $path): bool
    {
        return str_starts_with((string) $path, self::PRIVATE_PREFIX);
    }

    /** Remove a photo this service stored. Older public files are left to the move command. */
    public function delete(?string $path): void
    {
        if ($this->isPrivate($path)) {
            Storage::disk('local')->delete(substr((string) $path, strlen(self::PRIVATE_PREFIX)));
        }
    }
}
