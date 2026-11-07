<?php

declare(strict_types=1);

namespace Kestello\Framework\Console\Commands;

use Kestello\Framework\Console\Command;

/**
 * Start the PHP built-in development server.
 */
final class ServeCommand extends Command
{
    protected string $name = 'serve';
    protected string $description = 'Start the PHP built-in development server';

    public function __construct(
        private readonly string $basePath,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $host = (string) $this->option($options, 'host', '127.0.0.1');
        $port = (string) $this->option($options, 'port', '8000');
        if (preg_match('/^[A-Za-z0-9.:\[\]-]+$/', $host) !== 1 || preg_match('/^\d{2,5}$/', $port) !== 1 || (int) $port > 65535) {
            $this->output->error('Give --host as a name or IP address and --port as a number, e.g. --host=0.0.0.0 --port=8090.');
            return 1;
        }

        $this->output->info("Starting development server on http://{$host}:{$port}");
        $this->output->muted('  Press Ctrl+C to stop.');
        $this->output->newLine();

        $docRoot = $this->basePath . '/public';

        $router = dirname(__DIR__, 4) . '/server.php';    // core/server.php of the running release

        passthru(implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-S', "{$host}:{$port}", '-t', $docRoot, $router])), $code);

        return $code;
    }
}
