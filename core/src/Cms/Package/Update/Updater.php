<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\Manifest;
use Kestello\Cms\Extension\Package;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Package\PackageInstaller;
use Kestello\Cms\System\Maintenance;
use Kestello\Framework\Support\Semver;
use Throwable;

/**
 * The update pipeline for plugins and themes:
 *
 *   verify → resolve → backup → maintenance → swap → health check → commit | roll back
 *
 * The health check runs in a fresh process (HealthProbe): the new code's
 * migrations and upgrade() run on that boot, then the home page and the
 * status endpoint are requested. Anything wrong, and the old files and the
 * database backup go back, by themselves.
 *
 * Old package folders are kept in storage/app/backups/packages (the newest
 * three per package): with the backup they are the way back (Switch back,
 * UpdateRecovery). Each step is written to UpdateJournal first, so an update
 * PHP stopped halfway is put back by the next request.
 */
final class Updater
{
    private const int KEEP_OLD = 3;

    public function __construct(
        private readonly Cms $cms,
        private readonly PackageInstaller $installer,
        private readonly Backups $backups,
        private readonly HealthProbe $probe,
    ) {}

    /**
     * Read an update zip and say whether it can be applied, without changing anything.
     *
     * @return array<string, mixed> the package description plus "from", "problems"
     */
    public function check(string $zipPath, array $options = []): array
    {
        $staged = $this->installer->stage($zipPath);
        try {
            return $staged['check'] + ['problems' => $this->problems($staged, $options)];
        } finally {
            $this->installer->discard($staged);
        }
    }

    /**
     * @param array{allow_unsigned?: bool, allow_downgrade?: bool, actor?: ?int} $options
     * @return array{ok: bool, slug: string, type: string, from: string, to: string, problems: list<string>, backup: ?string, rolled_back: bool, rollback_problems: list<string>}
     * @throws ContentException when the update can't start (nothing was changed)
     */
    public function update(string $zipPath, array $options = []): array
    {
        $staged = $this->installer->stage($zipPath);
        try {
            $check = $staged['check'];
            $this->installer->assertAllowed($check, $options);
            $problems = $this->problems($staged, $options);
            if ($problems !== []) {
                throw ContentException::invalid(['file' => $problems]);
            }

            // The backup lock too: a scheduled backup must not start mid-update and block the rollback's restore.
            return Files::locked($this->cms->paths->storage(), fn(): array => $this->backups->exclusive(function () use ($staged, $options): array {
                // Read the installed copy again under the lock: another update may have finished meanwhile.
                $staged['check']['installed'] = $this->installer->installed($staged['type'], (string) $staged['check']['slug']);
                $problems = $this->problems($staged, $options);
                if ($problems !== []) {
                    throw ContentException::invalid(['file' => $problems]);
                }

                return $this->apply($staged, $options);
            }));
        } finally {
            $this->installer->discard($staged);
        }
    }

    /**
     * @param array{root: string, package: string, type: PackageType, check: array<string, mixed>} $staged
     * @return list<string>
     */
    private function problems(array $staged, array $options): array
    {
        if ((new UpdateJournal($this->cms->paths->storage()))->current() !== null) {
            return [UpdateRecovery::BLOCKED];
        }
        $check = $staged['check'];
        if ($check['installed'] === null) {
            return ["{$check['name']} is not installed: install it instead of updating."];
        }
        $from = (string) $check['installed']['version'];
        $cmp = Semver::compare($check['version'], $from);
        if ($cmp === 0) {
            return ["{$check['name']} {$from} is already installed."];
        }
        if ($cmp < 0 && !($options['allow_downgrade'] ?? false)) {
            return ["This is {$check['name']} {$check['version']}, older than the installed {$from}."];
        }
        $pin = ($options['allow_unsigned'] ?? false) ? null : $this->installer->pins()->problem($this->installer->owner($check), $check['signature']);
        if ($pin !== null) {
            return ["{$check['name']} {$check['version']} {$pin}."];
        }
        $candidate = new Package(Manifest::load($staged['package'], $staged['type']), $staged['package']);

        return array_map(static fn(string $p): string => "{$check['name']} {$check['version']} {$p}.", $this->cms->extensions()->updateProblems($candidate));
    }

    /**
     * @param array{root: string, package: string, type: PackageType, check: array<string, mixed>} $staged
     * @return array<string, mixed>
     */
    private function apply(array $staged, array $options): array
    {
        $check = $staged['check'];
        $type = $staged['type'];
        $slug = (string) $check['slug'];
        $owner = $type->value . ':' . $slug;
        $from = (string) $check['installed']['version'];
        $to = (string) $check['version'];
        $wasRunning = (bool) $check['installed']['active'];
        $ext = $this->cms->extensions();
        $target = ($type === PackageType::Plugin ? $this->cms->paths->plugins() : $this->cms->paths->themes()) . '/' . $slug;
        $aside = $this->cms->paths->storage('app/backups/packages') . "/{$slug}-{$from}-" . date('Ymd-His') . '-' . bin2hex(random_bytes(3));

        $storage = $this->cms->paths->storage();
        $backup = $this->backups->create(['files' => false, 'kind' => 'before-update', 'note' => "Before updating {$check['name']} {$from} to {$to}"])['name'];
        $maintenance = new Maintenance($storage);
        $bypass = $maintenance->on(Maintenance::VISITORS, "update {$slug}", 30);
        $swapped = false;
        $report = [];
        $ext->suspensions()->refresh();
        $suspended = $ext->suspensions()->all();
        $pluginsBefore = array_keys($ext->activePlugins());
        $journal = new UpdateJournal($storage);
        $journal->begin(['kind' => 'update', 'owner' => $owner, 'from' => $from, 'to' => $to, 'backup' => $backup, 'target' => $target, 'aside' => $aside, 'suspended' => $suspended]);

        try {
            if (!is_dir(dirname($aside))) {
                mkdir(dirname($aside), 0700, true);
            }
            $journal->step(UpdateJournal::SWITCHING);
            Files::move($target, $aside);
            Files::move($staged['package'], $target);
            $swapped = true;
            $this->freshCode();

            $report = $this->probe->run($owner, $bypass);
            $problems = UpdateProbe::problems($report, $to, $wasRunning, $pluginsBefore);
        } catch (Throwable $e) {
            $problems = ['The update could not be put in place: ' . $e->getMessage()];
        }

        if ($problems === []) {
            $journal->step(UpdateJournal::CONFIRMED);
            $maintenance->off();
            $ext->discover(true);
            $this->pruneOld($slug, $aside);
            $this->installer->pins()->remember($owner, $check['signature'], $check['signing_keys']);
            (new UpdateHistory($storage))->add(['owner' => $owner, 'from' => $from, 'to' => $to, 'backup' => $backup, 'aside' => $aside, 'actor' => $options['actor'] ?? null]);
            $journal->clear();
            $this->cms->audit->record('package.updated', [$type->value, $slug], ['from' => $from, 'to' => $to, 'signature' => $check['signature']['status'], 'backup' => $backup], $options['actor'] ?? null);

            return ['ok' => true, 'slug' => $slug, 'type' => $type->value, 'from' => $from, 'to' => $to, 'problems' => [], 'backup' => $backup, 'rolled_back' => false, 'rollback_problems' => []];
        }

        // ── roll back: old files, then the database as it was ──
        $rollback = [];
        try {
            if ($swapped) {
                Files::removeTree($target);
            }
            if (is_dir($aside)) {
                Files::move($aside, $target);
            }
            // the new version's failure suspended it; the old one gets the list as it was
            $ext->suspensions()->restore($suspended);
            $this->freshCode();
            $ext->discover(true);
            $this->backups->restoreNamed($backup, ['safety' => false, 'keep' => Backups::KEEP_ON_ROLLBACK]);
            $this->freshCode();
            $after = UpdateProbe::problems($this->probe->run($owner, $maintenance->on(Maintenance::VISITORS, "rollback {$slug}", 10)), $from, $wasRunning, $pluginsBefore);
            if ($after !== []) {
                $rollback = array_map(static fn(string $p): string => "After rolling back: {$p}", $after);
            }
        } catch (Throwable $e) {
            $rollback[] = 'Rolling back failed: ' . $e->getMessage() . " The database backup is {$backup}; the old files are in " . $aside . '.';
        }
        if ($rollback === []) {
            $journal->clear();
            $maintenance->off();
        } else {
            // a later request (or Try again on the Updates screen) tries again from the journal
            $journal->giveUp();
            // The rollback did not finish: keep visitors out until someone looks (signed-in administrators still get in).
            $maintenance->on(Maintenance::VISITORS, 'An update failed and could not be rolled back completely', \Kestello\Cms\Backup\Backups::BROKEN_MINUTES);
        }
        $this->cms->audit->record('package.update_failed', [$type->value, $slug], ['from' => $from, 'to' => $to, 'problems' => $problems, 'rollback' => $rollback, 'backup' => $backup], $options['actor'] ?? null);

        return ['ok' => false, 'slug' => $slug, 'type' => $type->value, 'from' => $from, 'to' => $to, 'problems' => $problems, 'backup' => $backup, 'rolled_back' => $rollback === [], 'rollback_problems' => $rollback];
    }

    /** Swapped PHP files must not come from OPcache, and pages rendered by the old code must go. */
    private function freshCode(): void
    {
        Files::forgetCompiled();
        try {
            if ($this->cms->container->has(\Kestello\Contracts\Cache\PageCache::class)) {
                $this->cms->container->make(\Kestello\Contracts\Cache\PageCache::class)->flush();
            }
        } catch (Throwable) {
            // rebuilt on the next request
        }
    }

    /** Keep the newest KEEP_OLD old folders of a package, always with the one just set aside. */
    private function pruneOld(string $slug, string $keep): void
    {
        $old = glob($this->cms->paths->storage('app/backups/packages') . "/{$slug}-*", GLOB_ONLYDIR) ?: [];
        $old = array_values(array_filter($old, static fn(string $d): bool => preg_match('/^' . preg_quote($slug, '/') . '-\d+\.\d+\.\d+[^\/]*-\d{8}-\d{6}-[a-f0-9]{6}$/', basename($d)) === 1));
        usort($old, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($old, self::KEEP_OLD) as $dir) {
            if ($dir === $keep) {
                continue;
            }
            Files::removeTree($dir);
        }
    }
}
