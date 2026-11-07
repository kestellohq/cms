<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Ide;

use Kestello\Cms\Admin\AdminRegistry;
use Kestello\Cms\Auth\Roles;
use Kestello\Cms\Ide\Meta;
use Kestello\Cms\Ide\Stubs;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Paths;
use Kestello\Kernel;

/**
 * ide:helpers [--out=<folder>] [--stubs] [--check]
 *
 * Writes .phpstorm.meta.php (container types, capability and extension point
 * names) into --out (default: the site root). With --stubs, also
 * kestello.stub.php: the public API for a plugin or theme in its own
 * repository, for editors and PHPStan (stubFiles). --check writes nothing and
 * fails when the meta file in --out is out of date (CI).
 */
final class IdeHelpersCommand extends Command
{
    protected string $name = 'ide:helpers';
    protected string $description = 'Write editor helpers: .phpstorm.meta.php, and kestello.stub.php with --stubs: ide:helpers [--out=<folder>] [--stubs] [--check]';

    /** Works on a checkout that was never installed (CI and the release workflow). */
    public function __construct(private readonly Paths $paths) {}

    public function handle(array $arguments, array $options): int
    {
        $out = rtrim((string) ($this->option($options, 'out') ?? $this->paths->root()), '/');
        if (!is_dir($out)) {
            $this->output->error("  There is no folder {$out}.");
            return 1;
        }
        $version = Kernel::version();
        $meta = Meta::render(array_keys(Roles::CORE), AdminRegistry::POINTS, AdminRegistry::REPLACEABLE);

        if ($this->option($options, 'check') !== null) {
            if (@file_get_contents("{$out}/.phpstorm.meta.php") !== $meta) {
                $this->output->error("  {$out}/.phpstorm.meta.php is out of date. Run: bin/cms ide:helpers");
                return 1;
            }
            $this->output->success("  {$out}/.phpstorm.meta.php is up to date.");
            return 0;
        }

        file_put_contents("{$out}/.phpstorm.meta.php", $meta);
        $this->output->success("  {$out}/.phpstorm.meta.php");
        if ($this->option($options, 'stubs') !== null) {
            $classes = Stubs::publicClasses($this->paths->core('src'));
            $functions = array_values(array_filter(get_defined_functions()['user'], static fn(string $f) => str_starts_with($f, 'kestello\\')));
            $functions = array_map(static fn(string $f) => (new \ReflectionFunction($f))->getName(), $functions);
            sort($functions);
            file_put_contents("{$out}/kestello.stub.php", Stubs::render($classes, $functions, $version));
            $this->output->success("  {$out}/kestello.stub.php (" . count($classes) . ' classes, ' . count($functions) . ' functions)');
        }

        return 0;
    }
}
