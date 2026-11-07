<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\ExecutionContext;

/**
 * Console commands registered by modules and plugins.
 *
 * @api
 */
final class CommandRegistry
{
    /** @var list<array{command: Command|class-string<Command>, owner: string}> */
    private array $commands = [];

    /**
     * @param Command|class-string<Command> $command
     */
    public function command(Command|string $command): void
    {
        $this->commands[] = ['command' => $command, 'owner' => ExecutionContext::current()];
    }

    /**
     * @return list<array{command: Command, owner: string}>
     */
    public function all(): array
    {
        return array_map(static function (array $entry): array {
            $command = $entry['command'];

            return [
                'command' => is_string($command) ? ExecutionContext::run($entry['owner'], static fn() => new $command()) : $command,
                'owner' => $entry['owner'],
            ];
        }, $this->commands);
    }
}
