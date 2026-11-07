<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Package\PackageInstaller;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\System\Maintenance;
use Kestello\Framework\Support\Semver;
use Throwable;

/**
 * Core updates. Releases live side by side in
 * core/releases/<version>/; core/current.php names the one the entry
 * scripts load. Switching is one atomic rename of that file, and so is
 * switching back.
 *
 *   verify → resolve (PHP, extensions, active plugins and themes) → backup →
 *   maintenance → unpack → switch → health check (fresh process: core
 *   migrations, plugins, pages) → commit | switch back + restore
 *
 * A development checkout runs core/ directly (no current.php). Its first
 * core update creates the pointer; rolling back removes it again.
 */
final class CoreUpdater
{
    private const int KEEP_RELEASES = 3;

    public function __construct(
        private readonly Cms $cms,
        private readonly PackageInstaller $installer,
        private readonly Backups $backups,
        private readonly HealthProbe $probe,
        private readonly ?string $running = null,
        /** @var array<string, array{name: string, public_key: string}> keys of official releases (release.keys) */
        private readonly array $releaseKeys = [],
    ) {}

    /**
     * Who may sign a core release: the official release keys and the publishers in
     * app.packages.trusted_keys.
     *
     * @return array<string, array{name: string, public_key: string}>
     */
    public function trustedKeys(): array
    {
        return $this->releaseKeys + $this->installer->trustedKeys();
    }

    public function running(): string
    {
        return $this->running ?? $this->cms->version();
    }

    /** @return array{version: string, mode: string, writable: bool, releases: list<array{version: string, current: bool}>} */
    public function status(): array
    {
        $current = $this->pointedVersion();
        $releases = [];
        foreach (glob($this->releasesDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (Semver::isValid(basename($dir))) {
                $releases[] = ['version' => basename($dir), 'current' => basename($dir) === $current];
            }
        }
        usort($releases, static fn(array $a, array $b): int => Semver::compare($b['version'], $a['version']));

        return [
            'version' => $this->running(),
            'mode' => $current === null ? 'development' : 'releases',
            'writable' => is_writable($this->coreRoot()),
            'releases' => $releases,
        ];
    }

    /**
     * @param array{allow_unsigned?: bool, allow_downgrade?: bool} $options
     * @return array{version: string, from: string, signature: array<string, mixed>, problems: list<string>, allowed: bool}
     */
    public function check(string $zipPath, array $options = []): array
    {
        $staged = $this->stage($zipPath);
        try {
            return [
                'version' => $staged['version'],
                'from' => $this->running(),
                'signature' => $staged['signature'],
                'problems' => $this->problems($staged, $options),
                'allowed' => $this->allowed($staged['signature']['status'], $options),
            ];
        } finally {
            Files::removeTree($staged['root']);
        }
    }

    /**
     * @param array{allow_unsigned?: bool, allow_downgrade?: bool, actor?: ?int} $options
     * @return array{ok: bool, from: string, to: string, problems: list<string>, backup: ?string, rolled_back: bool, rollback_problems: list<string>}
     * @throws ContentException when the update can't start (nothing was changed)
     */
    public function update(string $zipPath, array $options = []): array
    {
        $staged = $this->stage($zipPath);
        try {
            $status = $staged['signature']['status'];
            if ($status === PackageSignature::TAMPERED) {
                throw ContentException::invalid(['file' => ['The release was changed after it was signed. It was not installed.']]);
            }
            if (!$this->allowed($status, $options)) {
                throw ContentException::forbidden($status === PackageSignature::UNTRUSTED
                    ? 'This release is signed by a key this site does not trust.'
                    : 'This release is not signed. Core updates are applied here only when signed by a trusted publisher; a developer can apply it from the command line.');
            }
            $problems = $this->problems($staged, $options);
            if ($problems !== []) {
                throw ContentException::invalid(['file' => $problems]);
            }

            // The backup lock too: a scheduled backup must not start mid-update and block the rollback's restore.
            return Files::locked($this->cms->paths->storage(), fn(): array => $this->backups->exclusive(fn(): array => $this->apply($staged, $options)));
        } finally {
            Files::removeTree($staged['root']);
        }
    }

    /** @return array{root: string, dir: string, version: string, php: ?string, extensions: list<string>, signature: array<string, mixed>} */
    private function stage(string $zipPath): array
    {
        $tmp = $this->cms->paths->storage('tmp');
        if (!is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        return CoreRelease::stage($zipPath, $tmp, $this->trustedKeys());
    }

    private function allowed(string $status, array $options): bool
    {
        return $status === PackageSignature::SIGNED || ($status !== PackageSignature::TAMPERED && ($options['allow_unsigned'] ?? false));
    }

    /**
     * @param array{version: string, php: ?string, extensions: list<string>} $staged
     * @return list<string>
     */
    private function problems(array $staged, array $options): array
    {
        $to = $staged['version'];
        $from = $this->running();
        if (Semver::isValid($from)) {
            $cmp = Semver::compare($to, $from);
            if ($cmp === 0) {
                return ["Kestello {$from} is already running."];
            }
            if ($cmp < 0 && !($options['allow_downgrade'] ?? false)) {
                return ["This is Kestello {$to}, older than the running {$from}. Going back is done by restoring a backup."];
            }
        }
        $problems = [];
        $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        if ($staged['php'] !== null && !Semver::satisfies($php, $staged['php'])) {
            $problems[] = "Kestello {$to} needs PHP {$staged['php']}; this server runs {$php}.";
        }
        foreach ($staged['extensions'] as $extension) {
            if (!extension_loaded($extension)) {
                $problems[] = "Kestello {$to} needs the PHP extension \"{$extension}\".";
            }
        }
        if (!is_writable($this->coreRoot()) || (is_dir($this->releasesDir()) && !is_writable($this->releasesDir()))) {
            $problems[] = 'The core folder is not writable by PHP, so the release cannot be put in place.';
        }

        return [...$problems, ...$this->cms->extensions()->coreUpdateProblems($to)];
    }

    /**
     * @param array{root: string, dir: string, version: string, signature: array<string, mixed>} $staged
     * @return array<string, mixed>
     */
    private function apply(array $staged, array $options): array
    {
        $from = $this->running();
        $to = $staged['version'];
        $ext = $this->cms->extensions();
        $target = $this->releasesDir() . '/' . $to;
        $previous = is_file($this->pointer()) ? (string) file_get_contents($this->pointer()) : null;

        $backup = $this->backups->create(['files' => false, 'kind' => 'before-update', 'note' => "Before updating Kestello {$from} to {$to}"])['name'];
        $maintenance = new Maintenance($this->cms->paths->storage());
        $bypass = $maintenance->on(Maintenance::VISITORS, "update core {$to}", 30);
        $ext->suspensions()->refresh();
        $suspended = $ext->suspensions()->all();
        $pluginsBefore = array_keys($ext->activePlugins());
        $placed = false;

        try {
            if (!is_dir($this->releasesDir())) {
                mkdir($this->releasesDir(), 0755, true);
            }
            Files::removeTree($target);   // a leftover of an earlier attempt, never the running release
            Files::move($staged['dir'], $target);
            $placed = true;
            $this->writePointer("<?php\n\n// The core release the site runs; written by the updater.\nreturn " . var_export($to, true) . ";\n");
            $this->freshCode();

            $problems = UpdateProbe::problems($this->probe->run('core', $bypass), $to, true, $pluginsBefore);
        } catch (Throwable $e) {
            $problems = ['The release could not be put in place: ' . $e->getMessage()];
        }

        if ($problems === []) {
            $maintenance->off();
            $this->pruneReleases($to, $previous);
            $this->cms->audit->record('core.updated', null, ['from' => $from, 'to' => $to, 'signature' => $staged['signature']['status'], 'backup' => $backup], $options['actor'] ?? null);

            return ['ok' => true, 'from' => $from, 'to' => $to, 'problems' => [], 'backup' => $backup, 'rolled_back' => false, 'rollback_problems' => []];
        }

        // ── switch back, then the database as it was ──
        $rollback = [];
        try {
            $previous === null ? @unlink($this->pointer()) : $this->writePointer($previous);
            $this->freshCode();
            if ($placed) {
                Files::removeTree($target);
            }
            $ext->suspensions()->restore($suspended);
            $this->backups->restoreNamed($backup, ['safety' => false, 'keep' => Backups::KEEP_ON_ROLLBACK]);
            $this->freshCode();
            $after = UpdateProbe::problems($this->probe->run('core', $maintenance->on(Maintenance::VISITORS, 'rollback core', 10)), $from, true, $pluginsBefore);
            if ($after !== []) {
                $rollback = array_map(static fn(string $p): string => "After rolling back: {$p}", $after);
            }
        } catch (Throwable $e) {
            $rollback[] = 'Rolling back failed: ' . $e->getMessage() . " The database backup is {$backup}.";
        }
        if ($rollback === []) {
            $maintenance->off();
        } else {
            // The rollback did not finish: keep visitors out until someone looks (signed-in administrators still get in).
            $maintenance->on(Maintenance::VISITORS, 'An update failed and could not be rolled back completely', \Kestello\Cms\Backup\Backups::BROKEN_MINUTES);
        }
        $this->cms->audit->record('core.update_failed', null, ['from' => $from, 'to' => $to, 'problems' => $problems, 'rollback' => $rollback, 'backup' => $backup], $options['actor'] ?? null);

        return ['ok' => false, 'from' => $from, 'to' => $to, 'problems' => $problems, 'backup' => $backup, 'rolled_back' => $rollback === [], 'rollback_problems' => $rollback];
    }

    private function writePointer(string $contents): void
    {
        $tmp = $this->pointer() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException('Could not write core/current.php.');
        }
        @chmod($tmp, 0644);
        if (!rename($tmp, $this->pointer())) {
            @unlink($tmp);
            throw new \RuntimeException('Could not switch core/current.php.');
        }
        Files::forgetCompiled($this->pointer());
    }

    private function freshCode(): void
    {
        Files::forgetCompiled($this->pointer());
        try {
            if ($this->cms->container->has(\Kestello\Contracts\Cache\PageCache::class)) {
                $this->cms->container->make(\Kestello\Contracts\Cache\PageCache::class)->flush();
            }
        } catch (Throwable) {
            // rebuilt on the next request
        }
    }

    /** Keep the running release, the one before it, and the newest others up to KEEP_RELEASES. */
    private function pruneReleases(string $current, ?string $previousPointer): void
    {
        $previous = null;
        if ($previousPointer !== null && preg_match("/return '([^']+)'/", $previousPointer, $m) === 1) {
            $previous = $m[1];
        }
        $dirs = array_values(array_filter(glob($this->releasesDir() . '/*', GLOB_ONLYDIR) ?: [], static fn(string $d): bool => Semver::isValid(basename($d))));
        usort($dirs, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $kept = 0;
        foreach ($dirs as $dir) {
            $v = basename($dir);
            if ($v === $current || $v === $previous || ++$kept < self::KEEP_RELEASES - 1) {
                continue;
            }
            Files::removeTree($dir);
        }
    }

    private function pointedVersion(): ?string
    {
        if (!is_file($this->pointer())) {
            return null;
        }
        $v = preg_match("/return '([^']+)'/", (string) file_get_contents($this->pointer()), $m) === 1 ? $m[1] : null;

        return $v !== null && Semver::isValid($v) ? $v : null;
    }

    private function coreRoot(): string
    {
        return $this->cms->paths->root('core');
    }

    private function releasesDir(): string
    {
        return $this->coreRoot() . '/releases';
    }

    private function pointer(): string
    {
        return $this->coreRoot() . '/current.php';
    }
}
