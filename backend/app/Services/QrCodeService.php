<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Support\Facades\Log;

/**
 * Result-verification QR codes, generated entirely on this server.
 *
 * Three constraints shape this, and together they rule out every off-the-shelf
 * shortcut:
 *
 *  1. **No network.** The PDF renderer runs with remote fetching disabled — a
 *     school-authored report card template must never make our server request
 *     a URL of its choosing at print time. Calling a hosted QR API from PHP
 *     instead would still mean a Nigerian school's connection sits between a
 *     proprietor and a printed report card, and would hand a third party the
 *     verification token of every result we issue.
 *  2. **No image extension.** GD and Imagick are both absent from plenty of
 *     the shared-hosting PHP builds this product has to run on, so the PNG is
 *     assembled by hand. It only needs zlib, which is core.
 *  3. **Raster, not SVG.** dompdf can render SVG via php-svg-lib, but a
 *     hand-built PNG has no parser between the matrix and the page, which is
 *     what you want for the one element on a report card that must be
 *     scannable years later.
 *
 * Still fail-soft: if anything goes wrong the card prints the verification URL
 * as text, which verifies the result just as well, only with more typing.
 */
class QrCodeService
{
    /** Pixels per QR module. 4 keeps a ~150px code at typical versions. */
    private const MODULE_SIZE = 4;

    /** Quiet zone in modules. The spec requires 4; scanners rely on it. */
    private const QUIET_ZONE = 4;

    /**
     * @return string|null a `data:image/png;base64,…` URI, or null if the code
     *                     could not be produced
     */
    public function dataUriFor(string $verificationUrl, int $moduleSize = self::MODULE_SIZE): ?string
    {
        try {
            // Level M survives the creasing, photocopying and phone-camera
            // glare a report card in a Nigerian school bag actually meets.
            $matrix = Encoder::encode($verificationUrl, ErrorCorrectionLevel::M())->getMatrix();

            if (!$matrix instanceof ByteMatrix) {
                return null;
            }

            return 'data:image/png;base64,' . base64_encode($this->matrixToPng($matrix, $moduleSize));
        } catch (\Throwable $e) {
            Log::warning('QR code generation failed; the report card will print the verification URL as text.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Render the QR matrix as a 1-bit greyscale PNG.
     *
     * Written out by hand rather than through an image library: see the class
     * docblock. A QR code is pure black and white, so bit depth 1 keeps a
     * typical code near 300 bytes even before compression — which matters
     * when it is embedded as a data URI in every one of 400 report cards.
     */
    private function matrixToPng(ByteMatrix $matrix, int $moduleSize): string
    {
        $modules = $matrix->getWidth();
        $side = ($modules + self::QUIET_ZONE * 2) * $moduleSize;

        // One scanline per row: a filter byte, then one bit per pixel.
        $bytesPerRow = (int) ceil($side / 8);
        $raw = '';

        for ($y = 0; $y < $side; $y++) {
            $row = array_fill(0, $bytesPerRow, 0xFF); // 1 = white
            $moduleY = intdiv($y, $moduleSize) - self::QUIET_ZONE;

            if ($moduleY >= 0 && $moduleY < $modules) {
                for ($x = 0; $x < $side; $x++) {
                    $moduleX = intdiv($x, $moduleSize) - self::QUIET_ZONE;

                    if ($moduleX < 0 || $moduleX >= $modules) {
                        continue;
                    }

                    if ($matrix->get($moduleX, $moduleY)) {
                        // Clear the bit: 0 = black.
                        $row[intdiv($x, 8)] &= ~(0x80 >> ($x % 8)) & 0xFF;
                    }
                }
            }

            $raw .= "\x00" . pack('C*', ...$row); // filter type 0 (None)
        }

        // IHDR: width, height, bit depth 1, colour type 0 (greyscale).
        $ihdr = pack('NNCCCCC', $side, $side, 1, 0, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', $ihdr)
            . $this->pngChunk('IDAT', gzcompress($raw, 9))
            . $this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
