<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Media;

use Kestello\Cms\Cms;
use Kestello\Cms\Media\MediaException;
use Kestello\Cms\Media\MediaStorage;
use Kestello\Framework\Console\Command;

/**
 * Move every media file to another disk, or see where files are.
 *
 *   php bin/cms media:move                  disks and what they hold
 *   php bin/cms media:move --to=media-r2    move everything (resumes a stopped move)
 *   php bin/cms media:move --to=media-r2 --keep-source
 *   php bin/cms media:move --stop
 */
final class MediaMoveCommand extends Command
{
    protected string $name = 'media:move';
    protected string $description = 'Move media files to another disk [--to=disk] [--keep-source] [--stop]';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $storage = $cms->container->make(MediaStorage::class);

        if (isset($options['stop'])) {
            $storage->cancelMove(null);
            $this->output->success('  Stopped. Files already moved stay on the new disk.');

            return 0;
        }
        if (!isset($options['to']) || !is_string($options['to'])) {
            foreach ($storage->disks() as $d) {
                $this->output->line(sprintf('  %s %-16s %-8s %6d files  %10s%s', $d['current'] ? '*' : ' ', $d['name'], $d['driver'], $d['files'], MediaStorage::human($d['bytes']), $d['problem'] !== null ? '  (' . $d['problem'] . ')' : ''));
            }
            $this->output->line('  * new uploads go here. Move everything with --to=<disk>.');

            return 0;
        }

        $move = $storage->move();
        if ($move === null || $move['status'] !== 'running' || $move['to'] !== $options['to']) {
            try {
                $move = $storage->startMove($options['to'], isset($options['keep-source']), null);
            } catch (MediaException $e) {
                $this->output->error('  ' . implode(' ', array_merge(...array_values($e->errors ?: [[$e->getMessage()]]))));

                return 1;
            }
        }
        while ($move !== null && $move['status'] === 'running') {
            $move = $storage->step(maxFiles: 50, seconds: 30.0);
            if ($move !== null) {
                $this->output->line(sprintf('  %d of %d moved, %d left', $move['done'], $move['total'], $move['remaining']));
            }
        }
        foreach ($move['failed'] ?? [] as $id => $why) {
            $this->output->error("  #{$id} {$why}");
        }
        $this->output->success("  {$move['done']} file(s) now on \"{$move['to']}\"." . (($move['failed'] ?? []) !== [] ? ' Run again to retry the ones that failed.' : ''));

        return ($move['failed'] ?? []) === [] ? 0 : 1;
    }
}
