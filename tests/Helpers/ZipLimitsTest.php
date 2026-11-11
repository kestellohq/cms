<?php

declare(strict_types=1);

namespace Kestello\Tests\Helpers;

use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Zip\Zip;
use Kestello\Support\Zip\ZipException;

final class ZipLimitsTest extends TestCase
{
    public function testArchivesWeWriteCanAlwaysBeReadBack(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = Zip::create($path);
        for ($i = 0; $i <= 20_000; $i++) {
            $zip->addString("f/{$i}.txt", '');
        }
        $zip->close();
        try {
            $this->assertThrows(ZipException::class, fn() => Zip::open($path), 'unknown archives keep the low default');
            $this->assertSame(20_001, count(Zip::open($path, Zip::MAX_ENTRIES)->entries()));
        } finally {
            unlink($path);
        }
    }

    public function testMoreEntriesThanClassicZipHoldsUseZip64(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = Zip::create($path);
        for ($i = 0; $i < 70_000; $i++) {
            $zip->addString("f/{$i}.txt", (string) $i);
        }
        $zip->close();
        try {
            $read = Zip::open($path, Zip::MAX_ENTRIES);
            $this->assertCount(70_000, $read->entries());
            $this->assertSame('69999', $read->read('f/69999.txt'));
            $this->assertOthersRead($path, 70_000, 'f/69999.txt', '69999');
        } finally {
            unlink($path);
        }
    }

    public function testZip64EntriesRoundTrip(): void
    {
        // Every entry laid out as one over 4 GB would be (sizes and offsets in the ZIP64 extra field,
        // 8-byte data descriptors, ZIP64 end records): the paths a 5 GB media library takes.
        $dir = $this->tempDir();
        $bin = random_bytes(200_000);
        file_put_contents("{$dir}/photo.jpg", $bin);
        Zip::create("{$dir}/big.zip", zip64: true)
            ->addString('notes.txt', str_repeat("line\n", 10_000))
            ->addFile("{$dir}/photo.jpg", 'files/photo.jpg', compress: false)
            ->close();
        $read = Zip::open("{$dir}/big.zip");
        $this->assertSame(str_repeat("line\n", 10_000), $read->read('notes.txt'));
        $this->assertSame($bin, $read->read('files/photo.jpg'));
        $this->assertSame(['notes.txt', 'files/photo.jpg'], $read->extractTo("{$dir}/out"));
        $this->assertSame($bin, file_get_contents("{$dir}/out/files/photo.jpg"));
        $this->assertOthersRead("{$dir}/big.zip", 2, 'files/photo.jpg', $bin);

        Zip::create("{$dir}/small.zip")->addString('a.txt', 'a')->close();
        $this->assertFalse(str_contains((string) file_get_contents("{$dir}/small.zip"), "PK\x06\x06"), 'small archives stay classic, for old tools');
    }

    public function testTheWriterHashesWhatItReadsOnce(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.bin", $bytes = random_bytes(3 << 20));
        $zip = Zip::create("{$dir}/h.zip", hash: 'sha256');
        $zip->addFile("{$dir}/a.bin", 'a.bin');
        $this->assertSame(hash('sha256', $bytes), $zip->lastHash(), 'no second read of the file to hash it');
        $zip->addString('b.txt', 'b');
        $this->assertSame(hash('sha256', 'b'), $zip->lastHash());
        $zip->close();
        $this->assertSame('', Zip::create("{$dir}/n.zip")->addString('c', 'c')->lastHash(), 'only when asked');
    }

    /** ext-zip and Info-ZIP, when installed, read what we wrote. */
    private function assertOthersRead(string $path, int $count, string $name, string $contents): void
    {
        if (class_exists(\ZipArchive::class)) {
            $za = new \ZipArchive();
            // Not ZipArchive::CHECKCONS: libzip 1.12 refuses an archive with ZIP64 end records whose entries
            // carry 4-byte data descriptors, which APPNOTE 4.3.9 allows for entries without a ZIP64 field
            // (Info-ZIP, Python's zipfile and libzip's normal open all read them). unzip -t below checks
            // every entry's CRC.
            $this->assertTrue($za->open($path) === true, 'ext-zip opens it');
            $this->assertSame([$count, $contents], [$za->numFiles, $za->getFromName($name)]);
            $za->close();
        }
        if (is_executable('/usr/bin/unzip')) {
            exec('/usr/bin/unzip -tqq ' . escapeshellarg($path) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, 'unzip -t: ' . implode("\n", array_slice($out, 0, 5)));
        }
    }
}
