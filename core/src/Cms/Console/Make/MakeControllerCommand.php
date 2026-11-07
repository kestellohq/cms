<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Make;

/**
 * Scaffold a new controller class.
 */
final class MakeControllerCommand extends GeneratorCommand
{
    protected string $name = 'make:controller';
    protected string $description = 'Create a new controller class';


    public function handle(array $arguments, array $options): int
    {
        if (!$this->target($options)) {
            return 1;
        }

        $name = $this->argument($arguments, 0);

        if ($name === null) {
            $this->output->error('Usage: make:controller <Name>');
            return 1;
        }

        // Ensure it ends with "Controller"
        if (!str_ends_with($name, 'Controller')) {
            $name .= 'Controller';
        }

        $dir = $this->plugin['src'] . '/Controllers';
        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            $this->output->error("Controller already exists: {$path}");
            return 1;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$this->plugin['namespace']}Controllers;

        use Kestello\Framework\Core\Request;
        use Kestello\Framework\Core\Response;

        final class {$name}
        {
            public function index(Request \$request): Response
            {
                return Response::html('<h1>{$name}</h1>');
            }
        }
        PHP;

        file_put_contents($path, $content);

        $this->output->success("  Created: {$this->plugin['rel']}/src/Controllers/{$name}.php");

        return 0;
    }
}
