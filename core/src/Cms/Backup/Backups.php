<?php

declare(strict_types=1);

namespace Kestello\Cms\Backup;

use Closure;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Package\Update\Files;
use Kestello\Cms\System\Maintenance;
use Kestello\Contracts\Storage\Disk;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\MigrationRunner;
use Kestello\Framework\Settings\Settings;
use Kestello\Framework\Storage\LocalDisk;
use Kestello\Framework\Support\Semver;
use Kestello\Support\Hash;
use Kestello\Support\Zip\Zip;
use Kestello\Support\Zip\ZipEntry;
use RuntimeException;
use Throwable;

/**
 * Backups of the whole site, and restores (here or on another install).
 *
 * The archive: backup.json, database/<table>.jsonl
 * (engine-neutral rows), files/<section>/… Restores rebuild the schema from
 * migrations, so a MySQL backup restores on Postgres or SQLite.
 *
 * Archives this install made carry an HMAC under the app key. Only those may
 * put plugin and theme folders (code) back from the admin; an archive from
 * elsewhere brings its code only through the CLI (--with-code).
 */
final class Backups
{
    public const int FORMAT = 1;
    public const string PREFIX = 'kestello-backup-';

    /** Short-lived or derived tables: emptied, never backed up. Filter: backup.skip_tables */
    public const array SKIP_TABLES = ['sessions', 'cache', 'jobs', 'job_batches', 'failed_jobs', 'password_resets', 'migrations', 'search_documents'];

    /**
     * Tables a rollback after a failed update keeps as they are: the jobs
     * waiting to run (emails, image sizes) and who is signed in. A restore
     * someone asks for starts them empty, as the backup has none.
     */
    public const array KEEP_ON_ROLLBACK = ['jobs', 'job_batches', 'failed_jobs', 'sessions'];

    /** File sections: archive path => [where, is code]. storage/app/<disk> sections are added per local disk folder. */
    private const array SECTIONS = [
        'content/plugins' => true,
        'content/themes' => true,
        'content/uploads' => false,
        'storage/fonts' => false,
        'storage/forms' => false, // files visitors attached to form submissions
        'storage/plugins' => false,
    ];

    /** Bytes a restore may unpack: what fits on the disk, keeping 256 MB free, and at least 4 GB to try. */
    private static function extractBudget(string $dir): int
    {
        $probe = $dir;
        while (!is_dir($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }
        $free = @disk_free_space($probe);

        return $free === false ? 4 << 30 : max(4 << 30, (int) $free - (256 << 20));
    }

    /** How long maintenance stays on after a restore left the database broken: until someone acts. */
    public const int BROKEN_MINUTES = 7 * 24 * 60;

    /** storage/app folders that never go in a backup */
    private const array SKIP_APP = ['backups', 'uploads-tmp'];

    /** Files that are already compressed: stored, not deflated again */
    private const string STORED = '/\.(jpe?g|png|gif|webp|avif|woff2?|zip|gz|mp4|webm|mp3|pdf)$/i';

    private int $lockDepth = 0;

    private const string NAME = '/^kestello-backup-[a-z0-9-]{1,60}-\d{8}-\d{6}-[a-f0-9]{6}\.zip$/';

    public function __construct(
        private readonly Cms $cms,
        private readonly ?Connection $connection = null,
        private readonly string $diskName = 'backups',
    ) {}

    // ── Listing ─────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> newest first */
    public function all(): array
    {
        $out = [];
        foreach ($this->disk()->list('', false) as $file) {
            $name = basename($file->path);
            if (preg_match(self::NAME, $name) !== 1) {
                continue;
            }
            $meta = $this->disk()->exists($name . '.json') ? json_decode($this->disk()->get($name . '.json'), true) : null;
            $out[] = [
                'name' => $name,
                'size' => $file->size,
                'created_at' => is_array($meta) && is_int($meta['created_at'] ?? null) ? $meta['created_at'] : $file->lastModified,
                'kind' => is_array($meta) ? (string) ($meta['kind'] ?? 'manual') : 'manual',
                'files' => is_array($meta) ? (bool) ($meta['files'] ?? false) : null,
                'core' => is_array($meta) ? (string) ($meta['core'] ?? '') : '',
                'driver' => is_array($meta) ? (string) ($meta['driver'] ?? '') : '',
                'rows' => is_array($meta) ? (int) ($meta['rows'] ?? 0) : 0,
                'note' => is_array($meta) ? (string) ($meta['note'] ?? '') : '',
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at'] ?: strcmp($b['name'], $a['name']));

        return $out;
    }

    public function exists(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1 && $this->disk()->exists($name);
    }

    /**
     * Run $use with a local path to the archive. On a remote disk it is
     * downloaded to storage/tmp first and removed afterwards: a backup holds
     * every row, password hashes included, and must not pile up there.
     *
     * @template T
     * @param Closure(string $path): T $use
     * @return T
     */
    public function withLocalCopy(string $name, Closure $use): mixed
    {
        $path = $this->localPath($name);
        try {
            return $use($path);
        } finally {
            if (str_starts_with(basename($path), 'download-') && dirname($path) === $this->cms->paths->storage('tmp')) {
                @unlink($path);
            }
        }
    }

    /**
     * Restore a backup kept on the backups disk, by name.
     *
     * @param array{code?: bool, safety?: bool} $options as restore()
     * @return array<string, mixed> as restore()
     */
    public function restoreNamed(string $name, array $options = []): array
    {
        return $this->withLocalCopy($name, fn(string $path): array => $this->restore($path, $options));
    }

    /**
     * The archive as a stream, for downloading, straight from its disk.
     *
     * @return array{0: resource, 1: int} the stream and its size in bytes
     */
    public function open(string $name): array
    {
        if (!$this->exists($name)) {
            throw ContentException::invalid(['name' => ['There is no backup with that name.']]);
        }
        $stream = $this->disk()->readStream($name);

        return [$stream, $this->disk()->size($name)];
    }

    /**
     * A local path to read the archive from. A remote disk's archive is
     * downloaded to storage/tmp: prefer withLocalCopy(), which removes it.
     */
    public function localPath(string $name): string
    {
        if (!$this->exists($name)) {
            throw ContentException::invalid(['name' => ['There is no backup with that name.']]);
        }
        $disk = $this->disk();
        if ($disk instanceof LocalDisk) {
            return $disk->root() . '/' . $name;
        }
        $tmp = $this->tmp('download-' . bin2hex(random_bytes(4)) . '.zip');
        $in = $disk->readStream($name);
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not write to storage/tmp.');
        }
        stream_copy_to_stream($in, $out);
        fclose($out);
        if (is_resource($in)) {
            fclose($in);
        }

        return $tmp;
    }

    public function delete(string $name): void
    {
        if (!$this->exists($name)) {
            throw ContentException::invalid(['name' => ['There is no backup with that name.']]);
        }
        $this->disk()->delete($name);
        $this->disk()->delete($name . '.json');
    }

    /**
     * Keep the newest $keep automatic backups of each kind (scheduled, and
     * the safety copies made before a restore or an update); manual ones
     * stay until someone deletes them. Counted per kind, so an evening of
     * updates can't push the scheduled backups out.
     *
     * @return int how many were deleted
     */
    public function prune(int $keep): int
    {
        $removed = 0;
        foreach (['scheduled', 'before-restore', 'before-update'] as $kind) {
            $ofKind = array_values(array_filter($this->all(), static fn(array $b): bool => $b['kind'] === $kind));
            foreach (array_slice($ofKind, max(1, $keep)) as $old) {
                $this->delete($old['name']);
                $removed++;
            }
        }

        return $removed;
    }

    // ── Create ──────────────────────────────────────────────────────

    /**
     * @param array{files?: bool, kind?: string, note?: string} $options
     * @return array<string, mixed> the new backup, as all() lists it
     */
    public function create(array $options = []): array
    {
        $withFiles = $options['files'] ?? true;
        $kind = in_array($options['kind'] ?? 'manual', ['manual', 'scheduled', 'before-restore', 'before-update'], true) ? ($options['kind'] ?? 'manual') : 'manual';

        return $this->locked(function () use ($withFiles, $kind, $options): array {
            $site = $this->siteSlug();
            $name = self::PREFIX . $site . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $work = $this->tmp('backup-' . bin2hex(random_bytes(4)));
            mkdir($work, 0700, true);
            $zipPath = $work . '/' . $name . '.zip';

            try {
                $zip = Zip::create($zipPath, hash: 'sha256');     // each file is read once: hashed as it is written
                $hashes = [];
                $tables = [];
                $dump = new DatabaseDump($this->db());
                $skip = $this->skipTables();
                $dump->snapshot(function () use ($dump, $skip, $work, $zip, &$hashes, &$tables): void {
                    foreach ($dump->tables() as $table) {
                        if (in_array($table, $skip, true) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) !== 1) {
                            continue;
                        }
                        $file = $work . '/' . $table . '.jsonl';
                        $out = fopen($file, 'wb');
                        if ($out === false) {
                            throw new RuntimeException("Could not write {$file}.");
                        }
                        $rows = $dump->export($table, $out);
                        fclose($out);
                        $entry = "database/{$table}.jsonl";
                        $zip->addFile($file, $entry);
                        $hashes[$entry] = $zip->lastHash();
                        @unlink($file);
                        $tables[$table] = ['rows' => $rows, 'columns' => $dump->columns($table)];
                    }
                });

                $sections = [];
                if ($withFiles) {
                    foreach ($this->sections() as $section => $dir) {
                        $count = $this->addTree($zip, $dir, 'files/' . $section, $hashes);
                        $sections[$section] = ['files' => $count, 'code' => self::SECTIONS[$section] ?? false];
                    }
                }

                $manifest = [
                    'format' => self::FORMAT,
                    'created_at' => time(),
                    'kind' => $kind,
                    'note' => mb_substr((string) ($options['note'] ?? ''), 0, 200),
                    'site' => ['name' => (string) Settings::getInstance()->get('site_name', ''), 'url' => (string) $this->cms->config->get('app.url', '')],
                    'core' => $this->cms->version(),
                    'php' => PHP_VERSION,
                    'driver' => $this->db()->driver(),
                    'key' => $this->keyFingerprint(),
                    'packages' => $this->packages(),
                    'migrations' => $this->migrations(),
                    'tables' => $tables,
                    'sections' => $sections,
                    'entries' => $hashes,
                ];
                $body = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $zip->addString('backup.json', $body);
                // proves this install made it (restoring code from the admin needs that)
                $zip->addString('backup.sig', Hash::hmac($body, 'backup.archive'));
                $zip->close();
                // Never report a backup that could not be restored: read it back first.
                if (!Zip::open($zipPath, Zip::MAX_ENTRIES)->has('backup.json')) {
                    throw new RuntimeException('The new archive could not be read back.');
                }

                $this->store($zipPath, $name . '.zip');
                $rows = array_sum(array_column($tables, 'rows'));
                $this->disk()->put($name . '.zip.json', json_encode([
                    'created_at' => $manifest['created_at'], 'kind' => $kind, 'files' => $withFiles, 'core' => $manifest['core'],
                    'driver' => $manifest['driver'], 'rows' => $rows, 'note' => $manifest['note'],
                ], JSON_THROW_ON_ERROR));
            } finally {
                $this->removeTree($work);
            }

            foreach ($this->all() as $backup) {
                if ($backup['name'] === $name . '.zip') {
                    return $backup;
                }
            }

            throw new RuntimeException('The backup was written but is not listed.');
        });
    }

    /**
     * Keep an archive from elsewhere (an upload, for moving a site here)
     * next to this site's backups, after checking it.
     *
     * @return array<string, mixed> as all() lists it
     */
    public function import(string $zipPath): array
    {
        $check = $this->inspect($zipPath);
        $m = $check['manifest'];
        $name = self::PREFIX . 'uploaded-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $copy = $this->tmp($name . '.zip');
        if (!copy($zipPath, $copy)) {
            throw new RuntimeException('Could not copy the upload.');
        }
        $this->store($copy, $name . '.zip');
        @unlink($copy);
        $this->disk()->put($name . '.zip.json', json_encode([
            'created_at' => (int) ($m['created_at'] ?? time()), 'kind' => 'uploaded', 'files' => ($m['sections'] ?? []) !== [], 'core' => (string) $m['core'],
            'driver' => (string) ($m['driver'] ?? ''), 'rows' => array_sum(array_map(static fn($t): int => (int) ($t['rows'] ?? 0), $m['tables'])),
            'note' => mb_substr('From ' . (($m['site']['url'] ?? '') !== '' ? $m['site']['url'] : (($m['site']['name'] ?? '') !== '' ? $m['site']['name'] : 'another site')), 0, 200),
        ], JSON_THROW_ON_ERROR));
        foreach ($this->all() as $backup) {
            if ($backup['name'] === $name . '.zip') {
                return $backup;
            }
        }

        throw new RuntimeException('The upload was stored but is not listed.');
    }

    // ── Inspect and restore ─────────────────────────────────────────

    /**
     * Read and check an archive without changing anything.
     *
     * @return array{manifest: array<string, mixed>, signed: bool, warnings: list<string>}
     * @throws ContentException when it isn't a usable backup
     */
    public function inspect(string $zipPath): array
    {
        try {
            $zip = Zip::open($zipPath, Zip::MAX_ENTRIES);
            if (!$zip->has('backup.json')) {
                throw new RuntimeException('There is no backup.json in it.');
            }
            $body = $zip->read('backup.json', 64 << 20);
            $sig = $zip->has('backup.sig') ? trim($zip->read('backup.sig', 1024)) : '';
        } catch (Throwable $e) {
            throw ContentException::invalid(['file' => ['This is not a Kestello backup: ' . $e->getMessage()]]);
        }
        $manifest = json_decode($body, true);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT || !is_array($manifest['entries'] ?? null) || !is_array($manifest['tables'] ?? null)) {
            throw ContentException::invalid(['file' => ['The backup description (backup.json) is missing parts or has an unknown format.']]);
        }
        $core = (string) ($manifest['core'] ?? '');
        if (!Semver::isValid($core) || Semver::compare($core, $this->cms->version()) > 0) {
            throw ContentException::invalid(['file' => ["This backup comes from Kestello {$core}, newer than this site ({$this->cms->version()}). Update this site first."]]);
        }

        $known = ['backup.json' => true, 'backup.sig' => true];
        foreach ($manifest['entries'] as $entry => $hash) {
            if (!is_string($entry) || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || !self::allowedEntry($entry)) {
                throw ContentException::invalid(['file' => ['The backup lists a file it may not contain: ' . mb_substr((string) $entry, 0, 120)]]);
            }
            $known[$entry] = true;
        }
        $stray = array_filter(array_map(static fn(ZipEntry $e): string => $e->name, $zip->entries()), static fn(string $n): bool => !isset($known[$n]) && !str_ends_with($n, '/'));
        if ($stray !== []) {
            throw ContentException::invalid(['file' => ['The backup holds files it does not list: ' . implode(', ', array_slice(array_values($stray), 0, 5))]]);
        }

        $warnings = [];
        if (($manifest['key'] ?? '') !== $this->keyFingerprint()) {
            $warnings[] = 'This backup comes from a site with a different app key. Encrypted values (mail and storage passwords, plugin secrets, two-factor apps) need that key listed under previous_keys in config/app.php, or must be entered again.';
        }

        return ['manifest' => $manifest, 'signed' => $sig !== '' && Hash::verifyHmac($body, $sig, 'backup.archive'), 'warnings' => $warnings];
    }

    /**
     * Replace this site's database (and files, when the archive has them)
     * with the archive's. A safety backup is taken first and put back if
     * anything fails.
     *
     * @param array{code?: bool, safety?: bool, keep?: list<string>} $options code: also restore plugin and theme folders from an archive this install didn't make;
     *        keep: tables (of SKIP_TABLES) whose current rows stay, e.g. KEEP_ON_ROLLBACK
     * @return array{tables: int, rows: int, skipped_code: bool, missing_tables: list<string>, dropped_columns: array<string, list<string>>, warnings: list<string>, safety: ?string}
     */
    public function restore(string $zipPath, array $options = []): array
    {
        $check = $this->inspect($zipPath);
        $manifest = $check['manifest'];
        $withCode = $check['signed'] || ($options['code'] ?? false);

        return $this->locked(function () use ($zipPath, $manifest, $withCode, $check, $options): array {
            $safety = ($options['safety'] ?? true) ? $this->create(['files' => false, 'kind' => 'before-restore', 'note' => 'Taken automatically before a restore'])['name'] : null;
            $maintenance = new Maintenance($this->cms->paths->storage());
            $maintenance->on(Maintenance::ALL, 'restore', 30);
            $dbTouched = false;
            $work = $this->tmp('restore-' . bin2hex(random_bytes(4)));
            mkdir($work, 0700, true);
            $moved = [];

            try {
                $zip = Zip::open($zipPath, Zip::MAX_ENTRIES);
                // As large as the disk allows: a site's media can be far over any fixed limit.
                $zip->extractTo($work . '/x', self::extractBudget($work), 500, static fn(ZipEntry $e): bool => $e->name !== 'backup.json' && $e->name !== 'backup.sig');
                $maintenance->extend();
                foreach ($manifest['entries'] as $entry => $hash) {
                    $file = $work . '/x/' . $entry;
                    if (!is_file($file) || !hash_equals($hash, (string) hash_file('sha256', $file))) {
                        throw new RuntimeException("{$entry} does not match its checksum: the archive is damaged.");
                    }
                }

                // files first: the database rebuild needs the plugins' migrations
                $skippedCode = false;
                foreach (array_keys((array) ($manifest['sections'] ?? [])) as $section) {
                    if (!self::allowedSection((string) $section)) {
                        continue;
                    }
                    if ((self::SECTIONS[$section] ?? false) && !$withCode) {
                        $skippedCode = true;
                        continue;
                    }
                    $target = $this->sectionPath((string) $section);
                    $source = $work . '/x/files/' . $section;
                    if (!is_dir($source)) {
                        mkdir($source, 0775, true);         // an empty section empties the folder
                    }
                    $aside = $work . '/old/' . $section;
                    if (!is_dir(dirname($aside))) {
                        mkdir(dirname($aside), 0700, true);
                    }
                    // Files::move falls back to copying when a section is on another volume.
                    $maintenance->extend();
                    if (is_dir($target)) {
                        Files::move($target, $aside);
                    }
                    $moved[] = [$target, $aside];
                    if (!is_dir(dirname($target))) {
                        mkdir(dirname($target), 0775, true);
                    }
                    Files::move($source, $target);
                }
                $this->cms->extensions()->discover(true);

                $dbTouched = true;
                $report = $this->rebuildDatabase($manifest, $work . '/x/database', array_values(array_intersect($options['keep'] ?? [], self::SKIP_TABLES)), $work . '/kept');
                $this->afterRestore();
                $maintenance->off();
                $this->removeTree($work);
                $this->cms->audit->record('backup.restored', null, ['tables' => $report['tables'], 'rows' => $report['rows'], 'from_core' => $manifest['core'], 'driver' => $manifest['driver'], 'code' => $withCode && !$skippedCode]);

                return $report + ['skipped_code' => $skippedCode, 'warnings' => $check['warnings'], 'safety' => $safety];
            } catch (Throwable $e) {
                foreach (array_reverse($moved) as [$target, $aside]) {
                    $this->removeTree($target);
                    if (is_dir($aside)) {
                        Files::move($aside, $target);
                    }
                }
                $this->cms->extensions()->discover(true);
                $undo = '';
                $intact = !$dbTouched;          // the database was never emptied
                if ($intact) {
                    $undo = ' The site was put back as it was.';
                }
                if ($dbTouched && $safety !== null) {
                    try {
                        $dir = $work . '/safety';
                        $this->withLocalCopy($safety, function (string $path) use ($dir): void {
                            $safe = $this->inspect($path);
                            Zip::open($path, Zip::MAX_ENTRIES)->extractTo($dir, self::extractBudget($dir), 500, static fn(ZipEntry $x): bool => str_starts_with($x->name, 'database/'));
                            $this->rebuildDatabase($safe['manifest'], $dir . '/database');
                        });
                        $this->afterRestore();
                        $intact = true;
                        $undo = ' The site was put back as it was.';
                    } catch (Throwable $again) {
                        $undo = " Putting the site back also failed ({$again->getMessage()}): restore {$safety} from the command line.";
                    }
                }
                if ($intact) {
                    $maintenance->off();
                } else {
                    // Visitors must not see an emptied or half-imported database.
                    $maintenance->on(Maintenance::ALL, 'A restore failed and the database could not be put back', self::BROKEN_MINUTES);
                    $undo .= ' The site stays in maintenance mode until a backup is restored.';
                }
                $this->removeTree($work);
                throw new RuntimeException('The restore failed: ' . $e->getMessage() . $undo, 0, $e);
            }
        });
    }

    /**
     * Drop every table, run the migrations the archive recorded, insert its
     * rows, then run the migrations this install has beyond the archive.
     *
     * @param array<string, mixed> $manifest
     * @param list<string> $keep tables whose current rows are set aside and put back
     * @return array{tables: int, rows: int, missing_tables: list<string>, dropped_columns: array<string, list<string>>}
     */
    private function rebuildDatabase(array $manifest, string $dir, array $keep = [], ?string $keptDir = null): array
    {
        $db = $this->db();
        $dump = new DatabaseDump($db);
        $kept = $keep !== [] && $keptDir !== null ? $this->setAside($dump, $keep, $keptDir) : [];
        $dump->dropAll();

        $byOwner = [];
        foreach ((array) ($manifest['migrations'] ?? []) as $m) {
            if (is_array($m) && is_string($m['migration'] ?? null) && is_string($m['owner'] ?? null)) {
                $byOwner[$m['owner']][] = $m['migration'];
            }
        }
        $core = new MigrationRunner($this->cms->paths->coreMigrations());
        $core->migrate($byOwner['core'] ?? []);
        $plugins = $this->cms->extensions()->plugins();
        foreach ($byOwner as $owner => $files) {
            if (str_starts_with($owner, 'plugin:') && isset($plugins[substr($owner, 7)])) {
                $this->cms->extensions()->migrator($plugins[substr($owner, 7)])->migrate($files);
            }
        }
        $db->forgetColumns();

        $existing = array_flip($dump->tables());
        $tables = 0;
        $rows = 0;
        $missing = [];
        $dropped = [];
        $db->schema()->withoutForeignKeyConstraints(function () use ($manifest, $dir, $dump, $existing, &$tables, &$rows, &$missing, &$dropped): void {
            foreach (array_keys($manifest['tables']) as $table) {
                (new Maintenance($this->cms->paths->storage()))->extend();      // a large import must not outlast maintenance
                $file = $dir . '/' . $table . '.jsonl';
                if (!isset($existing[$table])) {
                    if (((int) ($manifest['tables'][$table]['rows'] ?? 0)) > 0) {
                        $missing[] = (string) $table;
                    }
                    continue;
                }
                if (!is_file($file)) {
                    continue;
                }
                // the backup's rows replace whatever the migrations seeded, instead of clashing with them
                $this->db()->table((string) $table)->delete();
                $handle = new \SplFileObject($file, 'rb');
                $result = $dump->import((string) $table, (static function () use ($handle): \Generator {
                    while (!$handle->eof()) {
                        yield (string) $handle->fgets();
                    }
                })());
                $tables++;
                $rows += $result['rows'];
                if ($result['dropped'] !== []) {
                    $dropped[$table] = $result['dropped'];
                }
            }
        });
        $dump->resetSequences(array_values(array_filter(array_keys($manifest['tables']), static fn($t): bool => isset($existing[$t]))));

        // the archive may come from an older core: the normal upgrade path from here
        $core->migrate();
        $this->putBack($dump, $kept);

        return ['tables' => $tables, 'rows' => $rows, 'missing_tables' => $missing, 'dropped_columns' => $dropped];
    }

    /**
     * Copy tables' rows to files before the database is emptied.
     *
     * @param list<string> $tables
     * @return array<string, string> table => file
     */
    private function setAside(DatabaseDump $dump, array $tables, string $dir): array
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $present = array_flip($dump->tables());
        $files = [];
        foreach ($tables as $table) {
            if (!isset($present[$table])) {
                continue;
            }
            $file = "{$dir}/{$table}.jsonl";
            $out = fopen($file, 'wb');
            if ($out === false) {
                throw new RuntimeException("Could not set {$table} aside in {$dir}.");
            }
            $dump->export($table, $out);
            fclose($out);
            $files[$table] = $file;
        }

        return $files;
    }

    /** @param array<string, string> $files table => file, from setAside() */
    private function putBack(DatabaseDump $dump, array $files): void
    {
        if ($files === []) {
            return;
        }
        $present = array_flip($dump->tables());
        $this->db()->schema()->withoutForeignKeyConstraints(function () use ($dump, $files, $present): void {
            foreach ($files as $table => $file) {
                if (!isset($present[$table])) {
                    continue;
                }
                $this->db()->table($table)->delete();
                $handle = new \SplFileObject($file, 'rb');
                $dump->import($table, (static function () use ($handle): \Generator {
                    while (!$handle->eof()) {
                        yield (string) $handle->fgets();
                    }
                })());
                @unlink($file);
            }
        });
        $dump->resetSequences(array_keys($files));
    }

    private function afterRestore(): void
    {
        Settings::destroy();
        $this->db()->forgetColumns();
        // Infrastructure settings came back with the database: rebuild their compiled copy.
        try {
            if ($this->cms->container->has(\Kestello\Cms\Platform\PlatformConfig::class)) {
                $this->cms->container->make(\Kestello\Cms\Platform\PlatformConfig::class)->compile();
            }
            $this->cms->drivers->forget();
        } catch (Throwable $e) {
            error_log('[kestello] platform settings not recompiled after restore: ' . $e->getMessage());
        }
        foreach (['cache', 'compiled'] as $dir) {
            $this->emptyDir($this->cms->paths->storage($dir));
        }
        try {
            if ($this->cms->container->has(\Kestello\Contracts\Cache\PageCache::class)) {
                $this->cms->container->make(\Kestello\Contracts\Cache\PageCache::class)->flush();
            }
        } catch (Throwable) {
            // the page cache rebuilds itself
        }
        try {
            $this->cms->queue->push(new \Kestello\Cms\Search\ReindexSearch());
        } catch (Throwable $e) {
            error_log('[kestello] search reindex after restore not queued: ' . $e->getMessage());
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /** @return array<string, string> section => absolute directory (only those that exist) */
    private function sections(): array
    {
        $out = [];
        foreach (array_keys(self::SECTIONS) as $section) {
            $dir = $this->sectionPath($section);
            if (is_dir($dir)) {
                $out[$section] = $dir;
            }
        }
        foreach (glob($this->cms->paths->storage('app') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (!in_array($name, self::SKIP_APP, true) && preg_match('/^[a-z0-9_-]{1,40}$/', $name) === 1 && !is_link($dir)) {
                $out['storage/app/' . $name] = $dir;
            }
        }

        return $out;
    }

    private function sectionPath(string $section): string
    {
        return str_starts_with($section, 'content/')
            ? $this->cms->paths->content(substr($section, 8))
            : $this->cms->paths->storage(substr($section, 8));
    }

    private static function allowedSection(string $section): bool
    {
        if (isset(self::SECTIONS[$section])) {
            return true;
        }

        return preg_match('#^storage/app/([a-z0-9_-]{1,40})$#', $section, $m) === 1 && !in_array($m[1], self::SKIP_APP, true);
    }

    private static function allowedEntry(string $entry): bool
    {
        if (preg_match('#^database/[A-Za-z0-9_]{1,64}\.jsonl$#', $entry) === 1) {
            return true;
        }
        if (Zip::safeName($entry) === null || !str_starts_with($entry, 'files/') || str_contains($entry, '/.git/')) {
            return false;
        }
        foreach (array_merge(array_keys(self::SECTIONS), ['storage/app/']) as $section) {
            $prefix = 'files/' . rtrim($section, '/') . '/';
            if (str_starts_with($entry, $prefix)) {
                return $section !== 'storage/app/' || preg_match('#^files/storage/app/([a-z0-9_-]{1,40})/#', $entry, $m) === 1 && !in_array($m[1], self::SKIP_APP, true);
            }
        }

        return false;
    }

    /** @param array<string, string> $hashes */
    private function addTree(\Kestello\Support\Zip\ZipWriter $zip, string $dir, string $prefix, array &$hashes): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $f): bool => !$f->isLink() && !in_array($f->getFilename(), ['.git', 'node_modules', '.DS_Store'], true),
            ),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            $entry = $prefix . '/' . $relative;
            if (Zip::safeName($entry) === null) {
                continue;
            }
            $zip->addFile($file->getPathname(), $entry, preg_match(self::STORED, $relative) !== 1);
            $hashes[$entry] = $zip->lastHash();
            $count++;
        }

        return $count;
    }

    /** @return list<array{type: string, slug: string, version: string, active: bool}> */
    private function packages(): array
    {
        $out = [];
        foreach ([PackageType::Plugin, PackageType::Theme] as $type) {
            $list = $type === PackageType::Plugin ? $this->cms->extensions()->plugins() : $this->cms->extensions()->themes();
            foreach ($list as $slug => $package) {
                $out[] = ['type' => $type->value, 'slug' => (string) $slug, 'version' => $package->version(), 'active' => $type === PackageType::Plugin
                    ? $this->cms->extensions()->isPluginEnabled((string) $slug) : $this->cms->extensions()->configuredTheme() === $slug];
            }
        }

        return $out;
    }

    /** @return list<array{migration: string, owner: string}> */
    private function migrations(): array
    {
        if (!$this->db()->schema()->hasTable('migrations')) {
            return [];
        }

        return array_map(
            static fn(array $r): array => ['migration' => (string) $r['migration'], 'owner' => (string) ($r['owner'] ?? 'core')],
            $this->db()->select('SELECT migration, owner FROM migrations ORDER BY id'),
        );
    }

    /** @return list<string> */
    private function skipTables(): array
    {
        $extra = (array) $this->cms->filters->apply('backup.skip_tables', []);

        return array_values(array_unique([...self::SKIP_TABLES, ...array_filter($extra, 'is_string')]));
    }

    /** Identifies the app key without revealing it. */
    private function keyFingerprint(): string
    {
        return substr(Hash::hmac('kestello-backup-key-fingerprint', 'backup.key'), 0, 16);
    }

    private function siteSlug(): string
    {
        $host = (string) parse_url((string) $this->cms->config->get('app.url', ''), PHP_URL_HOST);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($host !== '' ? $host : 'site')), '-');

        return substr($slug !== '' ? $slug : 'site', 0, 40);
    }

    private function store(string $local, string $name): void
    {
        $disk = $this->disk();
        if ($disk instanceof LocalDisk) {
            $target = $disk->root() . '/' . $name;
            if (!is_dir($disk->root())) {
                mkdir($disk->root(), 0700, true);
            }
            if (!@rename($local, $target)) {
                copy($local, $target);
            }
            @chmod($target, 0600);
            return;
        }
        $in = fopen($local, 'rb');
        if ($in === false) {
            throw new RuntimeException('Could not read the new backup.');
        }
        try {
            $disk->putStream($name, $in, ['visibility' => 'private']);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }

    private function disk(): Disk
    {
        return $this->cms->storage->disk($this->diskName);
    }

    private function db(): Connection
    {
        return $this->connection ?? DatabaseManager::getConnection();
    }

    private function tmp(string $name): string
    {
        $dir = $this->cms->paths->storage('tmp');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir . '/' . $name;
    }

    /**
     * One backup or restore at a time.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    /**
     * Run $fn holding the backup lock, so no backup or restore starts meanwhile.
     * The updaters hold it from the before-update backup to the end of a
     * rollback; nested calls (their own backup and restore) pass through.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function exclusive(callable $fn): mixed
    {
        return $this->locked($fn);
    }

    private function locked(callable $fn): mixed
    {
        if ($this->lockDepth > 0) {
            $this->lockDepth++;
            try {
                return $fn();
            } finally {
                $this->lockDepth--;
            }
        }
        $dir = $this->cms->paths->storage('framework');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $lock = fopen($dir . '/backup.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw ContentException::conflict('A backup or restore is already running. Try again when it has finished.');
        }
        @set_time_limit(0);
        $this->lockDepth = 1;
        try {
            return $fn();
        } finally {
            $this->lockDepth = 0;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function emptyDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
    }

    private function removeTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
