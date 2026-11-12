<?php

declare(strict_types=1);

namespace Kestello\Cms\Blocks;

use Closure;
use InvalidArgumentException;
use Kestello\Cms\Content\Entry;
use Kestello\Framework\Log\Log;
use Kestello\Framework\Settings\Settings;

/**
 * Data a block prop can show instead of typed text: {"bind": "entry.title"}.
 * Resolved on the server at render time, with what the page already shows
 * the viewer: the current entry (the loop item inside a query) and public
 * site settings. Internal fields are never reachable.
 *
 *   $cms->blocks->bindings->source('acme', ['acme.price' => 'Price'],
 *       fn(string $path, BlockContext $ctx) => Prices::for($ctx->entry));
 *
 * @api
 */
final class Bindings
{
    /** @var array<string, array{paths: array<string, string>, resolve: Closure}> */
    private array $sources = [];

    public function __construct()
    {
        $this->source('site', ['site.name' => 'Site name', 'site.tagline' => 'Tagline', 'site.url' => 'Site address'], static function (string $path): ?string {
            $s = Settings::getInstance();
            $config = \Kestello\Cms\Cms::instance()?->config;

            return match ($path) {
                'site.name' => (string) (\Kestello\Cms\I18n\SiteText::translated('site_name') ?? $s->get('site_name') ?? $config?->get('app.name', 'Kestello')),
                'site.tagline' => (string) (\Kestello\Cms\I18n\SiteText::translated('site_description') ?? $s->get('site_description') ?? $config?->get('app.description', '')),
                'site.url' => (string) ($s->get('site_url') ?? $config?->get('app.url', '/')),
                default => null,
            };
        });
        $this->source('entry', [
            'entry.title' => 'Title', 'entry.excerpt' => 'Excerpt', 'entry.url' => 'Address',
            'entry.published_at' => 'Publish date', 'entry.author' => 'Author', 'entry.fields.*' => 'A field',
        ], static function (string $path, BlockContext $ctx): mixed {
            $entry = $ctx->entry;
            if (!$entry instanceof Entry) {
                return null;
            }
            if (str_starts_with($path, 'entry.fields.')) {
                $field = $ctx->type?->getField(substr($path, 13));

                return $field === null || $field->isInternal() ? null : $entry->field($field->name);
            }

            return match ($path) {
                'entry.title' => $entry->title,
                'entry.excerpt' => $entry->excerpt,
                'entry.url' => $ctx->type !== null ? $entry->url($ctx->type) : null,
                'entry.published_at' => \Kestello\Framework\Database\Orm\Caster::present($entry->published_at),
                'entry.author' => $entry->author?->username,
                default => null,
            };
        });
    }

    /**
     * @param array<string, string> $paths path => label ("<source>.<name>", "*" for any last segment)
     * @param Closure(string, BlockContext): mixed $resolve
     */
    public function source(string $name, array $paths, Closure $resolve): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,39}$/', $name) !== 1) {
            throw new InvalidArgumentException("Binding source names are lowercase: \"{$name}\".");
        }
        foreach (array_keys($paths) as $path) {
            if (!str_starts_with($path, $name . '.')) {
                throw new InvalidArgumentException("Paths of the \"{$name}\" source start with \"{$name}.\".");
            }
        }
        $this->sources[$name] = ['paths' => $paths, 'resolve' => $resolve];
    }

    /** Whether a bind path names something a source offers. */
    public function known(string $path): bool
    {
        if (preg_match('/^[a-z][a-z0-9-]*(\.[a-z_][a-z0-9_]{0,63}){1,3}$/', $path) !== 1) {
            return false;
        }
        $source = $this->sources[explode('.', $path)[0]] ?? null;
        if ($source === null) {
            return false;
        }
        foreach (array_keys($source['paths']) as $offered) {
            if ($offered === $path || (str_ends_with($offered, '.*') && str_starts_with($path, substr($offered, 0, -1)) && substr_count($path, '.') === substr_count($offered, '.'))) {
                return true;
            }
        }

        return false;
    }

    public function resolve(string $path, BlockContext $ctx): mixed
    {
        if (!$this->known($path)) {
            return null;
        }
        try {
            return ($this->sources[explode('.', $path)[0]]['resolve'])($path, $ctx);
        } catch (\Throwable $e) {
            Log::error("blocks: binding {$path} failed: " . $e->getMessage());

            return null;
        }
    }

    /** @return list<array{path: string, label: string}> for the editor */
    public function describe(): array
    {
        $out = [];
        foreach ($this->sources as $source) {
            foreach ($source['paths'] as $path => $label) {
                $out[] = ['path' => $path, 'label' => $label];
            }
        }

        return $out;
    }
}
