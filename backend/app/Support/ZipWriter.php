<?php

namespace App\Support;

/**
 * A minimal ZIP writer.
 *
 * `ext-zip` is not a given: it is absent from this project's PHP build and
 * from the stock `php:fpm` images, so a data export that depended on
 * `ZipArchive` would work on one developer's machine and 500 on a server. The
 * only thing needed here is zlib, which is compiled in everywhere, because
 * `gzdeflate()` produces exactly the raw DEFLATE stream that ZIP method 8
 * stores.
 *
 * Deliberately small: entries are written as they are added, no ZIP64, no
 * encryption, no directories. That covers "hand a school secretary a file
 * they can double-click in Windows" and nothing more.
 */
class ZipWriter
{
    /** @var resource */
    private $handle;

    /** @var array<int,array<string,mixed>> */
    private array $entries = [];

    private int $offset = 0;

    public function __construct(string $path)
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }

        $this->handle = $handle;
    }

    public function add(string $name, string $contents): void
    {
        $crc = crc32($contents);
        $uncompressed = strlen($contents);

        /*
         * Level 6 rather than 9. These archives are almost entirely CSV, where
         * the last three levels buy a couple of percent for several times the
         * CPU — and this runs on a queue worker a school shares with its
         * report cards.
         */
        $deflated = gzdeflate($contents, 6);

        /*
         * Deflate can be larger than the input on tiny or already-random
         * files. ZIP allows method 0, so store those verbatim rather than
         * shipping a "compressed" entry that is bigger than the original.
         */
        if ($deflated === false || strlen($deflated) >= $uncompressed) {
            $deflated = $contents;
            $method = 0;
        } else {
            $method = 8;
        }

        $compressed = strlen($deflated);
        [$dosTime, $dosDate] = $this->dosTimestamp();

        // Bit 11 tells the reader the filename is UTF-8.
        $flags = 0x0800;

        $header = pack('VvvvvvVVVvv',
            0x04034b50,
            20,
            $flags,
            $method,
            $dosTime,
            $dosDate,
            $crc,
            $compressed,
            $uncompressed,
            strlen($name),
            0,
        );

        fwrite($this->handle, $header . $name . $deflated);

        $this->entries[] = [
            'name' => $name,
            'crc' => $crc,
            'compressed' => $compressed,
            'uncompressed' => $uncompressed,
            'offset' => $this->offset,
            'method' => $method,
            'time' => $dosTime,
            'date' => $dosDate,
            'flags' => $flags,
        ];

        $this->offset += strlen($header) + strlen($name) + $compressed;
    }

    /**
     * Write the central directory and close the file.
     *
     * Until this runs the file on disk is not a readable archive, so callers
     * must treat a failure between `add` and `finish` as a failed export.
     */
    public function finish(): void
    {
        $directoryOffset = $this->offset;
        $directory = '';

        foreach ($this->entries as $entry) {
            $directory .= pack('VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                $entry['flags'],
                $entry['method'],
                $entry['time'],
                $entry['date'],
                $entry['crc'],
                $entry['compressed'],
                $entry['uncompressed'],
                strlen($entry['name']),
                0,
                0,
                0,
                0,
                0x20, // archive
                $entry['offset'],
            ) . $entry['name'];
        }

        fwrite($this->handle, $directory);

        fwrite($this->handle, pack('VvvvvVVv',
            0x06054b50,
            0,
            0,
            count($this->entries),
            count($this->entries),
            strlen($directory),
            $directoryOffset,
            0,
        ));

        fclose($this->handle);
    }

    /**
     * @return array{0:int,1:int} DOS time, DOS date
     */
    private function dosTimestamp(): array
    {
        $now = getdate();

        $time = ($now['hours'] << 11) | ($now['minutes'] << 5) | (intdiv($now['seconds'], 2));
        $date = (($now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];

        return [$time, $date];
    }
}
