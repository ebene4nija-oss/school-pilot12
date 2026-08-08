<?php

namespace Tests\Feature;

use App\Services\QrCodeService;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The result-verification QR is the one thing on a report card that has to
 * still work years later, scanned off a photocopy, by a stranger's phone. The
 * PNG is assembled by hand (no GD, no Imagick, no network — see
 * QrCodeService), so these tests decode it back and check it pixel for pixel
 * rather than trusting that plausible-looking bytes are a valid image.
 */
class QrCodeServiceTest extends TestCase
{
    private const URL = 'https://graceland.schoolpilot.ng/api/v1/verify-result/abc123XYZ';

    private function service(): QrCodeService
    {
        return new QrCodeService();
    }

    private function pngFrom(string $dataUri): string
    {
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);

        return base64_decode(explode(',', $dataUri, 2)[1]);
    }

    public function test_it_produces_a_valid_png()
    {
        $png = $this->pngFrom($this->service()->dataUriFor(self::URL));

        $info = getimagesizefromstring($png);

        $this->assertNotFalse($info, 'Hand-built PNG is not readable as an image.');
        $this->assertSame('image/png', $info['mime']);
        $this->assertSame($info[0], $info[1], 'A QR code must be square.');
    }

    public function test_it_makes_no_network_request()
    {
        // The whole point of generating locally: a school with no internet
        // must still be able to print a verifiable report card, and no
        // verification token should ever reach a third-party host.
        Http::preventStrayRequests();

        $this->assertNotNull($this->service()->dataUriFor(self::URL));
    }

    public function test_the_encoded_pixels_match_the_qr_matrix_exactly()
    {
        $moduleSize = 4;
        $quietZone = 4;

        $matrix = Encoder::encode(self::URL, ErrorCorrectionLevel::M())->getMatrix();
        $pixels = $this->decodeOneBitGreyscalePng(
            $this->pngFrom($this->service()->dataUriFor(self::URL, $moduleSize))
        );

        $modules = $matrix->getWidth();
        $expectedSide = ($modules + $quietZone * 2) * $moduleSize;

        $this->assertCount($expectedSide, $pixels);

        for ($y = 0; $y < $expectedSide; $y++) {
            for ($x = 0; $x < $expectedSide; $x++) {
                $moduleX = intdiv($x, $moduleSize) - $quietZone;
                $moduleY = intdiv($y, $moduleSize) - $quietZone;

                $inCode = $moduleX >= 0 && $moduleX < $modules && $moduleY >= 0 && $moduleY < $modules;
                $expectDark = $inCode && $matrix->get($moduleX, $moduleY) === 1;

                $this->assertSame(
                    $expectDark,
                    $pixels[$y][$x] === 0,
                    "Pixel ({$x},{$y}) does not match the QR matrix."
                );
            }
        }
    }

    public function test_the_quiet_zone_is_present_on_every_edge()
    {
        // Scanners rely on the four-module white border. Without it a code
        // butted against a table cell simply will not read.
        $pixels = $this->decodeOneBitGreyscalePng($this->pngFrom($this->service()->dataUriFor(self::URL)));
        $side = count($pixels);
        $quietPx = 4 * 4;

        for ($i = 0; $i < $side; $i++) {
            for ($q = 0; $q < $quietPx; $q++) {
                $this->assertSame(1, $pixels[$q][$i], 'Top quiet zone is not white.');
                $this->assertSame(1, $pixels[$side - 1 - $q][$i], 'Bottom quiet zone is not white.');
                $this->assertSame(1, $pixels[$i][$q], 'Left quiet zone is not white.');
                $this->assertSame(1, $pixels[$i][$side - 1 - $q], 'Right quiet zone is not white.');
            }
        }
    }

    public function test_different_tokens_produce_different_codes()
    {
        $service = $this->service();

        $this->assertNotEquals(
            $service->dataUriFor(self::URL),
            $service->dataUriFor(self::URL . 'x')
        );
    }

    public function test_generation_is_deterministic_for_the_same_token()
    {
        // A reissued card for the same token must scan identically to the one
        // already in a parent's hands.
        $service = $this->service();

        $this->assertEquals($service->dataUriFor(self::URL), $service->dataUriFor(self::URL));
    }

    /**
     * Minimal decoder for exactly the PNG shape QrCodeService writes:
     * bit depth 1, colour type 0 (greyscale), filter type 0 (None) on every
     * scanline. Returns a [y][x] grid of 0 (black) / 1 (white).
     *
     * @return array<int,array<int,int>>
     */
    private function decodeOneBitGreyscalePng(string $png): array
    {
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8), 'Missing PNG signature.');

        $chunks = [];
        $offset = 8;

        while ($offset + 8 <= strlen($png)) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $length);
            $crc = unpack('N', substr($png, $offset + 8 + $length, 4))[1];

            $this->assertSame(crc32($type . $data), $crc, "Bad CRC on {$type} chunk.");

            $chunks[$type] = ($chunks[$type] ?? '') . $data;
            $offset += 12 + $length;
        }

        $this->assertArrayHasKey('IHDR', $chunks);
        $this->assertArrayHasKey('IDAT', $chunks);
        $this->assertArrayHasKey('IEND', $chunks);

        $header = unpack('Nwidth/Nheight/Cdepth/Ccolour', substr($chunks['IHDR'], 0, 10));
        $this->assertSame(1, $header['depth'], 'Expected 1-bit depth.');
        $this->assertSame(0, $header['colour'], 'Expected greyscale colour type.');

        $raw = gzuncompress($chunks['IDAT']);
        $this->assertNotFalse($raw, 'IDAT is not valid zlib data.');

        $bytesPerRow = (int) ceil($header['width'] / 8);
        $rows = [];

        for ($y = 0; $y < $header['height']; $y++) {
            $start = $y * ($bytesPerRow + 1);
            $this->assertSame(0, ord($raw[$start]), 'Expected filter type 0 (None).');

            $line = substr($raw, $start + 1, $bytesPerRow);
            $row = [];

            for ($x = 0; $x < $header['width']; $x++) {
                $row[$x] = (ord($line[intdiv($x, 8)]) >> (7 - ($x % 8))) & 1;
            }

            $rows[$y] = $row;
        }

        return $rows;
    }
}
