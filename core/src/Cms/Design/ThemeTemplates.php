<?php

declare(strict_types=1);

namespace Kestello\Cms\Design;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entry;
use Kestello\Framework\Log\Log;
use Kestello\Support\Translator;

/**
 * Templates, template parts and patterns: block trees a theme ships as
 * files and the site changes in the admin.
 *
 *   templates/entry-post.json   the template for every post
 *   templates/entry.json        any entry without a template of its own
 *   templates/wide.json         a custom template entries can choose ("for": ["page"])
 *   parts/header.json           used by templates through the core/template-part block
 *   patterns/hero.json          offered in the block inserter; "synced" ones stay linked
 *
 * Core ships patterns too (core/resources/patterns, CORE_PATTERNS): every
 * site has them, and a plugin's or theme's file with the same key replaces
 * one. A pattern with "layout": true is a whole page, offered when a page
 * starts empty.
 *
 * Active plugins ship the same folders. Their keys carry the plugin's slug
 * ("<slug>-…", or "entry-<slug>…" for the template of a type the plugin
 * declares), and a theme's file with the same key replaces the plugin's.
 *
 * A child theme's file replaces its parent's with the same name. The site's
 * published version replaces the file, and a draft sits beside it until it
 * is published (people who may design see drafts with ?design=draft).
 * Templates, parts and patterns created in the admin exist only as
 * documents.
 */
final class ThemeTemplates
{
    public const array KINDS = ['template' => 'templates', 'part' => 'parts', 'pattern' => 'patterns'];
    public const array AREAS = ['header' => 'Header', 'footer' => 'Footer', 'sidebar' => 'Sidebar', 'general' => 'General'];
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,60}$/';
    /** The patterns core ships, below every plugin's and theme's. */
    public const string CORE_PATTERNS = __DIR__ . '/../../../resources/patterns';

    /** @var array<string, array<string, array<string, mixed>>> kind => key => file data */
    private array $files = [];

    /** @var array<string, array<string, string>> kind => key => sha256 of the file as shipped */
    private array $hashes = [];

    /** @var array<string, array<string, string>> kind => key => where the file comes from: core, or a package's slug */
    private array $origins = [];

    public function __construct(private readonly Cms $cms, private readonly ThemeDocuments $documents) {}

    private function theme(): ?string
    {
        try {
            return $this->cms->extensions()->activeTheme()?->slug();
        } catch (\LogicException) {
            return null;
        }
    }

    // ── reading ─────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> key => data from the theme chain's files */
    public function files(string $kind): array
    {
        self::checkKind($kind);
        if (isset($this->files[$kind])) {
            return $this->files[$kind];
        }
        $out = [];
        try {
            // Plugins first, so the theme chain (parent, then child) replaces their files.
            $sources = [...array_values($this->cms->extensions()->activePlugins()), ...array_reverse($this->cms->extensions()->themeChain())];
        } catch (\LogicException) {
            $sources = [];
        }
        // core's patterns first: every package's file replaces one with the same key
        $folders = $kind === 'pattern' ? [['core', self::CORE_PATTERNS, false]] : [];
        foreach ($sources as $theme) {
            $folders[] = [$theme->slug(), $theme->path(self::KINDS[$kind]), $theme->type() === \Kestello\Cms\Extension\PackageType::Plugin];
        }
        foreach ($folders as [$origin, $folder, $plugin]) {
            foreach (glob($folder . '/*.json') ?: [] as $file) {
                $key = basename($file, '.json');
                if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                    continue;
                }
                if ($plugin && !self::ownedBy($key, $origin)) {
                    Log::warning("plugin {$origin}: " . self::KINDS[$kind] . "/{$key}.json is skipped; plugin keys start with \"{$origin}-\" or \"entry-{$origin}\".");
                    continue;
                }
                $raw = (string) file_get_contents($file);
                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    Log::warning("{$origin}/" . self::KINDS[$kind] . "/{$key}.json is not valid JSON.");
                    continue;
                }
                [$clean] = $this->normalize($kind, $key, $data, strict: false);
                $out[$key] = $clean;
                $this->hashes[$kind][$key] = hash('sha256', $raw);
                $this->origins[$kind][$key] = $origin;
            }
        }
        ksort($out);

        return $this->files[$kind] = $out;
    }

    /**
     * One document as the site sees it: the draft (when asked for and one
     * exists), else the site's published version, else the theme's file.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $kind, string $key, bool $drafts = false): ?array
    {
        self::checkKind($kind);
        $theme = $this->theme();
        if ($theme === null) {
            return null;
        }
        if ($drafts && ($d = $this->documents->get($theme, $kind, $key, 'draft')) !== null) {
            return $d;
        }

        return $this->documents->get($theme, $kind, $key) ?? $this->files($kind)[$key] ?? null;
    }

    /**
     * Everything of one kind, for the admin list.
     *
     * @return list<array{key: string, title: string, source: string, draft: bool, info: ?array<string, mixed>, data: array<string, mixed>}>
     */
    public function all(string $kind): array
    {
        self::checkKind($kind);
        $theme = $this->theme();
        $files = $this->files($kind);
        $keys = array_keys($files);
        if ($theme !== null) {
            foreach (\Kestello\Framework\Database\QueryBuilder::table('theme_documents')->where('theme', $theme)->where('kind', $kind)->pluck('doc_key') as $k) {
                $keys[] = (string) $k;
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);
        $out = [];
        foreach ($keys as $key) {
            $published = $theme === null ? null : $this->documents->get($theme, $kind, $key);
            $draft = $theme === null ? null : $this->documents->get($theme, $kind, $key, 'draft');
            $data = $published ?? $files[$key] ?? $draft;
            if ($data === null) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'title' => (string) ($data['title'] ?? $key),
                'source' => isset($files[$key]) ? ($published !== null ? 'customized' : (($this->origins[$kind][$key] ?? '') === 'core' ? 'core' : 'theme')) : 'site',
                'draft' => $draft !== null,
                'published' => $published !== null || isset($files[$key]),
                'info' => $theme === null ? null : ($this->documents->info($theme, $kind, $key) ?? $this->documents->info($theme, $kind, $key, 'draft')),
                'theme_update' => $published !== null && $this->hasThemeUpdate($kind, $key),
                'data' => $data,
            ];
        }

        return $out;
    }

    // ── which template ──────────────────────────────────────────────────

    /**
     * The template for an entry: the custom template it chose (when that
     * template is for its type), else entry-{type}, else entry. Null keeps
     * the theme's own Vue page.
     *
     * @return array{key: string, data: array<string, mixed>}|null
     */
    public function templateFor(Entry $entry, ContentType $type, bool $drafts = false): ?array
    {
        $chosen = is_string($entry->template) && $entry->template !== '' ? $entry->template : null;
        if ($chosen !== null && preg_match(self::KEY_PATTERN, $chosen) === 1 && !self::reserved($chosen)) {
            $t = $this->get('template', $chosen, $drafts);
            if ($t !== null && ($t['for'] === null || in_array($type->name, $t['for'], true))) {
                return ['key' => $chosen, 'data' => $t];
            }
        }
        foreach (['entry-' . $type->name, 'entry'] as $key) {
            if (($t = $this->get('template', $key, $drafts)) !== null) {
                return ['key' => $key, 'data' => $t];
            }
        }

        return null;
    }

    /** @return list<array{key: string, title: string}> custom templates an entry of $type may choose */
    public function choices(string $type): array
    {
        $out = [];
        foreach ($this->all('template') as $t) {
            if (!self::reserved($t['key']) && $t['published'] && ($t['data']['for'] === null || in_array($type, $t['data']['for'], true))) {
                $out[] = ['key' => $t['key'], 'title' => $t['title']];
            }
        }

        return $out;
    }

    /** Whether a plugin's file may use this key: its own prefix, or its own types' entry templates. */
    public static function ownedBy(string $key, string $slug): bool
    {
        foreach ([$slug, 'entry-' . $slug] as $prefix) {
            if ($key === $prefix || str_starts_with($key, $prefix . '-')) {
                return true;
            }
        }

        return false;
    }

    /** Templates with these keys are chosen automatically, never per entry. */
    public static function reserved(string $key): bool
    {
        return $key === 'entry' || str_starts_with($key, 'entry-');
    }

    // ── writing ─────────────────────────────────────────────────────────

    /**
     * Save a draft, or publish. A new key creates a document of the site's own.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> the clean data
     * @throws ContentException
     */
    public function save(string $kind, string $key, array $input, ?int $userId, bool $publish, bool $create = false): array
    {
        self::checkKind($kind);
        $theme = $this->theme() ?? throw ContentException::conflict('No theme is running.');
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw ContentException::invalid(['key' => ['Lowercase letters, digits and hyphens.']]);
        }
        $exists = $this->get($kind, $key, drafts: true) !== null;
        if ($create && $exists) {
            throw ContentException::invalid(['key' => ['There is already one with this name.']]);
        }
        if (!$create && !$exists) {
            throw new \OutOfBoundsException('There is no such ' . $kind . '.');
        }
        [$data, $errors] = $this->normalize($kind, $key, $input, strict: true);
        if ($errors !== []) {
            throw ContentException::invalid($errors);
        }
        // the theme file this change starts from stays recorded until the change is reset or rebased
        $base = $this->documents->info($theme, $kind, $key)['base_hash'] ?? $this->documents->info($theme, $kind, $key, 'draft')['base_hash'] ?? $this->fileHash($kind, $key);
        $this->documents->save($theme, $kind, $key, $data, $userId, $publish ? 'published' : 'draft', $publish ? 'Published' : '', $base);

        return $data;
    }

    /** Publish the draft as it is. @throws ContentException */
    public function publishDraft(string $kind, string $key, ?int $userId): void
    {
        $theme = $this->theme() ?? throw ContentException::conflict('No theme is running.');
        $draft = $this->documents->get($theme, $kind, $key, 'draft') ?? throw ContentException::conflict('There is no draft to publish.');
        $this->save($kind, $key, $draft, $userId, publish: true);
    }

    public function discardDraft(string $kind, string $key): bool
    {
        self::checkKind($kind);
        $theme = $this->theme();

        return $theme !== null && $this->documents->discardDraft($theme, $kind, $key);
    }

    /** sha256 of the theme's file version, or null when the theme ships none. */
    public function fileHash(string $kind, string $key): ?string
    {
        $this->files($kind);

        return $this->hashes[$kind][$key] ?? null;
    }

    /**
     * The theme changed its file since the site's version was made: the
     * owner can keep theirs, take the theme's, or compare and merge.
     */
    public function hasThemeUpdate(string $kind, string $key): bool
    {
        $theme = $this->theme();
        $now = $this->fileHash($kind, $key);
        if ($theme === null || $now === null) {
            return false;
        }
        $base = $this->documents->info($theme, $kind, $key)['base_hash'] ?? null;

        return $base !== null && $base !== $now;
    }

    /** "Keep mine": the site's version now counts as based on the theme's current file. */
    public function keepMine(string $kind, string $key): void
    {
        $theme = $this->theme() ?? throw ContentException::conflict('No theme is running.');
        $hash = $this->fileHash($kind, $key) ?? throw ContentException::conflict('The theme has no version of this.');
        $this->documents->rebase($theme, $kind, $key, $hash);
    }

    /** @return string|null the running theme's slug */
    public function themeSlug(): ?string
    {
        return $this->theme();
    }

    /** @return array<string, mixed>|null the theme's file version, if it ships one */
    public function file(string $kind, string $key): ?array
    {
        return $this->files($kind)[$key] ?? null;
    }

    /**
     * Back to the theme's file, or (for documents only the site has) delete.
     * Either way the last version stays restorable.
     */
    public function reset(string $kind, string $key, ?int $userId): void
    {
        self::checkKind($kind);
        $theme = $this->theme() ?? throw ContentException::conflict('No theme is running.');
        $this->documents->reset($theme, $kind, $key, $userId);
    }

    /** @return array{theme: string, kind: string, key: string, data: ?array<string, mixed>}|null */
    public function revision(int $id): ?array
    {
        return $this->documents->revision($id);
    }

    // ── checking ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    public function normalize(string $kind, string $key, array $in, bool $strict): array
    {
        $errors = [];
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            $title = ucfirst(str_replace('-', ' ', $key));
        }
        $out = ['title' => mb_substr($title, 0, 120), 'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 300)];

        // plain arrays (a tree may arrive with objects from an earlier check)
        $tree = json_decode((string) json_encode($in['blocks'] ?? []), true);
        [$blocks, $blockErrors] = $this->cms->blocks->validate(is_array($tree) ? $tree : []);
        if ($blockErrors !== []) {
            if ($strict) {
                $errors['blocks'] = array_slice($blockErrors, 0, 10);
            } else {
                Log::warning("{$kind} \"{$key}\": invalid blocks dropped: " . implode(' | ', array_slice($blockErrors, 0, 3)));
            }
        }
        $out['blocks'] = $blocks;

        if ($kind === 'template') {
            $out['layout'] = ($in['layout'] ?? 'site') === 'bare' ? 'bare' : 'site';
            $for = $in['for'] ?? null;
            if (self::reserved($key)) {
                $for = null;              // entry-{type} and entry choose themselves
            } elseif ($for !== null) {
                $for = array_values(array_unique(array_filter((array) $for, fn($t) => is_string($t) && $this->cms->content->get($t) !== null)));
                $for = $for === [] ? null : $for;
            }
            $out['for'] = $for;
            if (str_starts_with($key, 'entry-') && $this->cms->content->get(substr($key, 6)) === null && $strict) {
                $errors['key'][] = Translator::get('There is no content type "{type}".', ['type' => substr($key, 6)]);
            }
        } elseif ($kind === 'part') {
            $out['area'] = isset(self::AREAS[$in['area'] ?? '']) ? $in['area'] : 'general';
            if (self::uses($blocks, 'core/template-part')) {
                $errors['blocks'][] = Translator::get('A part can\'t contain another part.');
            }
        } else {
            $out['category'] = mb_substr(trim((string) ($in['category'] ?? '')), 0, 40) ?: 'General';
            $out['synced'] = !empty($in['synced']);
            $out['layout'] = !empty($in['layout']) && empty($in['synced']);       // a whole page; a synced one stays a section
            if (self::uses($blocks, 'core/pattern')) {
                $errors['blocks'][] = Translator::get('A pattern can\'t contain a synced pattern.');
            }
        }
        if ($strict) {
            // a part or synced pattern placed here must exist (a synced pattern must still be synced)
            foreach (self::references($blocks) as [$refKind, $refKey]) {
                $ref = $refKind === $kind && $refKey === $key ? null : $this->get($refKind, $refKey, drafts: true);
                if ($ref === null || ($refKind === 'pattern' && empty($ref['synced']))) {
                    $errors['blocks'][] = ($refKind === 'part' ? 'There is no part "' : 'There is no synced pattern "') . $refKey . '".';
                }
            }
        } else {
            unset($errors['blocks']);
        }

        return [$out, $errors];
    }

    /** @param list<array<string, mixed>> $nodes @return list<array{0: string, 1: string}> parts and synced patterns placed in a tree */
    private static function references(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $props = (array) ($n['props'] ?? []);
            if (($n['type'] ?? null) === 'core/template-part') {
                $out[] = ['part', (string) ($props['part'] ?? '')];
            } elseif (($n['type'] ?? null) === 'core/pattern') {
                $out[] = ['pattern', (string) ($props['pattern'] ?? '')];
            }
            $out = [...$out, ...self::references($n['children'] ?? [])];
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function uses(array $nodes, string $type): bool
    {
        foreach ($nodes as $n) {
            if (($n['type'] ?? null) === $type || self::uses($n['children'] ?? [], $type)) {
                return true;
            }
        }

        return false;
    }

    /** @internal tests */
    public function forget(): void
    {
        $this->files = [];
        $this->hashes = [];
        $this->origins = [];
    }

    private static function checkKind(string $kind): void
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException("Unknown kind \"{$kind}\".");
        }
    }
}
