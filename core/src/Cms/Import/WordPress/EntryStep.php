<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entries;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\Permalinks;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Str;

/**
 * A post, page or entry of another content type. Written through Entries,
 * like an editor's save: validated, sanitized, with a revision, search and
 * webhooks. Only the original dates are set afterwards, since no editor can
 * set those.
 *
 * @internal
 */
final class EntryStep
{
    /** Bumped when conversion improves, so a later import converts unchanged posts again. */
    public const int CONVERSION = 2;

    public function __construct(private readonly Run $run, private readonly ContentConverter $converter) {}

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $p
     */
    public function process(array $item, array $p): Outcome
    {
        $type = $this->run->cms->content->get((string) $p['type']);
        if ($type === null) {
            return Outcome::skipped(sprintf('This site has no content type "%s".', $p['type']));
        }
        $key = (string) $item['source_key'];
        $entries = $this->run->cms->container->make(Entries::class);

        $mapped = $this->run->map->get('entry', $key);
        $entry = null;
        if ($mapped !== null) {
            $entry = Entry::query()->find($mapped['target_id']);
            if (!$entry instanceof Entry) {
                return Outcome::skipped('Deleted on this site after the last import; not brought back.');
            }
        }
        // a post that came over before keeps its author, unless this import says otherwise:
        // someone else running it again is not a reason to credit them with every post
        $author = $entry !== null && !$this->run->choseAuthor((string) $p['author'])
            ? ($entry->getAttribute('author_id') === null ? null : (int) $entry->getAttribute('author_id'))
            : $this->run->author((string) $p['author']);
        $checksum = SourceMap::checksum([self::CONVERSION, $p, $author]);
        if ($mapped !== null && $entry !== null) {
            if ($mapped['checksum'] === $checksum) {
                return Outcome::unchanged((int) $entry->getKey());
            }
            if (self::editedHere($entry, $mapped)) {
                return Outcome::kept((int) $entry->getKey(), 'Changed on this site since the last import; left as it is.');
            }
        }

        $messages = [];
        $this->run->links->reset();
        $input = $this->input($type, $p, $author, $entry, $messages);
        if ($entry === null) {
            $entry = $entries->create($type, $input, $this->run->principal);
        } else {
            $entry = $entries->update($entry, $input, $this->run->principal, (int) $entry->getAttribute('version'));
        }
        $id = (int) $entry->getKey();
        $wanted = Str::slug((string) $p['slug']);
        if ($wanted !== '' && (string) $entry->getAttribute('slug') !== $wanted) {
            $messages[] = sprintf('Its address became "%s": "%s" is taken by this site.', $entry->getAttribute('slug'), $wanted);
        }
        $this->backdate($id, $p);
        $this->run->map->put('entry', $key, 'entry', $id, $checksum, self::hash($entry), (int) $entry->getAttribute('version'));
        $message = $messages === [] ? null : implode(' ', $messages);

        return $mapped === null ? Outcome::created($id, $message, $this->run->links->needsLinks()) : Outcome::updated($id, $message, $this->run->links->needsLinks());
    }

    /**
     * Convert again an entry that linked to items imported after it (or that
     * failed, and came over on Try again), now that they exist; a featured
     * image that was missing is filled in too. Left alone when someone
     * edited it in between.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $p
     * @return bool whether it changed
     */
    public function relink(array $item, array $p): bool
    {
        $type = $this->run->cms->content->get((string) $p['type']);
        $mapped = $this->run->map->get('entry', (string) $item['source_key']);
        $entry = $mapped === null ? null : Entry::query()->find($mapped['target_id']);
        $field = $type === null ? null : self::bodyField($type);
        if (!$entry instanceof Entry || $type === null || $field === null || self::editedHere($entry, $mapped)) {
            return false;
        }
        $this->run->links->reset();
        $data = (array) $entry->data;
        $before = $data;
        $data[$field->name] = $this->body($type, $field, (string) $p['content'], new Notes());
        $image = $type->getField('image');
        if ($image !== null && $image->type === 'media' && (int) $p['thumbnail'] > 0 && empty($data['image'])) {
            $data['image'] = $this->run->links->media((int) $p['thumbnail'], '') ?? ($data['image'] ?? null);
        }
        if ($data == $before) {
            return false;
        }
        $entry = $this->run->cms->container->make(Entries::class)->update($entry, ['data' => $data], $this->run->principal, (int) $entry->getAttribute('version'));
        $this->backdate((int) $entry->getKey(), $p);
        $this->run->map->put('entry', (string) $item['source_key'], 'entry', (int) $entry->getKey(), $mapped['checksum'], self::hash($entry), (int) $entry->getAttribute('version'));

        return true;
    }

    /**
     * What an SEO plugin kept on the post, into the entry's Search and
     * sharing fields (only those its type has). A canonical address on the
     * old site is left out: it would send search engines to a site that is
     * going away, and every page here already names its own address.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $seo SeoMeta::read()
     * @return array<string, mixed>
     */
    private function seo(ContentType $type, array $data, array $seo): array
    {
        $group = $type->getField('seo');
        if ($group === null || $group->type !== 'group') {
            return $data;
        }
        $has = array_flip(array_map(static fn(Field $f) => $f->name, $group->getChildren()));
        $own = is_array($data['seo'] ?? null) ? $data['seo'] : [];
        foreach (['title', 'description'] as $key) {
            if (isset($has[$key]) && is_string($seo[$key] ?? null) && $seo[$key] !== '') {
                $own[$key] = $seo[$key];
            }
        }
        if (isset($has['noindex']) && !empty($seo['noindex'])) {
            $own['noindex'] = true;
        }
        $canonical = is_string($seo['canonical'] ?? null) ? $seo['canonical'] : '';
        if (isset($has['canonical']) && $canonical !== '' && !$this->run->links->isOld($canonical)) {
            $own['canonical'] = $canonical;
        }
        if (isset($has['image']) && ((int) ($seo['image_id'] ?? 0) > 0 || is_string($seo['image_url'] ?? null))) {
            $media = $this->run->links->media((int) ($seo['image_id'] ?? 0), (string) ($seo['image_url'] ?? ''));
            if ($media !== null) {
                $own['image'] = $media;
            }
        }
        if ($own !== []) {
            $data['seo'] = $own;
            $this->run->notes->add('seo:' . $seo['source']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $p
     * @param list<string> $messages
     * @return array<string, mixed>
     */
    private function input(ContentType $type, array $p, ?int $author, ?Entry $entry, array &$messages): array
    {
        $status = Stager::STATUSES[$p['status']] ?? 'draft';
        if ($p['protected'] && $status === 'published') {
            $status = 'private';
            $messages[] = 'Password-protected in WordPress; private here, since Kestello has no post passwords.';
        }
        $title = trim(html_entity_decode(strip_tags((string) $p['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $input = [
            'title' => mb_substr($title !== '' ? $title : 'Untitled', 0, 255),
            'status' => $status,
            'published_at' => in_array($status, ['draft', 'pending'], true) ? null : self::publishedAt($p),
            'menu_order' => (int) $p['menu_order'],
            'author_id' => $author,
        ];

        $data = $entry === null ? [] : (array) $entry->data;
        $field = self::bodyField($type);
        if ($field !== null) {
            $data[$field->name] = $this->body($type, $field, (string) $p['content'], $this->run->notes);
        } elseif (trim(strip_tags((string) $p['content'])) !== '') {
            $messages[] = sprintf('The text was left out: the %s type has no field for it.', strtolower($type->singular()));
        }
        $image = $type->getField('image');
        if ($image !== null && $image->type === 'media' && (int) $p['thumbnail'] > 0) {
            $media = $this->run->links->media((int) $p['thumbnail'], '');
            if ($media !== null) {
                $data['image'] = $media;
            } else {
                $messages[] = 'Its featured image was not imported.';
            }
        }
        if (is_array($p['seo'] ?? null)) {
            $data = $this->seo($type, $data, $p['seo']);
        }
        $input['data'] = $data;

        if ($type->hasExcerpt()) {
            $excerpt = trim(html_entity_decode(strip_tags((string) $p['excerpt']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $input['excerpt'] = $excerpt === '' ? null : mb_substr($excerpt, 0, 1000);
        }

        $parentId = null;
        if ($type->isHierarchical() && (int) $p['parent'] > 0) {
            $parentId = $this->run->map->targetId('entry', (string) $p['parent']);
            if ($parentId === null) {
                $messages[] = 'Its parent page was not imported, so it is at the top level.';
            }
        }
        $input['parent_id'] = $parentId;

        $slug = Str::slug((string) $p['slug']);
        if ($slug !== '') {
            $entries = $this->run->cms->container->make(Entries::class);
            $wanted = $slug;
            // the first free address: not one the site uses itself, not one another entry has
            $except = $entry?->getKey();
            for ($i = 2; ($entries->reservedBy($type, $slug, $parentId) !== null || $entries->uniqueSlug($type, $slug, $parentId, $except) !== $slug) && $i < 100; $i++) {
                $slug = $wanted . '-' . $i;
            }
            $input['slug'] = $slug;     // what it became is read back after saving: another entry may hold this one too
        }

        $terms = [];
        foreach ((array) $p['terms'] as $t) {
            $taxonomy = Stager::TAXONOMIES[$t['taxonomy']] ?? (string) $t['taxonomy'];
            $id = $this->run->map->targetId('term', $t['taxonomy'] . ':' . $t['slug']);
            $terms[$taxonomy][] = $id ?? (string) $t['name'];
        }
        foreach ($this->run->cms->content->taxonomiesFor($type->name) as $taxonomy) {
            $input['terms'][$taxonomy->name] = array_slice(array_values(array_unique($terms[$taxonomy->name] ?? [], SORT_REGULAR)), 0, \Kestello\Cms\Content\Terms::MAX_PER_ENTRY);
        }

        return $input;
    }

    /** The converted body for the type's text field: blocks, or HTML for a rich text field. */
    private function body(ContentType $type, Field $field, string $content, Notes $notes): mixed
    {
        if ($field->type === 'rich_text') {
            return implode("\n", array_map(static fn(array $n) => (string) $n['props']['text'], $this->converter->fallback($content)));
        }
        $converted = $this->converter->convert($content);
        $allowed = $field->getOption('allowed');
        $allowed = is_array($allowed) ? array_values(array_map('strval', $allowed)) : null;
        // checked here only to choose; Entries validates and cleans it again when saving
        if ($this->run->cms->blocks->validate($converted['blocks'], $allowed)[1] === []) {
            $notes->addAll($converted['notes']);
            return $converted['blocks'];
        }
        // too many blocks, too deep, or blocks this field does not take: keep the text, lose the layout
        $notes->add('fallback');
        $notes->addAll($converted['notes']);

        return $this->converter->fallback($content);
    }

    /** The field the post body goes into: the first blocks field, else the first rich text field. */
    public static function bodyField(ContentType $type): ?Field
    {
        foreach (['blocks', 'rich_text'] as $kind) {
            foreach ($type->getFields() as $field) {
                if ($field->type === $kind && !$field->isInternal()) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Whether someone changed the entry here since the import wrote it. What
     * it says counts, not its version: the scheduler publishing it is no edit.
     *
     * @param array{target_hash: ?string, target_version: ?int} $mapped
     */
    private static function editedHere(Entry $entry, array $mapped): bool
    {
        if ($mapped['target_hash'] !== null) {
            return self::hash($entry) !== $mapped['target_hash'];
        }

        return $mapped['target_version'] !== null && (int) $entry->getAttribute('version') !== $mapped['target_version'];
    }

    /** What an editor can change about an entry, as stored, as one value to compare. */
    private static function hash(Entry $entry): string
    {
        $entry = Entry::query()->find((int) $entry->getKey()) ?? $entry;

        return SourceMap::checksum(array_map(static fn(string $k) => $entry->getAttribute($k), ['title', 'slug', 'data', 'excerpt', 'parent_id', 'author_id']));
    }

    /**
     * UTC publish time: WordPress's GMT date, else its local date read in this site's time zone.
     *
     * @param array<string, mixed> $p
     */
    private static function publishedAt(array $p): ?string
    {
        if ((string) $p['date_gmt'] !== '') {
            return (string) $p['date_gmt'];
        }
        if ((string) $p['date'] === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable((string) $p['date'], Permalinks::timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Created and modified dates as in WordPress; no editor can set these, so they are written directly.
     *
     * @param array<string, mixed> $p
     */
    private function backdate(int $id, array $p): void
    {
        $created = self::publishedAt($p);
        if ($created === null) {
            return;
        }
        $modified = (string) $p['modified_gmt'] !== '' && (string) $p['modified_gmt'] >= $created ? (string) $p['modified_gmt'] : $created;
        QueryBuilder::table('entries')->where('id', $id)->update(['created_at' => $created, 'updated_at' => $modified]);
    }
}
