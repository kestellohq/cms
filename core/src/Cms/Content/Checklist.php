<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Closure;
use InvalidArgumentException;
use Kestello\Cms\Cms;
use Kestello\Support\Str;
use Kestello\Support\Translator;

/**
 * Editorial checklists: what an entry must have, or should have, before it is published
 * ($cms->content->checks).
 *
 *   $cms->content->type('post')->checklist([
 *       ['check' => 'featured_image', 'level' => 'required'],
 *       ['check' => 'terms', 'taxonomy' => 'category'],          // recommended unless it says required
 *       ['check' => 'words', 'min' => 300],
 *   ]);
 *
 *   // a plugin's own check, named after the plugin
 *   $cms->content->checks->define('acme.disclaimer', 'Ends with the disclaimer',
 *       fn(array $entry, array $item): bool => str_contains(strip_tags($entry['data']['body'] ?? ''), 'Not financial advice'));
 *
 * Core checks: excerpt, featured_image (the type's first image field), terms (with
 * "taxonomy"), words (with "min", counting the text search reads), search_description.
 *
 * A required item that fails stops an entry from being published or scheduled; saving a draft
 * or submitting it for review still works. Recommended items are shown in the editor only.
 * Entries already public when an item is added aren't held back by it until they are
 * unpublished. A check that isn't available (its plugin is off, or the taxonomy no longer
 * applies) is shown as such and never blocks.
 *
 * An entry, as checks see it: ['title' => string, 'excerpt' => ?string, 'data' => array,
 * 'terms' => array<taxonomy, int count>].
 *
 * @api
 */
final class Checklist
{
    public const array LEVELS = ['required', 'recommended'];

    public const array CORE = ['excerpt', 'featured_image', 'terms', 'words', 'search_description'];

    public const int MAX_ITEMS = 20;

    private const string NAME = '/^[a-z][a-z0-9_-]{0,31}(\.[a-z][a-z0-9_-]{0,31})?$/';

    /** @var array<string, array{label: string|Closure, test: Closure}> plugin checks */
    private array $defined = [];

    public function __construct(private readonly ContentRegistry $content) {}

    /**
     * A plugin's own check, named "<plugin>.<check>". The label may be a closure of the item
     * (fn(array $item): string), the test gets the entry and the item: fn(array $entry, array $item): bool.
     */
    public function define(string $name, string|Closure $label, Closure $test): void
    {
        if (preg_match(self::NAME, $name) !== 1 || !str_contains($name, '.')) {
            throw new InvalidArgumentException("A checklist check is named \"<plugin>.<check>\", not [{$name}].");
        }
        if (isset($this->defined[$name])) {
            throw new InvalidArgumentException("The checklist check [{$name}] is already defined.");
        }
        $this->defined[$name] = ['label' => $label, 'test' => $test];
    }

    /** @return list<string> every check that can be used: core ones, then plugins' */
    public function names(): array
    {
        return [...self::CORE, ...array_keys($this->defined)];
    }

    /**
     * Every check, for the builder: its name, a label, and the option it needs (taxonomy or min).
     *
     * @return list<array{name: string, label: string, needs: ?string}>
     */
    public function catalog(): array
    {
        $out = [
            ['name' => 'excerpt', 'label' => Translator::get('Has an excerpt'), 'needs' => null],
            ['name' => 'featured_image', 'label' => Translator::get('Has a featured image'), 'needs' => null],
            ['name' => 'terms', 'label' => Translator::get('Has a term of a taxonomy'), 'needs' => 'taxonomy'],
            ['name' => 'words', 'label' => Translator::get('Has a minimum number of words'), 'needs' => 'min'],
            ['name' => 'search_description', 'label' => Translator::get('Has a search description'), 'needs' => null],
        ];
        foreach ($this->defined as $name => $def) {
            $out[] = ['name' => $name, 'label' => is_string($def['label']) ? Translator::get($def['label']) : $name, 'needs' => null];
        }

        return $out;
    }

    /**
     * One checklist item, checked and filled in: level defaults to recommended, and the
     * options each core check needs are there.
     *
     * @param array<mixed> $item
     * @return array{check: string, level: string, taxonomy?: string, min?: int}
     * @throws InvalidArgumentException
     */
    public static function item(array $item): array
    {
        $check = $item['check'] ?? null;
        if (!is_string($check) || preg_match(self::NAME, $check) !== 1 || (!str_contains($check, '.') && !in_array($check, self::CORE, true))) {
            throw new InvalidArgumentException('Unknown checklist check [' . (is_scalar($check) ? $check : gettype($check)) . '].');
        }
        $level = $item['level'] ?? 'recommended';
        if (!in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException("A checklist item is required or recommended, not [{$level}].");
        }
        $out = ['check' => $check, 'level' => $level];
        if ($check === 'terms') {
            $tax = $item['taxonomy'] ?? null;
            if (!is_string($tax) || preg_match('/^[a-z][a-z0-9-]{0,31}$/', $tax) !== 1) {
                throw new InvalidArgumentException('The "terms" checklist item needs a taxonomy.');
            }
            $out['taxonomy'] = $tax;
        }
        if ($check === 'words') {
            $min = $item['min'] ?? null;
            if (!is_int($min) || $min < 1 || $min > 100_000) {
                throw new InvalidArgumentException('The "words" checklist item needs a min of 1 to 100000.');
            }
            $out['min'] = $min;
        }

        return $out;
    }

    /**
     * Every item of the type's checklist, checked against the entry.
     *
     * @param array{title?: string, excerpt?: ?string, data?: array<string, mixed>, terms?: array<string, int>} $entry
     * @return list<array{check: string, level: string, label: string, passed: ?bool}> passed is null when the check isn't available
     */
    public function run(ContentType $type, array $entry): array
    {
        $entry += ['title' => '', 'excerpt' => null, 'data' => [], 'terms' => []];
        $out = [];
        foreach ($type->getChecklist() as $item) {
            [$label, $passed] = $this->evaluate($type, $item, $entry);
            $out[] = ['check' => $item['check'], 'level' => $item['level'], 'label' => $label, 'passed' => $passed];
        }

        return $out;
    }

    /**
     * The labels of the required items the entry fails: why it can't be published yet.
     *
     * @param array{title?: string, excerpt?: ?string, data?: array<string, mixed>, terms?: array<string, int>} $entry
     * @return list<string>
     */
    public function blocking(ContentType $type, array $entry): array
    {
        return array_values(array_map(
            static fn(array $r): string => $r['label'],
            array_filter($this->run($type, $entry), static fn(array $r): bool => $r['level'] === 'required' && $r['passed'] === false),
        ));
    }

    /**
     * @param array{check: string, level: string, taxonomy?: string, min?: int} $item
     * @param array{title: string, excerpt: ?string, data: array<string, mixed>, terms: array<string, int>} $entry
     * @return array{0: string, 1: ?bool}
     */
    private function evaluate(ContentType $type, array $item, array $entry): array
    {
        $data = $entry['data'];
        switch ($item['check']) {
            case 'excerpt':
                return [Translator::get('Has an excerpt'), $type->hasExcerpt() ? trim((string) $entry['excerpt']) !== '' : null];
            case 'featured_image':
                $field = ContentController::featuredField($type);

                return [Translator::get('Has a featured image'), $field === null ? null : self::filled($data[$field->name] ?? null)];
            case 'terms':
                $tax = $this->content->getTaxonomy((string) $item['taxonomy']);
                if ($tax === null || !$tax->appliesTo($type->name)) {
                    return [Translator::get('Has a term of {taxonomy}', ['taxonomy' => $item['taxonomy']]), null];
                }

                return [Translator::get('Has at least one of the {terms}', ['terms' => mb_strtolower($tax->plural())]), ($entry['terms'][$tax->name] ?? 0) > 0];
            case 'words':
                $min = (int) $item['min'];

                return [Translator::get('At least {min} words', ['min' => $min]), Str::wordCount(self::text($type, $entry)) >= $min];
            case 'search_description':
                $seo = $type->getField('seo');
                $own = is_array($data['seo'] ?? null) ? $data['seo'] : [];

                return [Translator::get('Has a search description'), $seo === null ? null : trim((string) ($own['description'] ?? '')) !== ''];
        }
        $def = $this->defined[$item['check']] ?? null;
        if ($def === null) {
            return [Translator::get('{check} (not available: is its plugin active?)', ['check' => $item['check']]), null];
        }
        $label = $def['label'] instanceof Closure ? (string) ($def['label'])($item) : Translator::get($def['label']);

        return [$label, (bool) ($def['test'])($entry, $item)];
    }

    private static function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [] && $value !== 0;
    }

    /** @param array{title: string, excerpt: ?string, data: array<string, mixed>, terms: array<string, int>} $entry */
    private static function text(ContentType $type, array $entry): string
    {
        $search = Cms::instance()?->search;

        return $search === null ? '' : $search->entryText($type, $entry['data'], $entry['excerpt']);
    }
}
