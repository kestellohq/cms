<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Cms\Cms;
use Kestello\Contracts\Auth\Principal;
use Kestello\Contracts\Storage\Disk;
use Kestello\Contracts\Storage\FileNotFound;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Settings\Settings;

/**
 * Where the media library keeps its files, how much each role may upload,
 * and moving every file to another disk.
 *
 * A move is resumable and verified. It runs in slices (step()): the queue
 * worker keeps pushing the next slice, or, without a worker, the admin
 * screen and `bin/cms media:move` drive it. Each file's original is read
 * back through a checksum when one is known, every copy's size is checked
 * on the new disk, and only then does the row point at the new disk. Pages
 * build file addresses from those rows, so nothing in content is rewritten.
 * New uploads go to the new disk from the moment a move starts.
 */
final class MediaStorage
{
    /** Disks that hold other things and never the library. */
    public const array RESERVED = ['public', 'private', 'backups'];
    private const string MOVE_KEY = 'media_move';
    private const int MAX_FAILURES_LISTED = 50;

    public function __construct(private readonly Cms $cms) {}

    // ── which disk ──────────────────────────────────────────────────────

    /** The disk new uploads go to. */
    public function current(): string
    {
        $name = (string) Settings::getInstance()->get('media_disk', 'media_storage');

        return $name !== 'media' && $this->usable($name) === null ? $name : 'media';
    }

    /** Why a disk can't hold the library, or null when it can. */
    public function usable(string $name): ?string
    {
        if (in_array($name, self::RESERVED, true) || !in_array($name, $this->cms->storage->names(), true)) {
            return 'There is no such disk for media.';
        }
        $config = (array) $this->cms->storage->config($name);
        if (($config['driver'] ?? 'local') === 'readonly') {
            return 'This disk is read-only.';
        }
        try {
            $this->cms->storage->disk($name)->url('kestello-probe.txt');
        } catch (\Throwable) {
            return 'Files on this disk have no public address, so pages couldn\'t show them. Give it a "url" in config/storage.php.';
        }

        return null;
    }

    /**
     * Every disk that could hold the library, with what it holds now.
     *
     * @return list<array{name: string, driver: string, current: bool, files: int, bytes: int, problem: ?string}>
     */
    public function disks(): array
    {
        $held = [];
        foreach (QueryBuilder::table('media')->select('disk')->selectRaw('COUNT(*) AS files')->selectRaw('SUM(size) AS bytes')->groupBy('disk')->get() as $row) {
            $row = (array) $row;
            $held[(string) $row['disk']] = ['files' => (int) $row['files'], 'bytes' => (int) $row['bytes']];
        }
        $current = $this->current();
        $out = [];
        $names = array_unique([...array_diff($this->cms->storage->names(), self::RESERVED), ...array_keys($held)]);
        foreach ($names as $name) {
            $known = in_array($name, $this->cms->storage->names(), true);
            $out[] = [
                'name' => $name,
                'driver' => $known ? (string) (((array) $this->cms->storage->config($name))['driver'] ?? 'local') : 'missing',
                'current' => $name === $current,
                'files' => $held[$name]['files'] ?? 0,
                'bytes' => $held[$name]['bytes'] ?? 0,
                'problem' => $known ? $this->usable($name) : 'This disk is no longer configured. Its files can\'t be shown.',
            ];
        }
        usort($out, static fn($a, $b) => [$b['current'], $b['files'], $a['name']] <=> [$a['current'], $a['files'], $b['name']]);

        return $out;
    }

    /** Write, read and delete a small file. Null when that works, the problem otherwise. */
    public function probe(string $name): ?string
    {
        $key = '.kestello-probe/' . bin2hex(random_bytes(8)) . '.txt';
        $body = 'kestello ' . bin2hex(random_bytes(8));
        try {
            $disk = $this->cms->storage->disk($name);
            $disk->put($key, $body, ['content_type' => 'text/plain', 'visibility' => 'private']);
            $back = $disk->get($key);
            $disk->delete($key);

            return $back === $body ? null : 'The disk returned something other than what was written.';
        } catch (\Throwable $e) {
            return 'The disk could not be written to: ' . $e->getMessage();
        }
    }

    // ── quotas ──────────────────────────────────────────────────────────

    /** @return array<string, int> role => megabytes (0 or missing: no limit) */
    public function quotas(): array
    {
        $raw = json_decode((string) Settings::getInstance()->get('media_quotas', '{}'), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $role => $mb) {
            if (is_string($role) && is_numeric($mb) && (int) $mb > 0) {
                $out[$role] = min(10_000_000, (int) $mb);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $quotas role => megabytes
     * @return array<string, string> errors by role
     */
    public function setQuotas(array $quotas, ?int $userId): array
    {
        $roles = $this->cms->auth->roles->roles();
        $clean = [];
        $errors = [];
        foreach ($quotas as $role => $mb) {
            if (!is_string($role) || !isset($roles[$role]) || $role === 'admin') {
                $errors[(string) $role] = 'There is no such role (administrators are never limited).';
                continue;
            }
            if ($mb === null || $mb === '' || $mb === 0) {
                continue;
            }
            if (!is_int($mb) || $mb < 1 || $mb > 10_000_000) {
                $errors[$role] = 'A whole number of megabytes, or empty for no limit.';
                continue;
            }
            $clean[$role] = $mb;
        }
        if ($errors !== []) {
            return $errors;
        }
        ksort($clean);
        Settings::getInstance()->set('media_quotas', json_encode((object) $clean, JSON_THROW_ON_ERROR), 'media_storage');
        $this->cms->audit->record('media.storage.quotas', null, ['quotas' => $clean], $userId);

        return [];
    }

    /** Megabytes this person may hold, or null for no limit. */
    public function quotaFor(Principal $p): ?int
    {
        if (\Kestello\Cms\Demo\Demo::on($this->cms->config)) {
            return intdiv(\Kestello\Cms\Demo\Demo::MEDIA_BYTES, 1_048_576);    // every visitor is the one demo account: this is the whole demo's room
        }
        $role = (string) ($p->user['role'] ?? '');
        if ($role === '' || $role === 'admin' || in_array('*', $p->capabilities, true)) {
            return null;
        }

        return $this->quotas()[$role] ?? null;
    }

    /** Bytes a person's uploads take: kept files plus unfinished uploads. */
    public function usedBy(int $userId): int
    {
        $kept = (int) QueryBuilder::table('media')->where('uploaded_by', $userId)->sum('size');
        $pending = (int) QueryBuilder::table('uploads')->where('user_id', $userId)->where('expires_at', '>', gmdate('Y-m-d H:i:s'))->sum('size');

        return $kept + $pending;
    }

    /** Null when $bytes more fit, the message otherwise. */
    public function checkQuota(Principal $p, int $bytes): ?string
    {
        $mb = $p->userId === null ? null : $this->quotaFor($p);
        if ($mb === null) {
            return null;
        }
        $used = $this->usedBy((int) $p->userId);
        if ($used + $bytes <= $mb * 1_048_576) {
            return null;
        }

        return sprintf('This would go over your %s of storage (%s used). Delete files you no longer need, or ask an administrator for more room.', self::human($mb * 1_048_576), self::human($used));
    }

    public static function human(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => round($bytes / 1_073_741_824, 1) . ' GB',
            $bytes >= 1_048_576 => round($bytes / 1_048_576, 1) . ' MB',
            $bytes <= 0 => '0 KB',
            default => max(1, (int) round($bytes / 1024)) . ' KB',
        };
    }

    // ── moving ──────────────────────────────────────────────────────────

    /**
     * The move in progress or the last one.
     *
     * @return array{to: string, keep_source: bool, status: string, total: int, done: int, remaining: int, failed: array<string, string>, started_at: string, finished_at: ?string}|null
     */
    public function move(): ?array
    {
        $raw = json_decode((string) Settings::getInstance()->get(self::MOVE_KEY, ''), true);
        if (!is_array($raw) || !isset($raw['to'], $raw['status'])) {
            return null;
        }
        $raw['failed'] = (array) ($raw['failed'] ?? []);
        $raw['remaining'] = $raw['status'] === 'running' ? $this->remaining($raw) : 0;

        return $raw;
    }

    /**
     * Start moving every file to $to.
     *
     * @return array<string, mixed> the move
     * @throws MediaException
     */
    public function startMove(string $to, bool $keepSource, ?int $userId): array
    {
        if (($m = $this->move()) !== null && $m['status'] === 'running') {
            throw MediaException::conflict('A move to "' . $m['to'] . '" is already running. Let it finish or stop it first.');
        }
        if (($problem = $this->usable($to)) !== null) {
            throw MediaException::invalid(['to' => [$problem]]);
        }
        if (($problem = $this->probe($to)) !== null) {
            throw MediaException::invalid(['to' => [$problem]]);
        }
        $total = QueryBuilder::table('media')->where('disk', '!=', $to)->count();
        $state = ['to' => $to, 'keep_source' => $keepSource, 'status' => $total > 0 ? 'running' : 'done', 'total' => $total, 'done' => 0, 'failed' => [], 'started_at' => gmdate('c'), 'finished_at' => $total > 0 ? null : gmdate('c'), 'started_by' => $userId];
        $settings = Settings::getInstance();
        $settings->set('media_disk', $to, 'media_storage');      // new uploads go straight to the new disk
        $this->save($state);
        $this->cms->audit->record('media.storage.move_started', null, ['to' => $to, 'files' => $total, 'keep_source' => $keepSource], $userId);
        if ($total > 0) {
            $this->queueNext();
        }

        return $this->move() ?? $state;
    }

    /** Stop after the file being moved now. Files already moved stay moved. */
    public function cancelMove(?int $userId): void
    {
        $m = $this->move();
        if ($m === null || $m['status'] !== 'running') {
            return;
        }
        $m['status'] = 'stopped';
        $m['finished_at'] = gmdate('c');
        $this->save($m);
        $this->cms->audit->record('media.storage.move_stopped', null, ['to' => $m['to'], 'done' => $m['done']], $userId);
    }

    /**
     * Move the next files, for at most $seconds. Returns the move afterwards.
     *
     * @return array<string, mixed>|null
     */
    public function step(int $maxFiles = 25, float $seconds = 15.0): ?array
    {
        $m = $this->move();
        if ($m === null || $m['status'] !== 'running') {
            return $m;
        }
        $lock = $this->cache()?->lock('media-move', (int) ceil($seconds) + 120);
        if ($lock !== null && !$lock->acquire()) {
            return $m;            // another worker or tab is on it
        }
        $start = microtime(true);
        $moved = 0;
        try {
            $rows = $this->pending($m)->orderBy('id')->limit(max(1, $maxFiles))->get();
            foreach ($rows as $row) {
                $media = Media::query()->find((int) ((array) $row)['id']);
                if ($media === null) {
                    continue;
                }
                try {
                    if ($this->moveOne($media, (string) $m['to'], (bool) $m['keep_source'])) {
                        $m['done']++;
                        $moved++;
                    }
                } catch (\Throwable $e) {
                    if (count($m['failed']) < self::MAX_FAILURES_LISTED) {
                        $m['failed'][(string) $media->getKey()] = mb_substr($media->filename . ': ' . $e->getMessage(), 0, 300);
                    } else {
                        $m['failed']['more'] = 'More files could not be moved.';
                    }
                }
                // re-read: someone may have pressed stop
                $now = $this->move();
                if ($now === null || $now['status'] !== 'running' || microtime(true) - $start > $seconds) {
                    $m['status'] = $now['status'] ?? 'stopped';
                    $m['finished_at'] = $now['finished_at'] ?? null;
                    break;
                }
            }
            if ($m['status'] === 'running' && $this->remaining($m) === 0) {
                $m['status'] = 'done';
                $m['finished_at'] = gmdate('c');
                $this->cms->audit->record('media.storage.move_finished', null, ['to' => $m['to'], 'done' => $m['done'], 'failed' => count($m['failed'])], null);
            }
            unset($m['remaining']);
            $this->save($m);
        } finally {
            $lock?->release();
        }
        if ($moved > 0) {
            $this->purgePages();
        }
        if ($m['status'] === 'running') {
            $this->queueNext();
        }

        return $this->move();
    }

    /**
     * Copy one file, its stored sizes and a PDF's first page to $to, check
     * them there, then point the row at $to. False when it was already there.
     *
     * @throws \RuntimeException
     */
    public function moveOne(Media $media, string $to, bool $keepSource = false): bool
    {
        $from = $media->disk;
        if ($from === $to) {
            return false;
        }
        $src = $this->cms->storage->disk($from);
        $dst = $this->cms->storage->disk($to);
        $headers = ['visibility' => 'public', 'cache_control' => 'public, max-age=31536000, immutable'];

        try {
            $this->copy($src, $dst, $media->path, $headers + ['content_type' => $media->mime], $media->checksum, (int) $media->size);
        } catch (FileNotFound $e) {
            // moved (and its old files removed) or edited by another pass since this one read the row
            $now = QueryBuilder::table('media')->where('id', $media->getKey())->first();
            if ($now === null || $now['disk'] !== $from || $now['path'] !== $media->path) {
                return false;
            }
            throw $e;
        }
        $copied = [$media->path];

        $meta = $media->meta ?? [];
        $poster = is_string($meta['poster']['path'] ?? null) ? $meta['poster']['path'] : null;
        if ($poster !== null) {
            try {
                $this->copy($src, $dst, $poster, $headers + ['content_type' => 'image/jpeg']);
                $copied[] = $poster;
            } catch (FileNotFound) {
                unset($meta['poster']);          // made again on demand by media:variants
            }
        }
        $original = is_string($meta['original']['path'] ?? null) ? $meta['original']['path'] : null;
        if ($original !== null) {
            // an edited image's original: private while it is edited
            $this->copy($src, $dst, $original, ['visibility' => 'private'] + $headers + ['content_type' => $media->mime], $meta['original']['checksum'] ?? null, (int) ($meta['original']['size'] ?? 0));
            $copied[] = $original;
        }
        $variants = QueryBuilder::table('media_variants')->where('media_id', $media->getKey())->get();
        $gone = [];
        foreach ($variants as $v) {
            $v = (array) $v;
            try {
                $this->copy($src, $dst, (string) $v['path'], $headers + ['content_type' => 'image/' . $v['format']], null, (int) $v['size']);
                $copied[] = (string) $v['path'];
            } catch (FileNotFound) {
                $gone[] = (int) $v['id'];        // missing already: made again when asked for
            }
        }

        // Only if the row is still the one copied: an image edited meanwhile has a new
        // file and meta that aren't on $to. It stays put and is moved on the next pass.
        $same = QueryBuilder::table('media')->where('id', $media->getKey())->where('disk', $from)->where('path', $media->path);
        $media->checksum === null ? $same->whereNull('checksum') : $same->where('checksum', $media->checksum);
        $updated = $same->update(['disk' => $to, 'meta' => $meta === [] ? null : json_encode($meta, JSON_THROW_ON_ERROR), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        if ($updated === 0) {
            // Another pass may have moved it to $to already: those files are now its own.
            $copied = QueryBuilder::table('media')->where('id', $media->getKey())->value('disk') === $to ? [] : $copied;
            foreach ($copied as $path) {
                try {
                    $dst->delete($path);     // edited meanwhile: drop the copies of the old version
                } catch (\Throwable) {
                }
            }
            return false;
        }
        if ($gone !== []) {
            QueryBuilder::table('media_variants')->whereIn('id', $gone)->delete();
        }
        if (!$keepSource) {
            foreach ($copied as $path) {
                try {
                    $src->delete($path);
                } catch (\Throwable) {
                    // left behind on the old disk; harmless
                }
            }
        }

        return true;
    }

    /**
     * Copy through a local file: the checksum (when known) proves the old
     * copy is intact, the size on the new disk proves the upload arrived.
     *
     * @param array<string, string> $options
     */
    private function copy(Disk $src, Disk $dst, string $path, array $options, ?string $checksum = null, ?int $size = null): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kmove');
        try {
            $in = $src->readStream($path);
            $out = fopen($tmp, 'wb');
            $ctx = hash_init('sha256');
            while (!feof($in)) {
                $chunk = fread($in, 1_048_576);
                if ($chunk === false) {
                    throw new \RuntimeException('Reading the file failed.');
                }
                hash_update($ctx, $chunk);
                fwrite($out, $chunk);
            }
            is_resource($in) && fclose($in);
            fclose($out);
            clearstatcache(true, $tmp);
            $bytes = (int) filesize($tmp);
            if ($checksum !== null && $checksum !== '' && !hash_equals($checksum, hash_final($ctx))) {
                throw new \RuntimeException('The stored file doesn\'t match its checksum, so it was left where it is.');
            }
            if ($size !== null && $size > 0 && $bytes !== $size) {
                throw new \RuntimeException("The stored file has {$bytes} bytes instead of {$size}, so it was left where it is.");
            }
            $stream = fopen($tmp, 'rb');
            try {
                $dst->putStream($path, $stream, $options);
            } finally {
                is_resource($stream) && fclose($stream);
            }
            $arrived = $dst->size($path);
            if ($arrived !== $bytes) {
                throw new \RuntimeException("The new disk holds {$arrived} bytes instead of {$bytes}.");
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function pending(array $m): QueryBuilder
    {
        $q = QueryBuilder::table('media')->where('disk', '!=', (string) $m['to']);
        $failed = array_map('intval', array_filter(array_keys((array) ($m['failed'] ?? [])), 'is_numeric'));

        return $failed === [] ? $q : $q->whereNotIn('id', $failed);
    }

    private function remaining(array $m): int
    {
        return $this->pending($m)->count();
    }

    private function save(array $state): void
    {
        unset($state['remaining']);
        Settings::getInstance()->set(self::MOVE_KEY, json_encode($state, JSON_THROW_ON_ERROR), 'media_storage');
    }

    private function queueNext(): void
    {
        if ($this->cms->queue->backend() instanceof \Kestello\Framework\Queue\SyncQueue) {
            return;                // no worker: the admin screen or the CLI drives the steps
        }
        $this->cms->queue->push(new MoveMediaFiles(), queue: 'media');
    }

    private function purgePages(): void
    {
        try {
            $this->cms->container->make(\Kestello\Contracts\Cache\PageCache::class)->purge('all');
        } catch (\Throwable) {
        }
    }

    private function cache(): ?\Kestello\Contracts\Cache\Store
    {
        try {
            $store = $this->cms->drivers->resolve('cache');
        } catch (\Throwable) {
            return null;
        }

        return $store instanceof \Kestello\Contracts\Cache\Store ? $store : null;
    }
}
