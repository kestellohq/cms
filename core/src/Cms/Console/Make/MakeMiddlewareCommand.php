<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Make;

/**
 * Scaffold a new middleware class.
 */
final class MakeMiddlewareCommand extends GeneratorCommand
{
    protected string $name = 'make:middleware';
    protected string $description = 'Create a new middleware class';


    public function handle(array $arguments, array $options): int
    {
        if (!$this->target($options)) {
            return 1;
        }

        $name = $this->argument($arguments, 0);

        if ($name === null) {
            $this->output->error('Usage: make:middleware <Name>');
            return 1;
        }

        $dir = $this->plugin['src'] . '/Middleware';
        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            $this->output->error("Middleware already exists: {$path}");
            return 1;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$this->plugin['namespace']}Middleware;

        use Kestello\Framework\Core\Middleware\MiddlewareInterface;
        use Kestello\Framework\Core\Request;
        use Kestello\Framework\Core\Response;
        use Closure;

        final readonly class {$name} implements MiddlewareInterface
        {
            public function handle(Request \$request, Closure \$next): Response
            {
                // Before request...

                \$response = \$next(\$request);

                // After request...

                return \$response;
            }
        }
        PHP;

        file_put_contents($path, $content);

        $this->output->success("  Created: {$this->plugin['rel']}/src/Middleware/{$name}.php");

        return 0;
    }
}
