<?php

declare(strict_types=1);

namespace Kestello\Framework\Testing;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Autoloader;

/**
 * php bin/cms test [path] [--filter=Name]
 */
final class TestCommand extends Command
{
    protected string $name = 'test';
    protected string $description = 'Run the test suite (tests/**/*Test.php)';

    public function __construct(
        private readonly string $basePath,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $directory = $this->argument($arguments, 0) ?? 'tests';
        $directory = str_starts_with($directory, '/') ? $directory : $this->basePath . '/' . $directory;

        $autoloader = new Autoloader();
        $autoloader->addNamespace('Kestello\\Tests\\', $this->basePath . '/tests');
        $autoloader->register();

        $runner = new TestRunner();

        return $runner->run($directory, $this->option($options, 'filter')) ? 0 : 1;
    }
}
