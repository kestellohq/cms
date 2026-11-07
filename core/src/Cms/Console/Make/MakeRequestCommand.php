<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Make;

/**
 * Scaffold a new form request class.
 */
final class MakeRequestCommand extends GeneratorCommand
{
    protected string $name = 'make:request';
    protected string $description = 'Create a new form request class';


    public function handle(array $arguments, array $options): int
    {
        if (!$this->target($options)) {
            return 1;
        }

        $name = $this->argument($arguments, 0);

        if ($name === null) {
            $this->output->error('Usage: make:request <Name>');
            return 1;
        }

        if (!str_ends_with($name, 'Request')) {
            $name .= 'Request';
        }

        $dir = $this->plugin['src'] . '/Requests';
        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            $this->output->error("Request already exists: {$path}");
            return 1;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$this->plugin['namespace']}Requests;

        use Kestello\Framework\Validation\FormRequest;

        final class {$name} extends FormRequest
        {
            public function rules(): array
            {
                return [
                    //
                ];
            }

            public function authorize(): bool
            {
                return true;
            }

            public function messages(): array
            {
                return [];
            }

            public function aliases(): array
            {
                return [];
            }
        }
        PHP;

        file_put_contents($path, $content);

        $this->output->success("  Created: {$this->plugin['rel']}/src/Requests/{$name}.php");

        return 0;
    }
}
