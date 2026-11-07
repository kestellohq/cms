<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Storage;

use Kestello\Cms\Cms;
use Kestello\Contracts\Storage\Disk;
use Kestello\Contracts\Storage\StorageException;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Storage\LocalDisk;
use Kestello\Framework\Storage\ReadOnlyDisk;

/**
 * Round-trips probe files through a disk: write, stream, read back, list,
 * copy, move, presigned download (fetched over HTTP for remote disks),
 * delete. Everything happens under ".kestello-test-…/" and is removed.
 */
final class StorageTestCommand extends Command
{
    protected string $name = 'storage:test';
    protected string $description = 'Check a disk end to end (default: every disk)';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('The CMS is not booted.');
        $names = isset($arguments[0]) ? [$arguments[0]] : $cms->storage->names();
        $failed = 0;
        foreach ($names as $name) {
            $this->output->line("  {$name}");
            try {
                $disk = $cms->storage->disk($name);
                if ($disk instanceof ReadOnlyDisk) {
                    iterator_to_array($disk->list(''), false);
                    $this->output->success('    ok  read-only: listing works, writes skipped');
                    continue;
                }
                $this->check($disk, $cms->container->make(HttpClient::class));
            } catch (\Throwable $e) {
                $failed++;
                $this->output->error('    FAILED: ' . $e->getMessage());
            }
        }

        return $failed > 0 ? 1 : 0;
    }

    private function check(Disk $disk, HttpClient $http): void
    {
        $dir = '.kestello-test-' . bin2hex(random_bytes(4)) . '/';
        $body = 'Kestello storage probe ' . bin2hex(random_bytes(8)) . "\n";
        $big = random_bytes(3 * 1024 * 1024 + 17);
        $step = function (string $label, callable $fn): void {
            $t = microtime(true);
            $fn();
            $this->output->line(sprintf('    ok  %-34s %5d ms', $label, (int) ((microtime(true) - $t) * 1000)));
        };
        $assert = static function (bool $ok, string $what): void {
            if (!$ok) {
                throw new StorageException($what);
            }
        };

        try {
            $step('put', fn() => $disk->put($dir . 'probe.txt', $body));
            $step('exists / size / type', function () use ($disk, $dir, $body, $assert) {
                $assert($disk->exists($dir . 'probe.txt'), 'exists() is false after put');
                $assert(!$disk->exists($dir . 'missing.txt'), 'exists() is true for a missing file');
                $assert($disk->size($dir . 'probe.txt') === strlen($body), 'size mismatch');
                $assert(str_starts_with((string) $disk->mimeType($dir . 'probe.txt'), 'text/plain'), 'content type not text/plain');
            });
            $step('get + checksum', function () use ($disk, $dir, $body, $assert) {
                $assert($disk->get($dir . 'probe.txt') === $body, 'read-back differs');
                $assert($disk->checksum($dir . 'probe.txt') === md5($body), 'checksum differs');
            });
            $step('putStream / readStream (3 MB)', function () use ($disk, $dir, $big, $assert) {
                $in = fopen('php://temp', 'w+');
                fwrite($in, $big);
                rewind($in);
                $disk->putStream($dir . 'sub/big.bin', $in);
                fclose($in);
                $out = $disk->readStream($dir . 'sub/big.bin');
                $assert(hash('sha256', (string) stream_get_contents($out)) === hash('sha256', $big), 'streamed read-back differs');
                fclose($out);
            });
            $step('list', function () use ($disk, $dir, $assert) {
                $all = array_map(static fn($f) => $f->path, iterator_to_array($disk->list($dir), false));
                sort($all);
                $assert($all === [$dir . 'probe.txt', $dir . 'sub/big.bin'], 'listing: ' . implode(', ', $all));
                $top = array_map(static fn($f) => $f->path, iterator_to_array($disk->list($dir, false), false));
                $assert($top === [$dir . 'probe.txt'], 'non-recursive listing: ' . implode(', ', $top));
            });
            $step('copy / move', function () use ($disk, $dir, $body, $assert) {
                $disk->copy($dir . 'probe.txt', $dir . 'copy.txt');
                $disk->move($dir . 'copy.txt', $dir . 'moved.txt');
                $assert(!$disk->exists($dir . 'copy.txt') && $disk->get($dir . 'moved.txt') === $body, 'copy/move');
            });
            $step('temporary URL', function () use ($disk, $dir, $body, $http, $assert) {
                try {
                    $url = $disk->temporaryUrl($dir . 'probe.txt', 300);
                } catch (StorageException $e) {
                    $this->output->line('        (' . $e->getMessage() . ')');
                    return;
                }
                if ($disk instanceof LocalDisk) {
                    $assert(str_contains($url, '/system/files?'), 'unexpected URL ' . $url);
                    return; // served by this site; not fetched from the CLI
                }
                $res = ExecutionContext::run('core', fn() => $http->get($url, ['allow_private' => true, 'timeout' => 20]));
                $assert($res->status === 200 && $res->body === $body, "presigned GET returned HTTP {$res->status}");
            });
            $step('delete / deleteDirectory', function () use ($disk, $dir, $assert) {
                $assert($disk->delete($dir . 'moved.txt'), 'delete() returned false');
                $assert(!$disk->delete($dir . 'moved.txt'), 'second delete() returned true');
                $assert($disk->deleteDirectory($dir) === 2, 'deleteDirectory count');
                $assert(iterator_to_array($disk->list($dir), false) === [], 'files left after deleteDirectory');
            });
        } finally {
            try {
                $disk->deleteDirectory($dir);
            } catch (\Throwable) {
            }
        }
    }
}
