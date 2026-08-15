<?php

namespace App\Services\IdCard;

use App\Services\ImageMetadataStripper;
use Illuminate\Support\Facades\Log;

/**
 * Turns a stored photo path into something safe to print, or says why it isn't.
 *
 * This exists because the failure mode it prevents is expensive and late. A
 * school queues "print JSS 2", walks to the printer, and finds out on sheet
 * eleven that four children have no photo and one has a 90x60 thumbnail that
 * prints as a grey smear. That is a wasted ream, wasted toner, and an hour of
 * a bursar's afternoon — so every photo in a run is resolved and measured
 * before a single card is laid out.
 *
 * Three constraints shape the implementation:
 *
 *  1. **Embed, never reference.** The PDF renderer runs with remote fetching
 *     off, and rightly so. A photo therefore has to arrive as a data: URI or
 *     not at all. A card whose `<img>` points at a URL prints as a blank box.
 *  2. **Read only from inside the photo roots.** `passport_photo_path` is a
 *     plain string an API client supplies. Resolving it without constraint
 *     turns "print the class's ID cards" into arbitrary local file
 *     disclosure — `../../.env`, rendered into a PDF and handed to a parent.
 *     Every path is canonicalised and proven to sit under an allowed root.
 *  3. **Strip metadata on the way through.** A passport photo taken on a
 *     phone carries GPS coordinates for wherever it was taken, which is
 *     frequently the child's home. We have no use for it (doc §12), so it
 *     does not travel into a document the school hands out.
 */
class PhotoPreflightService
{
    /** Below this, a face prints as a smear. Warned about, not refused. */
    public const MIN_COMFORTABLE_DPI = 200;

    /** A photo bigger than this is a scan or a raw camera dump, not a portrait. */
    public const MAX_PHOTO_BYTES = 8 * 1024 * 1024;

    /** Above this we still print, but the run's total size is worth flagging. */
    public const LARGE_PHOTO_BYTES = 1024 * 1024;

    /** Default photo window on the card face, in millimetres. */
    public const DEFAULT_BOX_MM = ['width' => 22.0, 'height' => 28.0];

    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(private ImageMetadataStripper $stripper)
    {
    }

    /**
     * Resolve one photo.
     *
     * @param array{width:float,height:float}|null $boxMm the printed size, for the DPI check
     * @return array{
     *     ok: bool,
     *     data_uri: string|null,
     *     fingerprint: string|null,
     *     bytes: int,
     *     problem: string|null,
     *     warnings: array<int,string>,
     *     dimensions: array{width:int,height:int}|null,
     *     dpi: array{horizontal:int,vertical:int}|null
     * }
     */
    public function resolve(?string $path, ?array $boxMm = null): array
    {
        $result = [
            'ok' => false,
            'data_uri' => null,
            'fingerprint' => null,
            'bytes' => 0,
            'problem' => null,
            'warnings' => [],
            'dimensions' => null,
            'dpi' => null,
        ];

        $path = trim((string) $path);

        if ($path === '') {
            $result['problem'] = 'no_photo';

            return $result;
        }

        // Already embedded — validate the envelope, then take it as given.
        if (str_starts_with(strtolower($path), 'data:')) {
            return $this->fromDataUri($path, $boxMm, $result);
        }

        /*
         * A remote photo cannot be printed. Rather than fetch it — which would
         * make this endpoint an SSRF probe with a school admin's credentials —
         * the run reports it and the school re-uploads the file.
         */
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) || str_starts_with($path, '//')) {
            $result['problem'] = 'remote_photo';

            return $result;
        }

        $real = $this->resolveWithinRoots($path);

        if ($real === null) {
            $result['problem'] = 'photo_not_found';

            return $result;
        }

        $bytes = @file_get_contents($real);

        if ($bytes === false || $bytes === '') {
            $result['problem'] = 'photo_unreadable';

            return $result;
        }

        return $this->inspect($bytes, $boxMm, $result);
    }

    /**
     * Canonicalise a stored path and prove it lives under a root we serve
     * photos from.
     *
     * realpath() resolves `..`, symlinks and Windows/POSIX separator mixing in
     * one step, which is why the containment check happens after it and not
     * on the string we were handed. A path that escapes every root returns
     * null and is reported as "not found" — the caller learns nothing about
     * what does exist outside the roots.
     */
    private function resolveWithinRoots(string $path): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $path), '/');

        // A path stored as "storage/foo.jpg" or "public/foo.jpg" is pointing at
        // the same file as "foo.jpg" relative to those roots.
        $candidates = [];
        foreach ($this->photoRoots() as $root) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . $relative;
        }

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);

            if ($real === false || !is_file($real) || !is_readable($real)) {
                continue;
            }

            foreach ($this->photoRoots() as $root) {
                $realRoot = realpath($root);

                if ($realRoot === false) {
                    continue;
                }

                // The separator on the end stops "/var/photos-evil" matching
                // a root of "/var/photos".
                if (str_starts_with($real, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                    return $real;
                }
            }
        }

        return null;
    }

    /** @return array<int,string> */
    private function photoRoots(): array
    {
        $roots = [
            storage_path('app/public'),
            storage_path('app'),
            public_path(),
        ];

        return array_values(array_filter($roots, 'is_dir'));
    }

    private function fromDataUri(string $uri, ?array $boxMm, array $result): array
    {
        if (!preg_match('#^data:(image/(?:png|jpeg|jpg|gif|webp));base64,(.+)$#is', $uri, $match)) {
            $result['problem'] = 'photo_unsupported_format';

            return $result;
        }

        $bytes = base64_decode($match[2], true);

        if ($bytes === false || $bytes === '') {
            $result['problem'] = 'photo_unreadable';

            return $result;
        }

        return $this->inspect($bytes, $boxMm, $result);
    }

    /**
     * Measure, strip, and encode.
     */
    private function inspect(string $bytes, ?array $boxMm, array $result): array
    {
        $result['bytes'] = strlen($bytes);

        if ($result['bytes'] > self::MAX_PHOTO_BYTES) {
            $result['problem'] = 'photo_too_large';

            return $result;
        }

        /*
         * getimagesizefromstring is core PHP, not GD — the same reasoning as
         * QrCodeService: this has to work on a shared-hosting build with no
         * image extension. It reads the header only; it does not decode.
         */
        $info = @getimagesizefromstring($bytes);

        if ($info === false || empty($info[0]) || empty($info[1])) {
            $result['problem'] = 'photo_unreadable';

            return $result;
        }

        $mime = strtolower((string) ($info['mime'] ?? ''));

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            $result['problem'] = 'photo_unsupported_format';

            return $result;
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $result['dimensions'] = ['width' => $width, 'height' => $height];

        $box = $this->normaliseBox($boxMm);
        $dpiH = (int) floor($width / ($box['width'] / 25.4));
        $dpiV = (int) floor($height / ($box['height'] / 25.4));
        $result['dpi'] = ['horizontal' => $dpiH, 'vertical' => $dpiV];

        if (min($dpiH, $dpiV) < self::MIN_COMFORTABLE_DPI) {
            $result['warnings'][] = sprintf(
                'Photo is %dx%d, which prints at about %d dpi in a %.1fx%.1fmm window. It will look soft; %d dpi or better is comfortable.',
                $width,
                $height,
                min($dpiH, $dpiV),
                $box['width'],
                $box['height'],
                self::MIN_COMFORTABLE_DPI
            );
        }

        /*
         * A landscape photo in a portrait window gets cropped by the card's
         * own CSS (object-fit), which usually takes the top off a head. Worth
         * saying out loud before 400 of them are printed.
         */
        $photoRatio = $width / max($height, 1);
        $boxRatio = $box['width'] / max($box['height'], 0.01);

        if ($photoRatio > $boxRatio * 1.35 || $photoRatio < $boxRatio * 0.65) {
            $result['warnings'][] = 'Photo aspect ratio is well outside the card\'s photo window; it will be cropped noticeably.';
        }

        if ($result['bytes'] > self::LARGE_PHOTO_BYTES) {
            $result['warnings'][] = sprintf(
                'Photo is %.1f MB. Large photos make the print run slow and the PDF heavy.',
                $result['bytes'] / 1048576
            );
        }

        try {
            $clean = $this->stripper->strip($bytes, $mime);
        } catch (\Throwable $e) {
            // Stripping is a hardening step, not a correctness one. If the
            // walker trips on an unusual container, print the card rather than
            // fail the run — but say so, because the metadata then travels.
            Log::warning('ID card photo metadata could not be stripped; embedding the original bytes.', [
                'error' => $e->getMessage(),
            ]);
            $clean = $bytes;
            $result['warnings'][] = 'Embedded metadata could not be stripped from this photo.';
        }

        $result['ok'] = true;
        $result['bytes'] = strlen($clean);
        // Fingerprint the stripped bytes: that is what actually goes on the
        // card, so it is what a later "which cards used the old photo?" asks.
        $result['fingerprint'] = hash('sha256', $clean);
        $result['data_uri'] = 'data:' . $mime . ';base64,' . base64_encode($clean);

        return $result;
    }

    /** @return array{width:float,height:float} */
    private function normaliseBox(?array $boxMm): array
    {
        $width = (float) ($boxMm['width'] ?? self::DEFAULT_BOX_MM['width']);
        $height = (float) ($boxMm['height'] ?? self::DEFAULT_BOX_MM['height']);

        return [
            'width' => $width > 0 ? $width : self::DEFAULT_BOX_MM['width'],
            'height' => $height > 0 ? $height : self::DEFAULT_BOX_MM['height'],
        ];
    }

    /*
     * There is deliberately no placeholder image here.
     *
     * The obvious move — ship a silhouette as a data URI — does not survive
     * the pipeline: HtmlSanitizer allows only raster data URIs (an SVG can
     * carry script, so it is refused), and a hand-built PNG would be a binary
     * blob in a service class for no gain. Instead a card with no photo
     * renders an empty, clearly-marked window from the template's own CSS,
     * which the shipped design does with `.photo-missing`. That also keeps the
     * look of a photoless card in the school's hands rather than ours.
     */

    /**
     * Plain-English text for a problem code, for the admin UI.
     */
    public static function describeProblem(string $problem): string
    {
        return match ($problem) {
            'no_photo' => 'No passport photo on file.',
            'photo_not_found' => 'The stored photo file is missing from the server.',
            'photo_unreadable' => 'The photo file could not be read or is corrupt.',
            'photo_unsupported_format' => 'The photo is not a JPEG, PNG, WebP or GIF.',
            'photo_too_large' => 'The photo file is larger than 8 MB — re-upload a smaller copy.',
            'remote_photo' => 'The photo is stored as a web address. Upload the image file itself so it can be embedded in the PDF.',
            'no_name' => 'The holder has no name on record.',
            default => 'The photo could not be prepared for printing.',
        };
    }
}
