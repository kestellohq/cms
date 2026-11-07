<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Fields;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Blocks\BlockRegistry;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;

/**
 * A block tree ($cms->blocks). Option "allowed": block types allowed at the
 * top level (default: every block without a parent rule).
 *
 *   Field::blocks('body')->label('Content')
 *   Field::blocks('hero')->option('allowed', ['core/heading', 'core/image'])
 */
final class BlocksField extends FieldType
{
    public function __construct(private readonly ?BlockRegistry $blocks = null) {}

    public function name(): string
    {
        return 'blocks';
    }

    public function component(): string
    {
        return '@core/fields/Blocks';
    }

    private function registry(): BlockRegistry
    {
        return $this->blocks ?? Cms::instance()?->blocks ?? new BlockRegistry();
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        return Schema::any(maxBytes: 2_097_152, maxDepth: BlockRegistry::MAX_DEPTH * 3 + 8);
    }

    public function check(mixed $value, Field $field, FieldTypes $types): array
    {
        return $this->registry()->validate($value, self::allowed($field))[1];
    }

    public function normalize(mixed $value, Field $field, FieldTypes $types): mixed
    {
        $tree = $this->registry()->validate($value, self::allowed($field))[0];

        return $tree === [] ? null : $tree;
    }

    public function media(mixed $value, Field $field, FieldTypes $types): array
    {
        return $this->registry()->mediaIn($value);
    }

    public function relations(mixed $value, Field $field, FieldTypes $types): array
    {
        return $this->registry()->relationsIn($value);
    }

    public function describe(Field $field, FieldTypes $types): array
    {
        return ['allowed' => self::allowed($field)] + $field->getOptions();
    }

    /** @return list<string>|null */
    private static function allowed(Field $field): ?array
    {
        $a = $field->getOption('allowed');

        return is_array($a) ? array_values(array_filter($a, 'is_string')) : null;
    }

    /** One line per block: its label and its text, nested blocks indented. */
    public function text(mixed $value, Field $field, FieldTypes $types): string
    {
        $lines = [];
        $walk = function (array $nodes, string $indent) use (&$walk, &$lines): void {
            foreach ($nodes as $node) {
                $node = (array) $node;
                $name = (string) ($node['type'] ?? '');
                $label = $this->registry()->get($name)?->getLabel() ?? $name;
                $bits = [];
                foreach ((array) ($node['props'] ?? []) as $key => $prop) {
                    if (is_string($prop) && trim($prop) !== '') {
                        $text = str_contains($prop, '<') ? \Kestello\Support\Html::toText($prop) : $prop;
                        $bits[] = in_array($key, ['text', 'html', 'value', 'content', 'caption', 'title', 'label'], true) ? str_replace("\n", ' ', $text) : $key . ' ' . $text;
                    } elseif (is_bool($prop) || is_int($prop) || is_float($prop)) {
                        $bits[] = $key . ' ' . (is_bool($prop) ? ($prop ? 'on' : 'off') : $prop);
                    }
                }
                $lines[] = $indent . $label . ($bits === [] ? '' : ': ' . implode(' · ', $bits));
                $walk((array) ($node['children'] ?? []), $indent . '  ');
            }
        };
        $walk(is_array($value) ? $value : [], '');

        return implode("\n", $lines);
    }
}
