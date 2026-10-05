<?php

namespace App\Services\IdCard;

use App\Services\ReportCard\TemplateException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Card artwork a school brings with it: the front or back it had designed in
 * Canva or at a print shop, exported as an image (TASK 18).
 *
 * Every upload is decoded and redrawn by the server as a fresh JPEG. Nothing
 * from the original file survives except its pixels — no metadata, no
 * embedded payload — and the result is sized for printing at about 300dpi.
 */
class CardBackgroundService
{
    /** Card size at 300dpi, landscape: what a sharp print needs. */
    public const PRINT_PX = [1011, 638];

    /** Below this the artwork prints visibly soft; below the floor it is refused. */
    private const WARN_LONG_EDGE = 750;
    private const MIN_LONG_EDGE = 400;
    private const MAX_LONG_EDGE = 1400;

    /** @var array<string,string> per-request cache of data URIs */
    private array $uris = [];

    /**
     * @return array{ref:string,width:int,height:int,warnings:array<int,string>}
     *
     * @throws TemplateException
     */
    public function store(UploadedFile $file, int $schoolId, string $orientation): array
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new TemplateException('Upload the design as a PNG or JPG image. In Canva: Share → Download → PNG.');
        }
        [$w, $h] = $info;
        $long = max($w, $h);
        if ($long < self::MIN_LONG_EDGE) {
            throw new TemplateException("This image is only {$w}×{$h} pixels — too small to print. Export it at least ".self::PRINT_PX[0].' pixels wide.');
        }

        $src = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($file->getRealPath()) : @imagecreatefromjpeg($file->getRealPath());
        if (! $src) {
            throw new TemplateException('That image could not be read. Export it again as PNG or JPG.');
        }

        $warnings = [];
        $portrait = $orientation === 'portrait';
        if (($h > $w) !== $portrait && abs($w - $h) > 0.05 * $long) {
            $warnings[] = 'This image is '.($h > $w ? 'upright' : 'sideways').' but the card is '.($portrait ? 'upright' : 'sideways').'. It will be stretched to fit.';
        } else {
            $target = $portrait ? 54 / 85.6 : 85.6 / 54;
            if (abs($w / $h - $target) / $target > 0.06) {
                $warnings[] = 'The image is not the same shape as an ID card, so it will be stretched slightly. Design at 85.6 × 54mm (or 1011 × 638 pixels).';
            }
        }
        if ($long < self::WARN_LONG_EDGE) {
            $warnings[] = "At {$w}×{$h} pixels this will print a little soft. ".self::PRINT_PX[0].' pixels wide prints sharp.';
        }

        // Redraw onto white (flattening any transparency) at no more than print size.
        $scale = min(1, self::MAX_LONG_EDGE / $long);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, 84);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        $ref = Str::lower(Str::random(32));
        Storage::disk('local')->put($this->path($schoolId, $ref), $jpeg);

        return ['ref' => $ref, 'width' => $nw, 'height' => $nh, 'warnings' => $warnings];
    }

    public function exists(int $schoolId, string $ref): bool
    {
        return $this->valid($ref) && Storage::disk('local')->exists($this->path($schoolId, $ref));
    }

    public function bytes(int $schoolId, string $ref): ?string
    {
        return $this->exists($schoolId, $ref) ? Storage::disk('local')->get($this->path($schoolId, $ref)) : null;
    }

    /** The image as a data URI for the stylesheet, or '' if it is gone. */
    public function dataUri(int $schoolId, string $ref): string
    {
        $key = "{$schoolId}:{$ref}";
        if (! isset($this->uris[$key])) {
            $bytes = $this->bytes($schoolId, $ref);
            $this->uris[$key] = $bytes === null ? '' : 'data:image/jpeg;base64,'.base64_encode($bytes);
        }

        return $this->uris[$key];
    }

    private function valid(string $ref): bool
    {
        return (bool) preg_match('/^[a-z0-9]{32}$/', $ref);
    }

    private function path(int $schoolId, string $ref): string
    {
        return "id-card-designs/{$schoolId}/{$ref}.jpg";
    }
}
