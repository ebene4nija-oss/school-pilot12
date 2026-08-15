<?php

namespace App\Services;

use App\Models\CbtMediaAsset;
use App\Models\QuestionBankItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The image pipeline behind CBT questions.
 *
 * A Nigerian secondary-school paper is full of pictures: labelled biology
 * diagrams, geometry constructions, map extracts, circuit drawings, a photo of
 * an apparatus. Handling them well means more than "accept a file":
 *
 *  - the declared MIME type is ignored; the bytes decide
 *  - SVG is refused outright (it is a script container, not a picture)
 *  - metadata is stripped on ingest (see ImageMetadataStripper)
 *  - storage is content-addressed, so the same diagram reused across twenty
 *    questions is one file and one download for the offline exam client
 *  - alt text is mandatory, because a candidate using a screen reader still
 *    has to sit the paper
 */
class CbtMediaService
{
    /** Raster formats only. SVG is deliberately absent. */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public const MAX_BYTES = 5 * 1024 * 1024;      // 5 MB
    public const MAX_DIMENSION = 5000;              // px on the longest edge
    public const THUMBNAIL_MAX_DIMENSION = 320;

    /** Where a media reference is allowed to sit on a question. */
    /**
     * `stimulus` belongs to a question *group* rather than a question: the map,
     * circuit or data table a whole passage of sub-questions refers to. It
     * loads once for the group, not once per sub-question.
     */
    public const ROLES = ['stem', 'option', 'explanation', 'diagram', 'stimulus'];

    public function __construct(
        private ImageMetadataStripper $stripper
    ) {
    }

    /**
     * Ingest an upload and return the (possibly pre-existing) asset.
     *
     * @throws \InvalidArgumentException on anything we will not store
     */
    public function store(UploadedFile $file, int $schoolId, ?int $userId, string $altText, ?string $caption = null, string $disk = 'public'): CbtMediaAsset
    {
        $altText = trim($altText);
        if ($altText === '') {
            throw new \InvalidArgumentException(
                'Alt text is required. Describe what the image shows so a candidate using a screen reader can answer the question.'
            );
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Image is %s; the maximum is %s. Exam halls run on slow connections — keep diagrams small.',
                $this->humanBytes((int) $file->getSize()),
                $this->humanBytes(self::MAX_BYTES)
            ));
        }

        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException('Upload could not be read.');
        }

        // Sniff the real type from the bytes. A .png extension on a PHP file
        // is the oldest upload trick there is.
        $mimeType = $this->detectMimeType($bytes);

        if (!isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported image type "%s". Allowed: JPEG, PNG, WebP, GIF. SVG is not accepted because it can carry scripts.',
                $mimeType ?: 'unknown'
            ));
        }

        $dimensions = @getimagesizefromstring($bytes);
        if ($dimensions === false) {
            throw new \InvalidArgumentException('File is not a readable image.');
        }

        [$width, $height] = $dimensions;

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new \InvalidArgumentException(sprintf(
                'Image is %dx%dpx; the maximum on either edge is %dpx.',
                $width,
                $height,
                self::MAX_DIMENSION
            ));
        }

        $clean = $this->stripper->strip($bytes, $mimeType);
        $checksum = hash('sha256', $clean);

        // Content-addressed: identical bytes are stored once per school.
        $existing = CbtMediaAsset::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('checksum', $checksum)
            ->first();

        if ($existing) {
            // Re-uploading the same diagram with better alt text should update
            // the description rather than silently keep the old one.
            if ($altText !== $existing->alt_text || ($caption !== null && $caption !== $existing->caption)) {
                $existing->update(array_filter([
                    'alt_text' => $altText,
                    'caption' => $caption,
                ], fn ($value) => $value !== null));
            }

            return $existing;
        }

        $extension = self::ALLOWED_MIME_TYPES[$mimeType];
        $path = sprintf('cbt/%d/%s/%s.%s', $schoolId, substr($checksum, 0, 2), $checksum, $extension);

        Storage::disk($disk)->put($path, $clean);

        $thumbnailPath = $this->makeThumbnail($clean, $mimeType, $disk, $schoolId, $checksum, $width, $height);

        return CbtMediaAsset::create([
            'school_id' => $schoolId,
            'uploaded_by' => $userId,
            'disk' => $disk,
            'path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'byte_size' => strlen($clean),
            'width' => $width,
            'height' => $height,
            'checksum' => $checksum,
            'alt_text' => $altText,
            'caption' => $caption,
        ]);
    }

    /**
     * Validate a question's media references: every asset must exist, belong
     * to this school, and sit in a role the question type understands.
     *
     * @param array<int,array> $media
     * @return array<int,array> normalised references
     * @throws \InvalidArgumentException
     */
    public function normaliseMediaReferences(?array $media, int $schoolId, string $questionType): array
    {
        if (empty($media)) {
            // Image-native question types are meaningless without a picture.
            if (in_array($questionType, ['diagram_label', 'hotspot'], true)) {
                throw new \InvalidArgumentException(
                    "A '{$questionType}' question needs an image attached in the 'diagram' role."
                );
            }

            return [];
        }

        $assetIds = collect($media)->pluck('asset_id')->filter()->map(fn ($id) => (int) $id)->unique();

        $assets = CbtMediaAsset::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('id', $assetIds)
            ->get()
            ->keyBy('id');

        $normalised = [];
        $position = 0;

        foreach ($media as $reference) {
            $assetId = (int) ($reference['asset_id'] ?? 0);

            if (!$assets->has($assetId)) {
                throw new \InvalidArgumentException(
                    "Media asset {$assetId} does not exist in this school's library."
                );
            }

            $role = $reference['role'] ?? 'stem';
            $baseRole = explode(':', $role)[0];

            if (!in_array($baseRole, self::ROLES, true)) {
                throw new \InvalidArgumentException(
                    "Unknown media role '{$role}'. Allowed: " . implode(', ', self::ROLES) . " (option roles are written as 'option:A')."
                );
            }

            $normalised[] = [
                'asset_id' => $assetId,
                'role' => $role,
                'position' => (int) ($reference['position'] ?? $position),
            ];

            $position++;
        }

        if (in_array($questionType, ['diagram_label', 'hotspot'], true)) {
            $hasDiagram = collect($normalised)->contains(fn ($r) => str_starts_with($r['role'], 'diagram'));
            if (!$hasDiagram) {
                throw new \InvalidArgumentException(
                    "A '{$questionType}' question needs an image attached in the 'diagram' role."
                );
            }
        }

        return $normalised;
    }

    /**
     * Hydrate media references into full asset payloads, keyed by role, so a
     * client can render without a second round trip per image.
     *
     * @param iterable<QuestionBankItem> $questions
     * @return array<int,array> asset payloads keyed by asset id
     */
    public function hydrateForQuestions(iterable $questions): array
    {
        $ids = [];
        foreach ($questions as $question) {
            foreach ($question->mediaAssetIds() as $id) {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return CbtMediaAsset::withoutGlobalScopes()
            ->whereIn('id', array_keys($ids))
            ->get()
            ->keyBy('id')
            ->map(fn (CbtMediaAsset $asset) => $asset->toManifestEntry())
            ->all();
    }

    /**
     * Everything the offline exam client must pre-download before exam day,
     * with checksums so it can verify what it cached and re-fetch what it
     * did not. Sizes are totalled so an admin can see "this paper is 14 MB"
     * before pushing it to twenty lab machines over a mobile hotspot.
     */
    public function buildOfflineManifest(iterable $questions): array
    {
        $assets = $this->hydrateForQuestions($questions);

        return [
            'asset_count' => count($assets),
            'total_bytes' => array_sum(array_column($assets, 'byte_size')),
            'assets' => array_values($assets),
        ];
    }

    private function detectMimeType(string $bytes): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_buffer($finfo, $bytes);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        $info = @getimagesizefromstring($bytes);

        return $info['mime'] ?? null;
    }

    /**
     * Thumbnails need an image extension. Where the host PHP has neither GD
     * nor Imagick — common on cheap Nigerian shared hosting — we skip the
     * derivative rather than fail the upload; clients fall back to the full
     * image with a max-width, which is correct, just heavier.
     */
    private function makeThumbnail(string $bytes, string $mimeType, string $disk, int $schoolId, string $checksum, int $width, int $height): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecopyresampled')) {
            return null;
        }

        if ($width <= self::THUMBNAIL_MAX_DIMENSION && $height <= self::THUMBNAIL_MAX_DIMENSION) {
            return null; // already thumbnail-sized
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        $scale = self::THUMBNAIL_MAX_DIMENSION / max($width, $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if (in_array($mimeType, ['image/png', 'image/webp', 'image/gif'], true)) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        match ($mimeType) {
            'image/png' => imagepng($target, null, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($target, null, 80) : imagejpeg($target, null, 80),
            'image/gif' => imagegif($target),
            default => imagejpeg($target, null, 80),
        };
        $thumbnailBytes = ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        if (!$thumbnailBytes) {
            return null;
        }

        $extension = self::ALLOWED_MIME_TYPES[$mimeType];
        $path = sprintf('cbt/%d/%s/%s_thumb.%s', $schoolId, substr($checksum, 0, 2), $checksum, $extension);
        Storage::disk($disk)->put($path, $thumbnailBytes);

        return $path;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1) . ' MB';
        }

        return round($bytes / 1024) . ' KB';
    }
}
