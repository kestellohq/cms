<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Closure;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Package\Update\UpdateRecovery;
use Kestello\Framework\Console\Command;

/**
 * update:recover: finish or put back an update that stopped halfway, also
 * after the site gave up trying by itself. Plugins are not loaded meanwhile.
 */
final class UpdateRecoverCommand extends Command
{
    protected string $name = 'update:recover';
    protected string $description = 'Finish or put back an update that stopped halfway (the old code, then the backup taken before it)';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $recovery = $cms->container->make(UpdateRecovery::class);
        if ($recovery->journal()->current() === null) {
            $this->output->line('  No update stopped halfway. Nothing to do.');

            return 0;
        }
        $recovery->journal()->retry();
        try {
            $result = $recovery->recover();
        } catch (ContentException $e) {
            $this->output->error('  ' . $e->getMessage());

            return 1;
        }

        return self::report($result, fn(string $line) => $result['ok'] ? $this->output->line($line) : $this->output->error($line));
    }

    /**
     * @param array{ok: bool, event: string, owner: ?string, from: ?string, to: ?string, problems: list<string>} $result
     * @param Closure(string): mixed $write
     */
    public static function report(array $result, Closure $write): int
    {
        $write('  ' . match ($result['event']) {
            'none' => 'No update stopped halfway. Nothing to do.',
            'finished' => "The update of {$result['owner']} to {$result['to']} had been checked: it is kept.",
            'put_back' => "The update of {$result['owner']} to {$result['to']} was put back: {$result['from']} and the database from before it.",
            'switched_back' => "{$result['owner']} was switched back to {$result['from']}, with the database from before the update.",
            default => 'Putting the update back failed: ' . implode(' ', $result['problems']),
        });

        return $result['ok'] ? 0 : 1;
    }
}
