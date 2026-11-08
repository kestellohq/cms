<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\System\Maintenance;
use Throwable;

/**
 * The ways back from an update.
 *
 * recover(): an update PHP stopped halfway (UpdateJournal) is finished when
 * the site had already been checked, and put back otherwise: the old code,
 * then the database backup taken before it. The kernel runs it on the next
 * request, before plugins load, so the half-installed code never runs. For
 * core, that request already runs the old release (CorePointer).
 *
 * switchBack(): an update that finished, undone on request: the code from
 * before it (still on disk) and the backup taken before it. It writes a
 * journal and lets recover() do the work on the next request, in the old
 * code, so the database is rebuilt by the migrations of the version that
 * will run it.
 */
final class UpdateRecovery
{
    public const string BLOCKED = 'Another update has not finished. Wait for it, or put it back under Site → Updates if it stopped.';

    public function __construct(
        private readonly Cms $cms,
        private readonly Backups $backups,
    ) {}

    public function journal(): UpdateJournal
    {
        return new UpdateJournal($this->cms->paths->storage());
    }

    public function history(): UpdateHistory
    {
        return new UpdateHistory($this->cms->paths->storage());
    }

    /**
     * Finish or put back the update in the journal.
     *
     * @return array{ok: bool, event: string, owner: ?string, from: ?string, to: ?string, problems: list<string>, attempts: int}
     * @throws ContentException when an update is running right now
     */
    public function recover(?int $actor = null): array
    {
        $storage = $this->cms->paths->storage();

        return Files::locked($storage, fn(): array => $this->backups->exclusive(function () use ($storage, $actor): array {
            $journal = $this->journal();
            $entry = $journal->current();
            if ($entry === null) {
                return ['ok' => true, 'event' => 'none', 'owner' => null, 'from' => null, 'to' => null, 'problems' => [], 'attempts' => 0];
            }
            $attempts = $journal->attempt();
            $maintenance = new Maintenance($storage);
            $maintenance->on(Maintenance::VISITORS, 'putting back an update that stopped', 30);
            $base = ['owner' => (string) $entry['owner'], 'from' => (string) $entry['from'], 'to' => (string) $entry['to'], 'attempts' => $attempts];

            try {
                $event = $entry['step'] === UpdateJournal::CONFIRMED ? $this->finish($entry) : $this->putBack($entry);
            } catch (Throwable $e) {
                $problem = $e->getMessage();
                if ($attempts >= UpdateJournal::MAX_ATTEMPTS) {
                    $journal->giveUp();
                    // visitors stay out of a half-updated site; signed-in administrators still get in
                    $maintenance->on(Maintenance::VISITORS, 'An update stopped halfway and could not be put back', Backups::BROKEN_MINUTES);
                }
                $this->cms->audit->record('update.recovery_failed', null, $base + ['problem' => $problem, 'backup' => $entry['backup']], $actor);

                return ['ok' => false, 'event' => 'failed', 'problems' => [$problem]] + $base;
            }

            $journal->clear();
            $maintenance->off();
            $this->cms->audit->record('update.' . $event, null, $base + ['backup' => $entry['backup'], 'kind' => $entry['kind'] ?? 'update'], $actor);

            return ['ok' => true, 'event' => $event, 'problems' => []] + $base;
        }));
    }

    /** What people call $owner: Kestello, or the plugin's or theme's name (its folder name once it is gone). */
    public static function displayName(Cms $cms, string $owner): string
    {
        if ($owner === 'core') {
            return 'Kestello';
        }
        [$kind, $slug] = explode(':', $owner, 2) + [1 => ''];
        try {
            $ext = $cms->extensions();
            $package = ($kind === 'theme' ? $ext->themes() : $ext->plugins())[$slug] ?? null;
        } catch (Throwable) {
            $package = null;
        }

        return $package !== null && $package->manifest->name !== '' ? $package->manifest->name : $slug;
    }

    /**
     * Whether $owner can go back to the version before its last update, and to what.
     *
     * @return array{owner: string, from: string, to: string, backup: string, backup_at: int, updated_at: int}|null
     */
    public function switchBackOption(string $owner, ?string $running): ?array
    {
        if ($running === null) {
            return null;
        }
        $last = $this->history()->lastUpdate($owner, $running);
        if ($last === null || !$this->backups->exists((string) $last['backup'])) {
            return null;
        }
        $old = $owner === 'core'
            ? $this->core()->hasRelease(is_string($last['previous'] ?? null) ? $last['previous'] : null)
            : is_string($last['aside'] ?? null) && is_dir($last['aside']) && is_file($last['aside'] . '/' . (str_starts_with($owner, 'plugin:') ? 'plugin.json' : 'theme.json'));
        if (!$old) {
            return null;
        }
        $backupAt = 0;
        foreach ($this->backups->all() as $b) {
            if ($b['name'] === $last['backup']) {
                $backupAt = (int) $b['created_at'];
            }
        }

        return ['owner' => $owner, 'from' => $running, 'to' => (string) $last['from'], 'backup' => (string) $last['backup'], 'backup_at' => $backupAt, 'updated_at' => (int) ($last['at'] ?? 0)];
    }

    /**
     * Start switching $owner back to the version before its last update. A
     * backup of the site as it is now is taken first. The old code is put in
     * place for the next request, which restores the database (recover()).
     *
     * @return array{from: string, to: string, safety: string}
     */
    public function switchBack(string $owner, ?string $running, ?int $actor = null): array
    {
        $storage = $this->cms->paths->storage();

        return Files::locked($storage, fn(): array => $this->backups->exclusive(function () use ($owner, $running, $actor, $storage): array {
            if ($this->journal()->current() !== null) {
                throw ContentException::conflict(self::BLOCKED);
            }
            $option = $this->switchBackOption($owner, $running) ?? throw ContentException::conflict('There is no earlier version to switch back to.');
            $last = $this->history()->lastUpdate($owner, (string) $running) ?? [];
            $safety = $this->backups->create(['files' => false, 'kind' => 'before-restore', 'note' => "Before switching {$owner} back from {$option['from']} to {$option['to']}"])['name'];

            (new Maintenance($storage))->on(Maintenance::VISITORS, "switch back {$owner}", 30);
            $previous = is_string($last['previous'] ?? null) ? $last['previous'] : null;
            $this->journal()->begin([
                'kind' => 'switch-back',
                'owner' => $owner,
                'from' => $option['to'],
                'to' => $option['from'],
                'backup' => $option['backup'],
                'previous' => $previous,
                'target' => $owner === 'core' ? $this->core()->releasesDir() . '/' . $option['from'] : $this->packageFolder($owner),
                'aside' => is_string($last['aside'] ?? null) ? $last['aside'] : null,
                'keep_target' => true,
            ], UpdateJournal::SWITCHING);
            if ($owner === 'core') {
                // the next request must already run the old release: it rebuilds the database
                $previous === null ? CorePointer::remove($this->core()->pointer()) : CorePointer::write($this->core()->pointer(), CorePointer::plain($previous));
            }
            $this->cms->audit->record('update.switch_back_started', null, ['owner' => $owner, 'from' => $option['from'], 'to' => $option['to'], 'backup' => $option['backup'], 'safety' => $safety], $actor);

            return ['from' => $option['from'], 'to' => $option['to'], 'safety' => $safety];
        }));
    }

    /** @param array<string, mixed> $entry */
    private function finish(array $entry): string
    {
        if ($entry['owner'] === 'core') {
            CorePointer::write($this->core()->pointer(), CorePointer::plain((string) $entry['to']));
        }
        $this->freshCode();
        $top = $this->history()->all()[0] ?? null;
        if ($top === null || $top['owner'] !== $entry['owner'] || $top['to'] !== $entry['to'] || ($top['backup'] ?? null) !== $entry['backup']) {
            $this->history()->add(['owner' => (string) $entry['owner'], 'from' => (string) $entry['from'], 'to' => (string) $entry['to'], 'backup' => (string) $entry['backup'], 'previous' => $entry['previous'] ?? null, 'aside' => $entry['aside'] ?? null]);
        }

        return 'finished';
    }

    /** @param array<string, mixed> $entry */
    private function putBack(array $entry): string
    {
        $owner = (string) $entry['owner'];
        $switchBack = ($entry['kind'] ?? 'update') === 'switch-back';
        $target = (string) $entry['target'];
        $ext = $this->cms->extensions();

        if ($owner === 'core') {
            $previous = is_string($entry['previous'] ?? null) ? $entry['previous'] : null;
            if (!$this->core()->hasRelease($previous)) {
                throw new \RuntimeException('The release to go back to, ' . ($previous ?? 'core/') . ', is no longer on the server. Restore the backup ' . $entry['backup'] . ' after putting that release back.');
            }
            $previous === null ? CorePointer::remove($this->core()->pointer()) : CorePointer::write($this->core()->pointer(), CorePointer::plain($previous));
            if (!($entry['keep_target'] ?? false) && basename($target) !== $previous && dirname($target) === $this->core()->releasesDir()) {
                Files::removeTree($target);
            }
        } else {
            $aside = is_string($entry['aside'] ?? null) ? $entry['aside'] : null;
            if ($aside !== null && is_dir($aside)) {
                // the old folder is complete (Files::move renames it into place): it replaces whatever is there
                if ($target !== $this->packageFolder($owner) || dirname($aside) !== $this->cms->paths->storage('app/backups/packages')) {
                    throw new \RuntimeException("The journal names folders that are not {$owner}'s.");
                }
                Files::removeTree($target);
                Files::move($aside, $target);
            } elseif ($switchBack) {
                throw new \RuntimeException("The old version of {$owner} is no longer on the server.");
            }
        }
        if (is_array($entry['suspended'] ?? null)) {
            $ext->suspensions()->restore($entry['suspended']);
        }
        $this->freshCode();
        $ext->discover(true);

        // before SWITCHING nothing but the backup happened: the database is as it was
        if ($entry['step'] === UpdateJournal::SWITCHING) {
            if (!$this->backups->exists((string) $entry['backup'])) {
                throw new \RuntimeException("The backup taken before the update, {$entry['backup']}, is missing.");
            }
            $this->backups->restoreNamed((string) $entry['backup'], ['safety' => false, 'keep' => Backups::KEEP_ON_ROLLBACK]);
            $this->freshCode();
        }
        if ($switchBack) {
            $this->history()->add(['owner' => $owner, 'from' => (string) $entry['to'], 'to' => (string) $entry['from'], 'backup' => (string) $entry['backup'], 'event' => 'switched-back']);
        }

        return $switchBack ? 'switched_back' : 'put_back';
    }

    private function packageFolder(string $owner): string
    {
        [$kind, $slug] = explode(':', $owner, 2);

        return (PackageType::from($kind) === PackageType::Plugin ? $this->cms->paths->plugins() : $this->cms->paths->themes()) . '/' . $slug;
    }

    private function core(): CoreUpdater
    {
        return $this->cms->container->make(CoreUpdater::class);
    }

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
}
