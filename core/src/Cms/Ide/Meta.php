<?php

declare(strict_types=1);

namespace Kestello\Cms\Ide;

/**
 * .phpstorm.meta.php: what PhpStorm (and the Intelephense meta support) can't
 * read from types alone. The container returns the class asked for, and the
 * string arguments that name a capability, an extension point or a
 * replaceable component complete from the real lists.
 *
 * Generated from the code (bin/cms ide:helpers), so it never drifts.
 *
 * @internal
 */
final class Meta
{
    /**
     * @param list<string> $capabilities
     * @param array<string, list<string>> $points kind => points (AdminRegistry::POINTS)
     * @param list<string> $replaceable
     */
    public static function render(array $capabilities, array $points, array $replaceable): string
    {
        $set = static fn(string $name, array $values): string => "    registerArgumentsSet('{$name}', " . implode(', ', array_map(static fn(string $v) => var_export($v, true), $values)) . ");\n";
        $admin = '\\Kestello\\Cms\\Admin\\AdminRegistry';

        return "<?php\n\n// Kestello: hints for PhpStorm. Made by `bin/cms ide:helpers`; edit the code, not this file.\n\n"
            . "namespace PHPSTORM_META {\n\n"
            . "    // \$container->make(Foo::class) returns a Foo\n"
            . "    override(\\Kestello\\Framework\\Core\\Container::make(0), map(['' => '@']));\n\n"
            . $set('kestello_capabilities', $capabilities)
            . $set('kestello_widget_areas', $points['widgets'] ?? [])
            . $set('kestello_slots', $points['slots'] ?? [])
            . $set('kestello_columns', $points['columns'] ?? [])
            . $set('kestello_replaceable', $replaceable)
            . "\n"
            . "    expectedArguments(\\Kestello\\Contracts\\Auth\\Principal::can(), 0, argumentsSet('kestello_capabilities'));\n"
            . "    expectedArguments({$admin}::page(), 3, argumentsSet('kestello_capabilities'));\n"
            . "    expectedArguments({$admin}::link(), 6, argumentsSet('kestello_capabilities'));\n"
            . "    expectedArguments({$admin}::widget(), 0, argumentsSet('kestello_widget_areas'));\n"
            . "    expectedArguments({$admin}::widget(), 4, argumentsSet('kestello_capabilities'));\n"
            . "    expectedArguments({$admin}::widget(), 3, 'quarter', 'half', 'full');\n"
            . "    expectedArguments({$admin}::slot(), 0, argumentsSet('kestello_slots'));\n"
            . "    expectedArguments({$admin}::slot(), 2, argumentsSet('kestello_capabilities'));\n"
            . "    expectedArguments({$admin}::column(), 0, argumentsSet('kestello_columns'));\n"
            . "    expectedArguments({$admin}::column(), 4, argumentsSet('kestello_capabilities'));\n"
            . "    expectedArguments({$admin}::replace(), 0, argumentsSet('kestello_replaceable'));\n"
            . "}\n";
    }
}
