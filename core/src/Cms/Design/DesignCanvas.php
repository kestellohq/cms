<?php

declare(strict_types=1);

namespace Kestello\Cms\Design;

use Kestello\Cms\Blocks\BlockContext;
use Kestello\Cms\Blocks\BlockRegistry;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentController;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\View\Page;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * The builder's preview: a template, part or pattern rendered by the
 * real site, drafts included, for people who may design. The builder then
 * sends unsaved trees through POST /design/preview and the canvas swaps
 * them in without reloading.
 */
final class DesignCanvas
{
    public const string ROUTE = '/system/design/canvas';

    public function __construct(private readonly Cms $cms, private readonly ThemeTemplates $templates) {}

    /**
     * The entry a template is previewed with: a published one, or one $p may
     * edit. Designing templates doesn't grant reading other people's drafts.
     */
    public function entry(?int $id, Principal $p): ?array
    {
        if ($id === null || $id < 1) {
            return null;
        }
        $entry = Entry::query()->find($id);
        $type = $entry === null ? null : $this->cms->content->get((string) $entry->type);

        return $entry === null || $type === null || !$this->visible($p, $type, $entry) ? null : [$entry, $type];
    }

    private function visible(Principal $p, ContentType $type, Entry $entry): bool
    {
        return $entry->isPublished() || $this->cms->container->make(\Kestello\Cms\Content\Entries::class)->can($p, $type, 'view', $entry);
    }

    /**
     * Validate (dropping what fails, as rendering does) and prepare a tree
     * the way the site would show it.
     *
     * @return list<array<string, mixed>>
     */
    public function prepare(mixed $blocks, ?array $entry, ?Request $request, ?array $viewer): array
    {
        [$e, $type] = $entry ?? [null, null];
        $ctx = (new BlockContext($e, $type, $request, $viewer))->forTemplate(drafts: true);

        return $this->cms->blocks->prepare($blocks, $ctx, draft: true);
    }

    /**
     * Entries a template can be previewed with: those it would lay out,
     * newest first.
     *
     * @return list<array{id: int, title: string, type: string, status: string}>
     */
    public function sampleEntries(string $kind, string $key, Principal $p, int $limit = 20): array
    {
        $types = array_values(array_filter($this->cms->content->types(), static fn(ContentType $t) => $t->getPath() !== null));
        $names = array_map(static fn(ContentType $t) => $t->name, $types);
        if ($kind === 'template') {
            if (str_starts_with($key, 'entry-')) {
                $names = array_values(array_intersect($names, [substr($key, 6)]));
            } elseif ($key !== 'entry') {
                $doc = $this->templates->get('template', $key, drafts: true);
                if (is_array($doc['for'] ?? null)) {
                    $names = array_values(array_intersect($names, $doc['for']));
                }
            }
        }
        if ($names === []) {
            return [];
        }
        $byName = array_column($types, null, 'name');
        $out = [];
        // a few times the limit: some may be drafts $p can't see
        foreach (Entry::query()->whereIn('type', $names)->whereIn('status', ['published', 'draft', 'pending', 'scheduled'])->orderByDesc('updated_at')->orderByDesc('id')->limit($limit * 5)->get() as $e) {
            if (count($out) >= $limit) {
                break;
            }
            /** @var Entry $row */
            $row = $e;
            if (!$this->visible($p, $byName[(string) $row->getAttribute('type')], $row)) {
                continue;
            }
            $out[] = ['id' => (int) $e->getKey(), 'title' => (string) $e->title, 'type' => (string) $e->type, 'status' => (string) $e->status];
        }

        return $out;
    }

    /**
     * The entry editor's canvas: ?kind=entry&type=page&entry=12&field=body,
     * or without entry for one not saved yet. For whoever may edit it (or
     * create one); anyone else gets a 404, as for any page they can't see.
     */
    private function entryCanvas(Request $request, \Kestello\Contracts\Auth\Principal $principal): Response
    {
        $type = $this->cms->content->get((string) ($request->query['type'] ?? ''));
        if ($type === null) {
            return Response::notFound();
        }
        $entries = $this->cms->container->make(\Kestello\Cms\Content\Entries::class);
        $id = (int) ($request->query['entry'] ?? 0);
        if ($id > 0) {
            $entry = Entry::query()->ofType($type->name)->with('author', 'parent')->find($id);
            if ($entry === null || !$entries->can($principal, $type, 'edit', $entry)) {
                return Response::notFound();
            }
        } else {
            if (!$entries->can($principal, $type, 'create')) {
                return Response::notFound();
            }
            $entry = new Entry();
            $entry->forceFill(['type' => $type->name, 'title' => '', 'slug' => '', 'status' => 'draft', 'data' => []]);
        }
        $field = null;
        $wanted = (string) ($request->query['field'] ?? '');
        foreach ($type->getFields() as $f) {
            if ($f->type === 'blocks' && !$f->isInternal() && ($wanted === '' || $f->name === $wanted)) {
                $field = $f->name;
                break;
            }
        }
        if ($field === null) {
            return Response::notFound();
        }
        // the template chosen in the editor, before it is saved ('' : none). Only for this
        // preview: never stored. templateFor() checks the key and that this type may use it.
        $chosen = $request->query['template'] ?? null;
        if (is_string($chosen) && strlen($chosen) <= 61) {
            $entry->forceFill(['template' => $chosen === '' ? null : $chosen]);
        }

        return ContentController::canvas($this->cms, $request, $type, $entry, ['field' => $field, 'entry' => $id > 0 ? $id : null]);
    }

    /**
     * A pattern on its own, as the site draws it, for the pattern browser's
     * previews (?kind=pattern&key=…&preview=1): no editing on it. Whoever may
     * edit content may look at published patterns; designers at drafts too.
     */
    private function patternPreview(string $key, \Kestello\Contracts\Auth\Principal $principal): Response
    {
        if (!$principal->can('content.access') || preg_match(ThemeTemplates::KEY_PATTERN, $key) !== 1) {
            return Response::notFound();
        }
        $designer = $principal->can('theme.design');
        $published = array_column(array_filter($this->templates->all('pattern'), static fn(array $p) => $p['published']), 'key');
        $doc = $designer || in_array($key, $published, true) ? $this->templates->get('pattern', $key, drafts: $designer) : null;
        if ($doc === null) {
            return Response::notFound();
        }
        $ctx = (new BlockContext(null, null, null, \Kestello\Cms\Auth\Auth::getInstance()?->user()))->forTemplate(drafts: $designer);
        $blocks = $this->cms->blocks->prepare($this->cms->blocks->fit($doc['blocks'] ?? []), $ctx);

        return Page::render('@core/content/TemplateBare', [
            'title' => (string) ($doc['title'] ?? $key),
            'entry' => ['id' => null, 'title' => (string) ($doc['title'] ?? $key), 'path' => null, 'published_at' => null],
            'template' => ['key' => $key, 'blocks' => $blocks],
            'design' => ['preview' => true],
            'seo' => ['noindex' => true],
        ], preload: BlockRegistry::components($blocks))
            ->withHeader('Cache-Control', 'private, max-age=60')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    /** GET /system/design/canvas?kind=&key=&entry= */
    public function handle(Request $request): Response
    {
        try {
            $principal = $this->cms->auth->authenticate($request);
        } catch (\Throwable) {
            return Response::notFound();
        }
        $kind = (string) ($request->query['kind'] ?? '');
        if ($kind === 'entry') {
            return $this->entryCanvas($request, $principal);
        }
        $key = (string) ($request->query['key'] ?? '');
        if ($kind === 'pattern' && ($request->query['preview'] ?? '') === '1') {
            return $this->patternPreview($key, $principal);
        }
        if (!$principal->can('theme.design') || !isset(ThemeTemplates::KINDS[$kind]) || preg_match(ThemeTemplates::KEY_PATTERN, $key) !== 1) {
            return Response::notFound();
        }
        $doc = $this->templates->get($kind, $key, drafts: true);
        if ($doc === null) {
            return Response::notFound();
        }
        $entry = $kind === 'template' ? $this->entry(isset($request->query['entry']) ? (int) $request->query['entry'] : null, $principal) : null;
        $blocks = $this->prepare($doc['blocks'] ?? [], $entry, $request, \Kestello\Cms\Auth\Auth::getInstance()?->user());
        // parts are shown on their own; templates in their own frame; patterns inside the site
        $bare = $kind === 'part' || ($kind === 'template' && ($doc['layout'] ?? 'site') === 'bare');
        [$e] = $entry ?? [null];

        return Page::render($bare ? '@core/content/TemplateBare' : '@core/content/Template', [
            'title' => (string) ($doc['title'] ?? $key),
            'entry' => ['id' => $e?->getKey(), 'title' => $e?->title ?? (string) ($doc['title'] ?? $key), 'path' => null, 'published_at' => null],
            'template' => ['key' => $key, 'blocks' => $blocks],
            'design' => ['canvas' => true, 'kind' => $kind, 'key' => $key, 'entry' => $e?->getKey()],
            'seo' => ['noindex' => true],
        ], preload: BlockRegistry::components($blocks))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
