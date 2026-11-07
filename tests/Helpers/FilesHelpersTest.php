<?php

declare(strict_types=1);

namespace Kestello\Tests\Helpers;

use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Csv;
use Kestello\Support\Mime;
use Kestello\Support\Process;
use Kestello\Support\Zip\Zip;
use Kestello\Support\Zip\ZipException;

/** H2: Mime, Process, Zip, Csv. */
final class FilesHelpersTest extends TestCase
{
    public function testMime(): void
    {
        $this->assertSame('image/jpeg', Mime::fromExtension('Photo.JPG'));
        $this->assertSame('image/webp', Mime::fromExtension('webp'));
        $this->assertSame('application/octet-stream', Mime::fromExtension('file.unknownext'));
        $this->assertSame(['jpg', 'jpeg'], Mime::extensions('image/jpeg; charset=binary'));

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $this->assertSame('image/png', Mime::detectBytes($png));
        $this->assertSame('image/webp', Mime::detectBytes("RIFF\x00\x00\x00\x00WEBPVP8 "));
        $this->assertSame('image/avif', Mime::detectBytes("\x00\x00\x00\x1cftypavif\x00\x00\x00\x00"));
        $this->assertSame('text/csv', Mime::detectBytes("a,b\n1,2\n", 'export.csv'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', Mime::detectBytes("PK\x03\x04" . str_repeat("\0", 40), 'cv.docx'));

        $file = $this->tempDir() . '/fake.jpg';
        file_put_contents($file, '<?php system($_GET["c"]);');
        $this->assertFalse(Mime::matches($file, 'fake.jpg'), 'PHP disguised as a JPEG');
        file_put_contents($file, $png);
        $this->assertFalse(Mime::matches($file, 'fake.jpg'), 'PNG named .jpg');
        $this->assertTrue(Mime::matches($file, 'real.png'));

        foreach (['shell.php', 'shell.php.jpg', 'x.PHTML', '.htaccess', 'a.phar', 'page.html', 'icon.svgz', '.user.ini'] as $bad) {
            $this->assertTrue(Mime::isDangerous($bad), $bad);
        }
        foreach (['photo.jpg', 'report.pdf', 'archive.tar.gz', 'php-tips.md'] as $ok) {
            $this->assertFalse(Mime::isDangerous($ok), $ok);
        }
        $this->assertTrue(Mime::isInlineSafe('image/png'));
        $this->assertFalse(Mime::isInlineSafe('image/svg+xml'), 'SVG can run script');
    }

    public function testProcess(): void
    {
        if (!Process::available()) {
            return;
        }
        $php = PHP_BINARY;
        $r = Process::run([$php, '-r', 'echo $argv[1], "|", stream_get_contents(STDIN); fwrite(STDERR, "warn"); exit(3);', 'a b; rm -rf /'], input: 'stdin!');
        $this->assertSame('a b; rm -rf /|stdin!', $r->output, 'arguments are passed verbatim, never through a shell');
        $this->assertSame('warn', $r->errorOutput);
        $this->assertSame(3, $r->exitCode);
        $this->assertFalse($r->ok());
        $this->assertThrows(\RuntimeException::class, fn() => $r->throw());

        $slow = Process::run([$php, '-r', 'sleep(5);'], timeout: 0.5);
        $this->assertTrue($slow->timedOut);
        $this->assertTrue($slow->seconds < 3, 'killed on timeout');

        $this->assertSame(0, Process::run([$php, '-r', 'echo getcwd();'], cwd: '/tmp')->exitCode);
        $this->assertNotNull(Process::which(basename($php)) ?? Process::which('sh'));
        $this->assertNull(Process::which('../bin/sh'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Process::run(['echo', "a\0b"]));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Process::run([]));
    }

    public function testZipRoundTripAndInterop(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/src/sub", 0755, true);
        file_put_contents("{$dir}/src/a.txt", str_repeat("alpha\n", 5000));
        file_put_contents("{$dir}/src/sub/b.bin", $bin = random_bytes(70000));
        symlink('/etc/passwd', "{$dir}/src/link");

        Zip::create("{$dir}/x.zip")->addDirectory("{$dir}/src", 'pkg')->addString('pkg/ünicode name.txt', 'ü')->close();
        $zip = Zip::open("{$dir}/x.zip");
        $names = array_map(static fn($e) => $e->name, $zip->entries());
        $this->assertSame(['pkg/a.txt', 'pkg/sub/b.bin', 'pkg/ünicode name.txt'], $names, 'symlinks skipped, sorted');
        $this->assertSame($bin, $zip->read('pkg/sub/b.bin'));
        $this->assertSame(3, count($zip->extractTo("{$dir}/out")));
        $this->assertSame('ü', file_get_contents("{$dir}/out/pkg/ünicode name.txt"));
        $this->assertSame([], glob("{$dir}/x.zip.tmp-*"));

        if (class_exists(\ZipArchive::class)) {
            $za = new \ZipArchive();
            $this->assertTrue($za->open("{$dir}/x.zip") === true, 'ext-zip reads our archives');
            $this->assertSame($bin, $za->getFromName('pkg/sub/b.bin'));
            $za->close();
            $za = new \ZipArchive();
            $za->open("{$dir}/theirs.zip", \ZipArchive::CREATE);
            $za->addFromString('dir/file.txt', 'from ext-zip');
            $za->addEmptyDir('empty');
            $za->close();
            $this->assertSame('from ext-zip', Zip::open("{$dir}/theirs.zip")->read('dir/file.txt'));
        }
    }

    public function testZipRefusesHostileArchives(): void
    {
        $dir = $this->tempDir();
        foreach (['../evil.php', '/etc/cron.d/x', 'a/../../x', 'C:\\x', "a\0b"] as $name) {
            $this->assertThrows(ZipException::class, fn() => Zip::create("{$dir}/w.zip")->addString($name, 'x'), json_encode($name));
            $this->writeRaw("{$dir}/evil.zip", [[$name, 'x']]);
            $this->assertThrows(ZipException::class, fn() => Zip::open("{$dir}/evil.zip"), 'reader: ' . json_encode($name));
        }

        $this->writeRaw("{$dir}/link.zip", [['link', '/etc/passwd', 0120777]]);
        $this->assertThrows(ZipException::class, fn() => Zip::open("{$dir}/link.zip")->extractTo("{$dir}/o1"), 'symlink');

        // 20 MB of zeros compresses to ~20 kB: over the ratio limit and over the byte budget.
        Zip::create("{$dir}/bomb.zip")->addString('zeros.bin', str_repeat("\0", 20 << 20))->close();
        $e = $this->assertThrows(ZipException::class, fn() => Zip::open("{$dir}/bomb.zip")->extractTo("{$dir}/o2", maxBytes: 5 << 20));
        $this->assertContains('larger than allowed', $e->getMessage());
        $this->assertFalse(is_file("{$dir}/o2/zeros.bin"), 'partial file removed');

        // Headers that lie about the size are caught while inflating.
        $this->writeRaw("{$dir}/liar.zip", [['big.bin', str_repeat('A', 3 << 20), null, 10]]);
        $this->assertThrows(ZipException::class, fn() => Zip::open("{$dir}/liar.zip")->extractTo("{$dir}/o3", maxRatio: 10));

        $bytes = (string) file_get_contents("{$dir}/bomb.zip");
        file_put_contents("{$dir}/corrupt.zip", substr_replace($bytes, 'XXXX', 60, 4));
        $this->assertThrows(ZipException::class, fn() => Zip::open("{$dir}/corrupt.zip")->read('zeros.bin', 30 << 20), 'CRC');
        $this->assertThrows(ZipException::class, fn() => Zip::open(__FILE__), 'not a zip');
    }

    public function testCsv(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/in.csv", Csv::BOM . "Name;Email;Note;Name\r\n\"Đorđe; Jr\";dj@example.com;\"multi\nline \"\"quoted\"\"\";x\r\n\r\nAna;ana@example.com\r\n");
        $rows = iterator_to_array(Csv::read("{$dir}/in.csv"), false);
        $this->assertSame([
            ['Name' => 'Đorđe; Jr', 'Email' => 'dj@example.com', 'Note' => "multi\nline \"quoted\"", 'Name_2' => 'x'],
            ['Name' => 'Ana', 'Email' => 'ana@example.com', 'Note' => '', 'Name_2' => ''],
        ], $rows, 'BOM, ; detection, quotes, newlines, padding, duplicate headers');
        $this->assertCount(1, iterator_to_array(Csv::read("{$dir}/in.csv", maxRows: 1), false));
        $this->assertSame([['a', 'b'], ['1', '2']], iterator_to_array(Csv::read(fopen('data://text/plain,a,b%0A1,2', 'r'), header: false), false));

        $out = Csv::toString(['Name', 'Formula', 'Num'], [
            ['Name' => 'Ana', 'Formula' => '=HYPERLINK("http://evil","x")', 'Num' => '-12.5'],
            ['Bob', '@SUM(A1)', null],
        ]);
        $this->assertSame("Name,Formula,Num\r\nAna,\"'=HYPERLINK(\"\"http://evil\"\",\"\"x\"\")\",-12.5\r\nBob,'@SUM(A1),\r\n", $out, 'formulas neutralized, numbers kept');
        $this->assertSame(1, Csv::write("{$dir}/out.csv", ['a'], [['x']], bom: true));
        $this->assertTrue(str_starts_with((string) file_get_contents("{$dir}/out.csv"), Csv::BOM));
        $this->assertSame("\t", Csv::detectDelimiter("a\tb\t\"c,d,e\""));
    }

    /**
     * Hand-built archive (bypasses the writer's own checks).
     *
     * @param list<array{0: string, 1: string, 2?: ?int, 3?: ?int}> $entries name, data, unix mode, declared size override
     */
    private function writeRaw(string $path, array $entries): void
    {
        $body = '';
        $central = '';
        foreach ($entries as $e) {
            [$name, $data] = $e;
            $mode = $e[2] ?? 0100644;
            $deflated = gzdeflate($data);
            $size = $e[3] ?? strlen($data);
            $crc = crc32($data);
            $offset = strlen($body);
            $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0x5821, $crc, strlen($deflated), $size, strlen($name), 0) . $name . $deflated;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x031E, 20, 0, 8, 0, 0x5821, $crc, strlen($deflated), $size, strlen($name), 0, 0, 0, 0, $mode << 16, $offset) . $name;
        }
        file_put_contents($path, $body . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), strlen($body), 0));
    }
}
