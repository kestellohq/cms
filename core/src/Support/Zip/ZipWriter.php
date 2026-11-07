<?php

declare(strict_types=1);

namespace Kestello\Support\Zip;

/**
 * Streams entries into a new archive (deflate, data descriptors, UTF-8
 * names). Written to "<path>.tmp" and renamed on close(), so a failed run
 * never leaves a half archive behind.
 *
 * ZIP64 is used only where an archive needs it: an entry of nearly 4 GB or
 * more (known from its file size), an entry starting past 4 GB, or more than
 * 65,534 entries. Everything else stays classic ZIP, which every tool reads.
 *
 * @api
 */
final class ZipWriter
{
    /** The largest value a classic 4-byte field holds; ZIP64 takes over at it. */
    private const int MAX32 = 0xFFFFFFFF;

    /** Files this large get a ZIP64 local header: deflate may grow them past 4 GB. */
    private const int ZIP64_FILE = 0xF0000000;

    /** @var resource */
    private mixed $out;
    /** @var resource central directory records, kept on disk past a few MB */
    private mixed $central;
    private string $tmp;
    private int $offset = 0;
    private int $count = 0;
    private string $lastHash = '';
    /** @var array<string, true> */
    private array $names = [];
    private bool $closed = false;

    /** @internal use Zip::create() */
    public function __construct(private readonly string $path, private readonly bool $zip64 = false, private readonly ?string $hash = null)
    {
        if ($hash !== null && !in_array($hash, hash_algos(), true)) {
            throw new ZipException("Unknown hash algorithm \"{$hash}\".");
        }
        $this->tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $out = @fopen($this->tmp, 'xb');
        $central = fopen('php://temp/maxmemory:' . (8 << 20), 'w+b');
        if ($out === false || $central === false) {
            throw new ZipException("Cannot create {$path}.");
        }
        $this->out = $out;
        $this->central = $central;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            @fclose($this->out);
            @unlink($this->tmp);
        }
        if (is_resource($this->central)) {
            fclose($this->central);
        }
    }

    public function addString(string $name, string $contents, ?int $modified = null): self
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $contents);
        rewind($stream);
        $this->addStream($name, $stream, $modified);
        fclose($stream);

        return $this;
    }

    public function addFile(string $file, string $name, bool $compress = true): self
    {
        $in = @fopen($file, 'rb');
        if ($in === false) {
            throw new ZipException("Cannot read {$file}.");
        }
        try {
            // Only "private or not" is kept: 0600 for files others can't read, 0644 for the rest.
            $this->addStream($name, $in, (int) filemtime($file), $compress, ((int) @fileperms($file) & 0004) === 0 ? 0600 : 0644, (int) filesize($file));
        } finally {
            fclose($in);
        }

        return $this;
    }

    /**
     * Add a directory tree under $prefix ("my-plugin/"). Symlinks are skipped.
     *
     * @param (callable(string $relativePath): bool)|null $filter return false to skip
     */
    public function addDirectory(string $dir, string $prefix = '', ?callable $filter = null): self
    {
        $dir = rtrim((string) realpath($dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            throw new ZipException("Not a directory: {$dir}");
        }
        $prefix = $prefix === '' ? '' : rtrim($prefix, '/') . '/';
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            if ($filter === null || $filter($relative)) {
                $files[] = $relative;
            }
        }
        sort($files);
        foreach ($files as $relative) {
            $this->addFile($dir . '/' . $relative, $prefix . $relative);
        }

        return $this;
    }

    /**
     * @param resource $stream
     * @param int|null $size the stream's length when known: one of nearly 4 GB or more needs ZIP64 from its first byte
     */
    public function addStream(string $name, mixed $stream, ?int $modified = null, bool $compress = true, int $mode = 0644, ?int $size = null): self
    {
        $this->guard($name);
        [$time, $date] = self::dosTime($modified ?? time());
        $method = $compress ? 8 : 0;
        $flags = 0x0808; // bit 3: sizes in data descriptor; bit 11: UTF-8 names
        $start = $this->offset;
        $large = $this->zip64 || ($size ?? 0) >= self::ZIP64_FILE;

        // A large entry says so up front: sizes in a ZIP64 extra field (filled in by
        // the data descriptor, which then carries 8-byte sizes).
        $this->write($large
            ? pack('VvvvvvVVVvv', 0x04034b50, 45, $flags, $method, $time, $date, 0, self::MAX32, self::MAX32, strlen($name), 20) . $name . pack('vvPP', 0x0001, 16, 0, 0)
            : pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $time, $date, 0, 0, 0, strlen($name), 0) . $name);

        $crc = hash_init('crc32b');
        $digest = $this->hash !== null ? hash_init($this->hash) : null;
        $size = 0;
        $csize = 0;
        $deflate = $compress ? deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]) : null;
        while (!feof($stream)) {
            $chunk = (string) fread($stream, 1 << 20);
            if ($chunk === '') {
                continue;
            }
            hash_update($crc, $chunk);
            if ($digest !== null) {
                hash_update($digest, $chunk);
            }
            $size += strlen($chunk);
            $data = $deflate !== null ? deflate_add($deflate, $chunk, ZLIB_NO_FLUSH) : $chunk;
            $csize += strlen($data);
            $this->write($data);
        }
        if ($deflate !== null) {
            $tail = deflate_add($deflate, '', ZLIB_FINISH);
            $csize += strlen($tail);
            $this->write($tail);
        }
        if (!$large && ($size >= self::MAX32 || $csize >= self::MAX32)) {
            throw new ZipException("\"{$name}\" is 4 GB or more: pass its size to addStream(), or use addFile().");
        }
        $crcValue = (int) hexdec(hash_final($crc));
        $this->lastHash = $digest !== null ? hash_final($digest) : '';
        $this->write($large ? pack('VVPP', 0x08074b50, $crcValue, $csize, $size) : pack('VVVV', 0x08074b50, $crcValue, $csize, $size));

        // The central record: any field that doesn't fit (or every one, for a large entry)
        // reads 0xFFFFFFFF and comes in the ZIP64 extra field, in the order the format sets.
        $extra = '';
        foreach ([$size, $csize, $start] as $value) {
            if ($large || $value >= self::MAX32) {
                $extra .= pack('P', $value);
            }
        }
        $wide = static fn(int $value): int => $large || $value >= self::MAX32 ? self::MAX32 : $value;
        $record = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            0x031E,
            $extra === '' ? 20 : 45,
            $flags,
            $method,
            $time,
            $date,
            $crcValue,
            $wide($csize),
            $wide($size),
            strlen($name),
            $extra === '' ? 0 : 4 + strlen($extra),
            0,
            0,
            0,
            ((0100000 | ($mode === 0600 ? 0600 : 0644)) << 16),
            $wide($start),
        ) . $name . ($extra === '' ? '' : pack('vv', 0x0001, strlen($extra)) . $extra);
        if (fwrite($this->central, $record) !== strlen($record)) {
            throw new ZipException('Write failed (disk full?).');
        }
        $this->count++;

        return $this;
    }

    /** The hex digest of the last entry added, when the archive was created with a hash algorithm; '' otherwise. */
    public function lastHash(): string
    {
        return $this->lastHash;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $cdStart = $this->offset;
        rewind($this->central);
        while (!feof($this->central)) {
            $this->write((string) fread($this->central, 1 << 20));
        }
        fclose($this->central);
        $cdSize = $this->offset - $cdStart;
        $count = $this->count;
        if ($this->zip64 || $count >= 0xFFFF || $cdSize >= self::MAX32 || $cdStart >= self::MAX32) {
            // ZIP64 end of central directory record, then its locator; the classic
            // record below points readers at them with its maxed-out fields.
            $record = $this->offset;
            $this->write(pack('VPvvVVPPPP', 0x06064b50, 44, 0x031E, 45, 0, 0, $count, $count, $cdSize, $cdStart));
            $this->write(pack('VVPV', 0x07064b50, 0, $record, 1));
            $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0, 0xFFFF, 0xFFFF, self::MAX32, self::MAX32, 0));
        } else {
            $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $cdSize, $cdStart, 0));
        }
        fclose($this->out);
        $this->closed = true;
        if (!@rename($this->tmp, $this->path)) {
            @unlink($this->tmp);
            throw new ZipException("Cannot write {$this->path}.");
        }
    }

    private function guard(string $name): void
    {
        if ($this->closed) {
            throw new ZipException('The archive is closed.');
        }
        if (Zip::safeName($name) === null || str_ends_with($name, '/')) {
            throw new ZipException("Invalid entry name \"{$name}\".");
        }
        if (isset($this->names[$name])) {
            throw new ZipException("Duplicate entry \"{$name}\".");
        }
        $this->names[$name] = true;
    }

    private function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        if (fwrite($this->out, $bytes) !== strlen($bytes)) {
            throw new ZipException('Write failed (disk full?).');
        }
        $this->offset += strlen($bytes);
    }

    /** @return array{0: int, 1: int} */
    private static function dosTime(int $ts): array
    {
        $d = getdate(max($ts, 315532800)); // DOS time starts in 1980

        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }
}
