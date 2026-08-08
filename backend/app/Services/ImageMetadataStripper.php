<?php

namespace App\Services;

/**
 * Removes embedded metadata from uploaded images without re-encoding them.
 *
 * This exists for two reasons.
 *
 * **NDPA data minimisation (doc §12).** A teacher photographing a diagram off
 * a whiteboard with a phone ships GPS coordinates, the device serial, and a
 * timestamp inside the JPEG's EXIF block. That is personal data about a
 * member of staff attached to an exam question, collected by accident and
 * kept forever. We do not need it, so we do not store it.
 *
 * **Payload surface.** EXIF/XMP blocks are a classic place to smuggle script
 * or polyglot content past a naive image check.
 *
 * Stripping is done by walking the container format directly rather than
 * decoding and re-encoding through GD, because: GD is not guaranteed present
 * on a school's shared-hosting PHP build, re-encoding visibly degrades the
 * fine lines in a scanned geometry diagram, and a decode/encode round-trip on
 * attacker-supplied bytes is a larger attack surface than a byte walk.
 */
class ImageMetadataStripper
{
    /**
     * @return string the image bytes with metadata removed (or the original
     *                bytes when the format carries none we can safely drop)
     */
    public function strip(string $bytes, string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => $this->stripJpeg($bytes),
            'image/png' => $this->stripPng($bytes),
            'image/webp' => $this->stripWebp($bytes),
            default => $bytes,
        };
    }

    /**
     * Drop APP1..APP15 (EXIF, XMP, Photoshop IRB, ICC beyond APP2) and COM
     * comment segments. APP0/JFIF is kept — some decoders expect it.
     */
    private function stripJpeg(string $bytes): string
    {
        $length = strlen($bytes);
        if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return $bytes;
        }

        $out = "\xFF\xD8";
        $i = 2;

        while ($i + 3 < $length) {
            if ($bytes[$i] !== "\xFF") {
                // Desynchronised — bail out and keep the remainder verbatim
                // rather than risk corrupting a valid image.
                return $out . substr($bytes, $i);
            }

            $marker = ord($bytes[$i + 1]);

            // Start of scan: everything after this is entropy-coded data.
            if ($marker === 0xDA) {
                return $out . substr($bytes, $i);
            }

            // Standalone markers carry no length field.
            if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD9) || $marker === 0x01) {
                $out .= substr($bytes, $i, 2);
                $i += 2;
                continue;
            }

            $segmentLength = unpack('n', substr($bytes, $i + 2, 2))[1];
            if ($segmentLength < 2 || $i + 2 + $segmentLength > $length) {
                return $out . substr($bytes, $i);
            }

            $isMetadata = ($marker >= 0xE1 && $marker <= 0xEF) || $marker === 0xFE;

            if (!$isMetadata) {
                $out .= substr($bytes, $i, 2 + $segmentLength);
            }

            $i += 2 + $segmentLength;
        }

        return $out;
    }

    /**
     * Drop ancillary text/metadata chunks. Critical chunks (IHDR, PLTE, IDAT,
     * IEND) and rendering-relevant ancillary chunks (tRNS, gAMA, sRGB, pHYs)
     * are kept so the image still looks right.
     */
    private function stripPng(string $bytes): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        $length = strlen($bytes);

        if ($length < 8 || substr($bytes, 0, 8) !== $signature) {
            return $bytes;
        }

        $drop = ['eXIf', 'tEXt', 'iTXt', 'zTXt', 'tIME', 'dSIG'];
        $out = $signature;
        $i = 8;

        while ($i + 8 <= $length) {
            $chunkLength = unpack('N', substr($bytes, $i, 4))[1];
            $type = substr($bytes, $i + 4, 4);
            $total = 12 + $chunkLength; // length + type + data + crc

            if ($chunkLength > $length || $i + $total > $length) {
                return $out . substr($bytes, $i);
            }

            if (!in_array($type, $drop, true)) {
                $out .= substr($bytes, $i, $total);
            }

            $i += $total;

            if ($type === 'IEND') {
                break;
            }
        }

        return $out;
    }

    /**
     * Extended WebP files are RIFF containers; EXIF and XMP live in their own
     * chunks. Drop those and rewrite the RIFF size. Simple (lossy/lossless)
     * WebP files have no metadata chunks and pass through untouched.
     */
    private function stripWebp(string $bytes): string
    {
        $length = strlen($bytes);

        if ($length < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return $bytes;
        }

        $drop = ['EXIF', 'XMP '];
        $payload = '';
        $i = 12;
        $changed = false;

        while ($i + 8 <= $length) {
            $type = substr($bytes, $i, 4);
            $chunkLength = unpack('V', substr($bytes, $i + 4, 4))[1];
            $padded = $chunkLength + ($chunkLength % 2); // chunks are even-aligned
            $total = 8 + $padded;

            if ($i + $total > $length) {
                $payload .= substr($bytes, $i);
                break;
            }

            if (in_array($type, $drop, true)) {
                $changed = true;
            } else {
                $payload .= substr($bytes, $i, $total);
            }

            $i += $total;
        }

        if (!$changed) {
            return $bytes;
        }

        return 'RIFF' . pack('V', 4 + strlen($payload)) . 'WEBP' . $payload;
    }
}
