<?php

declare(strict_types=1);

namespace Kestello\Support\Zip;

/**
 * Reads an archive's central directory and inflates entries with limits.
 *
 * @api
 */
final class ZipReader
{
    /** @var resource */
    private mixed $in;
    /** @var array<string, ZipEntry> */
    private array $entries = [];

    /** @internal use Zip::open() */
    public function __construct(private readonly string $path, int $maxEntries = 20_000)
    {
        $in = @fopen($path, 'rb');
        if ($in === false) {
            throw new ZipException("Cannot open {$path}.");
        }
        $this->in = $in;
        $this->readDirectory($maxEntries);
    }

    public function __destruct()
    {
        if (is_resource($this->in)) {
            fclose($this->in);
        }
    }

    /** @return list<ZipEntry> */
    public function entries(): array
    {
        return array_values($this->entries);
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** Total uncompressed size the headers claim (verified while extracting). */
    public function declaredSize(): int
    {
        return array_sum(array_map(static fn(ZipEntry $e) => $e->size, $this->entries));
    }

    /** Read one entry into memory (at most $maxBytes). */
    public function read(string $name, int $maxBytes = 16 << 20): string
    {
        $entry = $this->entries[$name] ?? throw new ZipException("No entry \"{$name}\".");
        $out = fopen('php://temp', 'w+');
        $this->inflate($entry, $out, $maxBytes);
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * Extract everything (or the entries $filter accepts) into $dir.
     *
     * @param (callable(ZipEntry): bool)|null $filter
     * @return list<string> extracted relative paths
     */
    public function extractTo(string $dir, int $maxBytes = 512 << 20, int $maxRatio = 200, ?callable $filter = null): array
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ZipException("Cannot create {$dir}.");
        }
        $root = (string) realpath($dir);
        $budget = $maxBytes;
        $written = [];
        foreach ($this->entries as $entry) {
            if ($filter !== null && !$filter($entry)) {
                continue;
            }
            if ($entry->isSymlink) {
                throw new ZipException("Symlink entries are not allowed (\"{$entry->name}\").");
            }
            if ($entry->encrypted) {
                throw new ZipException("Encrypted entries are not supported (\"{$entry->name}\").");
            }
            $target = $root . '/' . rtrim($entry->name, '/');
            if ($entry->isDirectory) {
                if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new ZipException("Cannot create {$entry->name}.");
                }
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new ZipException("Cannot create the folder for {$entry->name}.");
            }
            $realParent = (string) realpath($parent);
            if ($realParent !== $root && !str_starts_with($realParent . '/', $root . '/')) {
                throw new ZipException("\"{$entry->name}\" leaves the target folder.");
            }
            if (is_link($target)) {
                throw new ZipException("\"{$entry->name}\" would overwrite a symlink.");
            }
            $limit = min($budget, $entry->compressedSize > 0 ? max(1 << 20, $entry->compressedSize * $maxRatio) : 1 << 20);
            $out = @fopen($target, 'wb');
            if ($out === false) {
                throw new ZipException("Cannot write {$entry->name}.");
            }
            try {
                $budget -= $this->inflate($entry, $out, $limit);
            } catch (\Throwable $e) {
                fclose($out);
                @unlink($target);
                throw $e;
            }
            fclose($out);
            // A file that was private when archived (not readable by others) stays private.
            if ($entry->mode !== 0 && ($entry->mode & 0004) === 0) {
                @chmod($target, 0600);
            }
            if ($entry->modified > 0) {
                @touch($target, $entry->modified);
            }
            $written[] = $entry->name;
        }

        return $written;
    }

    /** @param resource $out @return int bytes written */
    private function inflate(ZipEntry $entry, mixed $out, int $limit): int
    {
        if ($entry->encrypted) {
            throw new ZipException("Encrypted entries are not supported (\"{$entry->name}\").");
        }
        if ($entry->size > $limit) {
            throw new ZipException("\"{$entry->name}\" is larger than allowed.");
        }
        fseek($this->in, $entry->offset);
        $header = (string) fread($this->in, 30);
        if (strlen($header) !== 30 || unpack('V', $header)[1] !== 0x04034b50) {
            throw new ZipException("Corrupt local header for \"{$entry->name}\".");
        }
        $h = unpack('vnameLen/vextraLen', substr($header, 26, 4));
        fseek($this->in, $entry->offset + 30 + $h['nameLen'] + $h['extraLen']);

        $inflate = match ($entry->method) {
            0 => null,
            8 => inflate_init(ZLIB_ENCODING_RAW),
            default => throw new ZipException("Unsupported compression method {$entry->method} for \"{$entry->name}\"."),
        };
        $crc = hash_init('crc32b');
        $remaining = $entry->compressedSize;
        $total = 0;
        while ($remaining > 0) {
            $chunk = (string) fread($this->in, min(1 << 16, $remaining));
            if ($chunk === '') {
                throw new ZipException("Truncated data for \"{$entry->name}\".");
            }
            $remaining -= strlen($chunk);
            $data = $inflate !== null ? @inflate_add($inflate, $chunk, $remaining === 0 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH) : $chunk;
            if ($data === false) {
                throw new ZipException("Corrupt data for \"{$entry->name}\".");
            }
            $total += strlen($data);
            if ($total > $limit) {
                throw new ZipException("\"{$entry->name}\" inflates beyond the allowed size (zip bomb?).");
            }
            hash_update($crc, $data);
            fwrite($out, $data);
        }
        if ($total !== $entry->size || (int) hexdec(hash_final($crc)) !== $entry->crc) {
            throw new ZipException("Checksum mismatch for \"{$entry->name}\".");
        }

        return $total;
    }

    private function readDirectory(int $maxEntries): void
    {
        $size = (int) fstat($this->in)['size'];
        if ($size < 22) {
            throw new ZipException('Not a zip archive.');
        }
        $tailLen = min($size, 65535 + 22);
        fseek($this->in, $size - $tailLen);
        $tail = (string) fread($this->in, $tailLen);
        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false) {
            throw new ZipException('Not a zip archive (no end of central directory).');
        }
        $eocd = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset/vcommentLen', substr($tail, $pos, 22));
        if ($eocd['disk'] !== 0 || $eocd['entries'] !== $eocd['diskEntries']) {
            throw new ZipException('Multi-volume archives are not supported.');
        }
        if ($eocd['entries'] === 0xFFFF || $eocd['cdSize'] === 0xFFFFFFFF || $eocd['cdOffset'] === 0xFFFFFFFF) {
            $eocd = $this->zip64End($size - $tailLen + $pos);
        }
        if ($eocd['entries'] > $maxEntries) {
            throw new ZipException("Too many entries ({$eocd['entries']}).");
        }
        if ($eocd['cdOffset'] < 0 || $eocd['cdSize'] < 0 || $eocd['cdOffset'] + $eocd['cdSize'] > $size) {
            throw new ZipException('Corrupt central directory.');
        }

        // Record by record from the file: a directory of a million entries is no single string in memory.
        fseek($this->in, $eocd['cdOffset']);
        $left = $eocd['cdSize'];
        for ($i = 0; $i < $eocd['entries']; $i++) {
            $head = $left >= 46 ? (string) fread($this->in, 46) : '';
            if (strlen($head) !== 46 || unpack('V', $head)[1] !== 0x02014b50) {
                throw new ZipException('Corrupt central directory entry.');
            }
            $r = unpack('vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnameLen/vextraLen/vcommentLen/vdisk/vinternal/Vexternal/Voffset', substr($head, 4, 42));
            $tailBytes = $r['nameLen'] + $r['extraLen'] + $r['commentLen'];
            $left -= 46 + $tailBytes;
            $rest = $tailBytes > 0 && $left >= 0 ? (string) fread($this->in, $tailBytes) : '';
            if (strlen($rest) !== $tailBytes) {
                throw new ZipException('Corrupt central directory entry.');
            }
            $name = substr($rest, 0, $r['nameLen']);
            if ($r['size'] === 0xFFFFFFFF || $r['csize'] === 0xFFFFFFFF || $r['offset'] === 0xFFFFFFFF) {
                $r = self::zip64Sizes($r, substr($rest, $r['nameLen'], $r['extraLen']), $name);
            }
            if (Zip::safeName($name) === null) {
                throw new ZipException('Unsafe entry name "' . addcslashes($name, "\0..\37") . '".');
            }
            if (isset($this->entries[$name])) {
                throw new ZipException("Duplicate entry \"{$name}\".");
            }
            $unix = ($r['madeBy'] >> 8) === 3 ? ($r['external'] >> 16) & 0xFFFF : 0;
            $this->entries[$name] = new ZipEntry(
                name: $name,
                size: $r['size'],
                compressedSize: $r['csize'],
                method: $r['method'],
                crc: $r['crc'],
                offset: $r['offset'],
                isDirectory: str_ends_with($name, '/'),
                isSymlink: ($unix & 0170000) === 0120000,
                encrypted: ($r['flags'] & 0x1) === 1,
                modified: self::fromDos($r['time'], $r['date']),
                mode: $unix & 0777,
            );
        }
    }

    /**
     * The ZIP64 end of central directory record, found through the locator just before the classic one.
     *
     * @return array{entries: int, cdSize: int, cdOffset: int}
     */
    private function zip64End(int $classicAt): array
    {
        $locator = '';
        if ($classicAt >= 20) {
            fseek($this->in, $classicAt - 20);
            $locator = (string) fread($this->in, 20);
        }
        if (strlen($locator) !== 20 || unpack('V', $locator)[1] !== 0x07064b50) {
            throw new ZipException('Corrupt archive: the ZIP64 end record is missing.');
        }
        $l = unpack('Vsig/Vdisk/Poffset/Vdisks', $locator);
        if ($l['disk'] !== 0 || $l['disks'] !== 1) {
            throw new ZipException('Multi-volume archives are not supported.');
        }
        if ($l['offset'] < 0 || $l['offset'] > $classicAt - 20 - 56) {
            throw new ZipException('Corrupt archive: the ZIP64 end record is out of place.');
        }
        fseek($this->in, $l['offset']);
        $record = (string) fread($this->in, 56);
        if (strlen($record) !== 56 || unpack('V', $record)[1] !== 0x06064b50) {
            throw new ZipException('Corrupt archive: the ZIP64 end record is missing.');
        }
        $e = unpack('Vsig/Psize/vmadeBy/vneeded/Vdisk/VcdDisk/PdiskEntries/Pentries/PcdSize/PcdOffset', $record);
        if ($e['disk'] !== 0 || $e['cdDisk'] !== 0 || $e['entries'] !== $e['diskEntries']) {
            throw new ZipException('Multi-volume archives are not supported.');
        }
        if ($e['entries'] < 0) {
            throw new ZipException("Too many entries ({$e['entries']}).");
        }

        return ['entries' => $e['entries'], 'cdSize' => $e['cdSize'], 'cdOffset' => $e['cdOffset']];
    }

    /**
     * Sizes and offset that didn't fit their 4-byte fields, from the entry's ZIP64 extra field.
     *
     * @param array<string, int> $r a central directory record
     * @return array<string, int>
     */
    private static function zip64Sizes(array $r, string $extra, string $name): array
    {
        $at = 0;
        while ($at + 4 <= strlen($extra)) {
            ['id' => $id, 'len' => $len] = unpack('vid/vlen', substr($extra, $at, 4));
            if ($id === 0x0001) {
                $field = substr($extra, $at + 4, $len);
                $p = 0;
                foreach (['size', 'csize', 'offset'] as $key) {
                    if ($r[$key] !== 0xFFFFFFFF) {
                        continue;
                    }
                    if ($p + 8 > strlen($field)) {
                        throw new ZipException("Corrupt ZIP64 field for \"{$name}\".");
                    }
                    $r[$key] = unpack('P', substr($field, $p, 8))[1];
                    $p += 8;
                    if ($r[$key] < 0) {
                        throw new ZipException("\"{$name}\" is too large.");
                    }
                }

                return $r;
            }
            $at += 4 + $len;
        }
        throw new ZipException("Corrupt ZIP64 field for \"{$name}\".");
    }

    private static function fromDos(int $time, int $date): int
    {
        $ts = @mktime($time >> 11, ($time >> 5) & 0x3F, ($time & 0x1F) * 2, ($date >> 5) & 0x0F, $date & 0x1F, ($date >> 9) + 1980);

        return $ts === false ? 0 : $ts;
    }
}
