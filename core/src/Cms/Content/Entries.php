<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use DateTimeImmutable;
use DateTimeZone;
use Kestello\Cms\Events\EntryPublished;
use Kestello\Cms\Events\EntryReviewed;
use Kestello\Cms\Events\EntrySaved;
use Kestello\Cms\Events\EntrySubmitted;
use Kestello\Cms\Events\EntryTrashed;
use Kestello\Cms\I18n\ContentLanguages;
use Kestello\Contracts\Auth\Principal;
use Kestello\Contracts\Cache\LockTimeout;
use Kestello\Contracts\Cache\Store;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\Caster;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Support\Clock;
use Kestello\Support\Str;
use Kestello\Support\Translator;

/**
 * Writing content: permissions, statuses, slugs, revisions, autosaves and
 * the field index. The Entries API and plugins go through here.
 *
 *   $entries->create($cms->content->get('post'), ['title' => 'Hello', 'status' => 'published', 'data' => [...]], $principal);
 *
 * Throws ContentException (invalid / forbidden / conflict).
 *
 * @api
 */
final class Entries
{
    /** How deep pages may nest; addresses, parents and checks all use it. */
    public const int MAX_DEPTH = 25;

    /** How long a save waits for another save of the same type to finish choosing its address. */
    public const int SLUG_LOCK_WAIT = 5;

    /** Top-level addresses the site itself answers; a top-level page can't take them (it would never be reached). */
    public const array RESERVED_PATHS = ['admin', 'api', 'assets', 'install', 'login', 'logout', 'register', 'forgot-password', 'reset-password',
        'search', 'author', 'preview', 'system', 'storage', 'uploads', 'media', 'feed', 'sitemap.xml', 'robots.txt', 'favicon.ico', '.well-known'];

    /** Columns kept in revisions. */
    public const array SNAPSHOT = ['title', 'slug', 'status', 'excerpt', 'data', 'parent_id', 'author_id', 'template', 'menu_order', 'published_at'];

    private readonly Terms $terms;

    public function __construct(
        private readonly ContentRegistry $content,
        private readonly ?EventDispatcher $events = null,
        ?Terms $terms = null,
        private readonly ?Store $locks = null,     // makes choosing a slug and committing it one step across requests
    ) {
        $this->terms = $terms ?? new Terms();
    }

    // ── permissions ──────────────────────────────────────────────────

    /** create, view, edit, publish or delete (own entries, or anyone's with *_others). */
    public function can(Principal $p, ContentType $type, string $action, ?Entry $entry = null): bool
    {
        $own = $entry === null || ($entry->author_id !== null && $entry->author_id === $p->userId);

        return match ($action) {
            'create' => $p->can($type->capability('create')),
            'view', 'edit' => $p->can($type->capability('edit')) && ($own || $p->can($type->capability('edit_others'))),
            'publish' => $p->can($type->capability('publish')) && ($own || $p->can($type->capability('edit_others'))),
            'delete' => $p->can($type->capability('delete')) && ($own || $p->can($type->capability('delete_others'))),
            default => false,
        };
    }

    /** @return array{edit: bool, publish: bool, delete: bool} */
    public function abilities(Principal $p, ContentType $type, Entry $entry): array
    {
        $edit = $this->can($p, $type, 'edit', $entry) && (in_array($entry->status, Entry::UNPUBLISHED, true) || $this->can($p, $type, 'publish', $entry));

        return ['edit' => $edit, 'publish' => $this->can($p, $type, 'publish', $entry), 'delete' => $this->can($p, $type, 'delete', $entry)];
    }

    // ── writes ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input title, slug, status, data, terms ({taxonomy: [ids or new names]}), excerpt, parent_id, author_id, template, menu_order, published_at,
     *                                    locale (one of the site's other languages; default the main one, fixed once created)
     */
    public function create(ContentType $type, array $input, Principal $p): Entry
    {
        if (!$this->can($p, $type, 'create')) {
            throw ContentException::forbidden("You can't create " . strtolower($type->plural()) . '.');
        }
        $locale = self::language($input['locale'] ?? '');
        $entry = (new Entry())->forceFill([
            'type' => $type->name, 'status' => 'draft', 'title' => '', 'slug' => '', 'author_id' => $p->userId,
            'locale' => $locale, 'data' => [], 'menu_order' => 0, 'version' => 0,
        ]);

        return $this->write($type, $entry, $input, $p, null, true);
    }

    /**
     * A draft of $source in another language, linked to it as its
     * translation: the same fields and terms to translate from, its parent's
     * translation as parent (none when that has no translation yet), and a
     * slug from the title. An existing translation is a conflict, not a copy.
     *
     * @param string $locale one of the site's other languages, or '' for the main one
     */
    public function translate(Entry $source, string $locale, Principal $p): Entry
    {
        $type = $this->typeOf($source);
        $locale = self::language($locale);
        if ($locale === $source->language()) {
            throw ContentException::invalid(['locale' => ['This ' . strtolower($type->singular()) . ' is already in that language.']]);
        }
        if (!$this->can($p, $type, 'edit', $source)) {
            throw ContentException::forbidden("You can't edit this " . strtolower($type->singular()) . '.');
        }
        if (($existing = $source->translation($locale, published: false)) !== null) {
            throw ContentException::conflict('This ' . strtolower($type->singular()) . ' already has that translation (#' . $existing->getKey() . ').');
        }

        return DatabaseManager::getConnection()->transaction(function () use ($source, $locale, $type, $p): Entry {
            $group = (string) ($source->translation_group ?? '');
            if ($group === '') {
                $group = Str::ulid();
                Entry::query()->where('id', $source->getKey())->update(['translation_group' => $group]);
                $source->forceFill(['translation_group' => $group]);
            }
            $parent = $source->parent_id === null ? null : Entry::query()->find($source->parent_id);
            $parent = $parent instanceof Entry ? $parent->translation($locale, published: false) : null;
            $terms = [];
            foreach ($this->terms->summaries([(int) $source->getKey()])[(int) $source->getKey()] ?? [] as $taxonomy => $list) {
                $terms[$taxonomy] = array_map(static fn(array $t): int => $t['id'], $list);
            }
            $copy = $this->create($type, [
                'locale' => $locale, 'title' => (string) $source->title, 'excerpt' => $source->excerpt, 'data' => $source->data ?? [],
                'terms' => $terms, 'parent_id' => $parent?->getKey(), 'template' => $source->template, 'menu_order' => (int) $source->menu_order,
            ], $p);
            Entry::query()->where('id', $copy->getKey())->update(['translation_group' => $group]);

            return $copy->forceFill(['translation_group' => $group]);
        });
    }

    /** A language an entry can be written in: '' (the main one) or one of the other languages; anything else is an error. */
    private static function language(mixed $value): string
    {
        $code = is_string($value) ? trim($value) : '';
        if ($code !== '' && !ContentLanguages::has($code)) {
            throw ContentException::invalid(['locale' => [Translator::get('The site isn\'t written in "{code}". Use one of its languages, or leave it empty for the main one.', ['code' => $code])]]);
        }

        return $code;
    }

    /** $expectedVersion: the version the editor loaded; a newer one in the database is a conflict. */
    public function update(Entry $entry, array $input, Principal $p, ?int $expectedVersion = null): Entry
    {
        $type = $this->typeOf($entry);
        if (!$this->can($p, $type, 'edit', $entry)) {
            throw ContentException::forbidden("You can't edit this " . strtolower($type->singular()) . '.');
        }
        if (!in_array($entry->status, Entry::UNPUBLISHED, true) && !$this->can($p, $type, 'publish', $entry)) {
            throw ContentException::forbidden('This ' . strtolower($type->singular()) . ' is ' . $entry->status . '; only someone who can publish may change it.');
        }

        return $this->write($type, $entry, $input, $p, $expectedVersion, false);
    }

    /** Move to the trash (a soft delete; restore() brings it back). */
    public function trash(Entry $entry, Principal $p): void
    {
        $type = $this->typeOf($entry);
        if (!$this->can($p, $type, 'delete', $entry)) {
            throw ContentException::forbidden("You can't delete this " . strtolower($type->singular()) . '.');
        }
        $entry->delete();
        $this->events?->dispatch(new EntryTrashed((int) $entry->getKey(), (string) $entry->type, (string) $entry->title, $p->userId));
    }

    public function restore(Entry $entry, Principal $p): void
    {
        $type = $this->typeOf($entry);
        if (!$this->can($p, $type, 'delete', $entry)) {
            throw ContentException::forbidden("You can't restore this " . strtolower($type->singular()) . '.');
        }
        $entry->restore();
    }

    /**
     * Delete every entry of a type for good, trashed ones included, with
     * their revisions, index rows, term links and search documents. For a
     * plugin's uninstall(): a plugin may only purge its own types ("<slug>"
     * or "<slug>-…"), and the type no longer needs to be declared.
     *
     * @return int entries deleted
     */
    public function purgeType(string $type): int
    {
        ContentRegistry::assertOwnName($type, 'Content types');
        $count = 0;
        do {
            $batch = Entry::query()->withTrashed()->where('type', $type)->orderBy('id')->limit(100)->get()->all();
            foreach ($batch as $entry) {
                QueryBuilder::table('entries')->where('parent_id', $entry->getKey())->update(['parent_id' => null]);
                $entry->forceDelete();      // model events keep search and the page cache right
                $count++;
            }
        } while ($batch !== []);

        return $count;
    }

    /** Delete for good (only from the trash). Revisions and the index go with it. */
    public function destroy(Entry $entry, Principal $p): void
    {
        $type = $this->typeOf($entry);
        if (!$entry->trashed()) {
            throw ContentException::conflict('Move it to the trash first.');
        }
        if (!$this->can($p, $type, 'delete', $entry)) {
            throw ContentException::forbidden("You can't delete this " . strtolower($type->singular()) . '.');
        }
        $entry->forceDelete();
    }

    /** Bring back a revision's content (title, slug, excerpt, fields, template); status stays. */
    public function restoreRevision(Entry $entry, Revision $revision, Principal $p): Entry
    {
        if ($revision->entry_id !== $entry->getKey()) {
            throw ContentException::conflict('That revision belongs to another entry.');
        }
        $snap = (array) $revision->snapshot;
        $input = array_intersect_key($snap, array_flip(['title', 'slug', 'excerpt', 'data', 'template', 'menu_order']));
        $type = $this->typeOf($entry);
        $input['data'] = array_intersect_key((array) ($input['data'] ?? []), array_flip(array_map(static fn(Field $f) => $f->name, $type->getFields())));
        // Terms come back where you may assign them and they still exist.
        foreach ((array) ($snap['terms'] ?? []) as $taxName => $ids) {
            $tax = $this->content->getTaxonomy((string) $taxName);
            if ($tax !== null && $tax->appliesTo($type->name) && $p->can($tax->capability('assign'))) {
                $input['terms'][$tax->name] = array_map('intval', QueryBuilder::table('terms')->where('taxonomy', $tax->name)->whereIn('id', array_map('intval', (array) $ids))->pluck('id'));
            }
        }

        return $this->update($entry, $input, $p);
    }

    /** Save a user's unsaved changes (title, slug, excerpt, data) without touching the entry. */
    public function autosave(Entry $entry, array $input, Principal $p): string
    {
        $type = $this->typeOf($entry);
        if (!$this->abilities($p, $type, $entry)['edit']) {
            throw ContentException::forbidden("You can't edit this " . strtolower($type->singular()) . '.');
        }
        $snapshot = array_intersect_key($input, array_flip(['title', 'slug', 'excerpt', 'data']));
        $now = Model::freshTimestamp();
        QueryBuilder::table('autosaves')->upsert([[
            'entry_id' => $entry->getKey(), 'user_id' => $p->userId,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => $now,
        ]], ['entry_id', 'user_id'], ['snapshot', 'updated_at']);

        return $now;
    }

    /** @return array{saved_at: string, snapshot: array<string, mixed>}|null a user's autosave newer than the entry */
    public function autosaveFor(Entry $entry, int $userId): ?array
    {
        $row = QueryBuilder::table('autosaves')->where('entry_id', $entry->getKey())->where('user_id', $userId)->first();
        if ($row === null || (string) $row['updated_at'] < (string) $entry->getRawAttribute('updated_at')) {
            return null;
        }

        return ['saved_at' => Caster::present(Caster::toDate((string) $row['updated_at'])), 'snapshot' => (array) json_decode((string) $row['snapshot'], true)];
    }

    /** Publish scheduled entries whose time has come. Returns how many. */
    public function publishDue(): int
    {
        $count = 0;
        foreach (Entry::query()->where('status', 'scheduled')->where('published_at', '<=', Model::freshTimestamp())->limit(500)->get() as $entry) {
            $entry->forceFill(['status' => 'published', 'version' => $entry->version + 1])->save();
            $this->published($entry);
            $count++;
        }

        return $count;
    }

    // ── reading ──────────────────────────────────────────────────────

    /**
     * A published entry of $type at $path (relative to the type's prefix, no
     * leading slash) in $language ('' = the main language; default: the
     * language of the page being answered).
     */
    public function findPublic(ContentType $type, string $path, ?string $language = null): ?Entry
    {
        $language ??= ContentLanguages::current();
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== ''));
        if ($segments === [] || count($segments) > self::MAX_DEPTH + 1) {
            return null;
        }
        foreach ($segments as $s) {
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/', $s) !== 1) {
                return null;
            }
        }
        if (!$type->isHierarchical()) {
            // found by slug; date parts only have to look like dates (the controller redirects to the current address)
            $slug = array_pop($segments);
            if ($segments !== [] && (!Permalinks::supports($type) || !Permalinks::looksDated($segments))) {
                return null;
            }

            return Entry::query()->ofType($type->name)->inLanguage($language)->published()->where('slug', $slug)->first();
        }
        $parent = null;
        $entry = null;
        foreach ($segments as $i => $slug) {
            $q = Entry::query()->ofType($type->name)->inLanguage($language)->where('slug', $slug);
            $parent === null ? $q->whereNull('parent_id') : $q->where('parent_id', $parent);
            // every parent on the way must be public too: a draft parent hides its children
            $entry = $q->published()->first();
            if ($entry === null) {
                return null;
            }
            $parent = $entry->getKey();
        }

        return $entry;
    }

    // ── internals ────────────────────────────────────────────────────

    private function write(ContentType $type, Entry $entry, array $input, Principal $p, ?int $expected, bool $new): Entry
    {
        $errors = [];
        $status = (string) ($input['status'] ?? $entry->status);
        if (!in_array($status, Entry::STATUSES, true)) {
            $errors['status'][] = Translator::get('Must be one of: {list}.', ['list' => implode(', ', Entry::STATUSES)]);
            $status = 'draft';
        }
        if (!in_array($status, Entry::UNPUBLISHED, true) && !$this->can($p, $type, 'publish', $new ? null : $entry)) {
            throw ContentException::forbidden("You can't publish " . strtolower($type->plural()) . '; save it as a draft or submit it for review.');
        }
        $draft = $status === 'draft';

        $title = array_key_exists('title', $input) ? trim((string) $input['title']) : (string) $entry->title;
        if (mb_strlen($title) > 255) {
            $errors['title'][] = Translator::get('Must be at most 255 characters.');
        } elseif (!$draft && $title === '') {
            $errors['title'][] = $status === 'pending' ? 'Add a title before submitting it for review.' : 'Add a title before publishing.';
        }

        $data = $this->data($type, $entry, $input, $draft, $new, $errors);
        $terms = $this->termInput($type, $input, $p, $errors);

        $excerpt = $entry->excerpt;
        if (array_key_exists('excerpt', $input)) {
            $excerpt = $input['excerpt'] === null ? null : trim((string) $input['excerpt']);
            if (!$type->hasExcerpt() && $excerpt !== null && $excerpt !== '') {
                $errors['excerpt'][] = Translator::get('This content type has no excerpt.');
            } elseif ($excerpt !== null && mb_strlen($excerpt) > 1000) {
                $errors['excerpt'][] = Translator::get('Must be at most 1000 characters.');
            }
        }

        $parentId = $entry->parent_id;
        if (array_key_exists('parent_id', $input) && $input['parent_id'] !== $entry->parent_id) {
            $parentId = $input['parent_id'] === null ? null : (int) $input['parent_id'];
            if ($parentId !== null && ($message = $this->parentProblem($type, $entry, $parentId)) !== null) {
                $errors['parent_id'][] = $message;
            }
        }

        $authorId = $entry->author_id;
        if (array_key_exists('author_id', $input) && $input['author_id'] !== $entry->author_id) {
            $authorId = $input['author_id'] === null ? null : (int) $input['author_id'];
            if (!$p->can($type->capability('edit_others'))) {
                $errors['author_id'][] = Translator::get('You can\'t change the author.');
            } elseif ($authorId !== null && !QueryBuilder::table('users')->where('id', $authorId)->exists()) {
                $errors['author_id'][] = Translator::get('No such user.');
            }
        }

        $template = array_key_exists('template', $input) ? ($input['template'] === null || $input['template'] === '' ? null : (string) $input['template']) : $entry->template;
        if ($template !== null && preg_match('#^[A-Za-z0-9][A-Za-z0-9/_-]{0,99}$#', $template) !== 1) {
            $errors['template'][] = Translator::get('Invalid template name.');
        }
        $menuOrder = array_key_exists('menu_order', $input) ? (int) $input['menu_order'] : (int) $entry->menu_order;

        [$status, $publishedAt] = $this->schedule($status, $entry, $input, $errors);

        $slug = $this->slug($type, $entry, $input, $title, $parentId, $new, $errors);

        // The type's checklist, when the entry is about to become public (not when it already is).
        if (in_array($status, ['published', 'scheduled'], true) && ($new || !in_array((string) $entry->status, ['published', 'scheduled'], true)) && $type->getChecklist() !== []) {
            $counts = array_map(static fn(array $c): int => count($c['ids']) + count($c['names']), $terms);
            $missing = $this->content->checks->blocking($type, ['title' => $title, 'excerpt' => $excerpt, 'data' => $data, 'terms' => $this->termCounts($type, $new ? null : $entry, $counts)]);
            foreach ($missing as $label) {
                $errors['checklist'][] = Translator::get('Before publishing: {item}.', ['item' => $label]);
            }
        }

        if ($expected !== null && !$new && $expected !== (int) $entry->version) {
            throw ContentException::conflict('Someone else saved this ' . strtolower($type->singular()) . ' since you opened it. Reload to see their changes.');
        }
        if ($errors !== []) {
            throw ContentException::invalid($errors);
        }

        $wasPublished = !$new && $entry->isPublished();
        $was = $new ? null : (string) $entry->status;
        $commit = fn() => $this->commit($entry, $type, $p, $status, $title, $slug, $data, $terms, $excerpt, $parentId, $authorId, $template, $menuOrder, $publishedAt, $new);
        if ($this->locks !== null && ($new || $slug !== (string) $entry->slug)) {
            // A new address: another request may be choosing the same one right now. Choose it
            // again under the type's lock, and commit before letting the next one choose.
            try {
                $this->locks->lock('entry-slug:' . $type->name, 30)->block(self::SLUG_LOCK_WAIT, function () use ($type, $entry, $input, $title, $parentId, $new, &$slug, $commit): void {
                    $ignored = [];
                    $slug = $this->slug($type, $entry, $input, $title, $parentId, $new, $ignored);
                    $commit();
                });
            } catch (LockTimeout) {
                throw ContentException::conflict('Many ' . strtolower($type->plural()) . ' are being saved at once. Try again in a moment.');
            }
        } else {
            $commit();
        }
        $this->events?->dispatch(new EntrySaved((int) $entry->getKey(), (string) $entry->type, (string) $entry->title, (string) $entry->status, $new, $p->userId));
        if (!$wasPublished && $entry->isPublished()) {
            $this->published($entry);
        }
        $this->reviewed($entry, $was, $p->userId);

        return $entry;
    }

    /**
     * Writes a checked save in one transaction: the version claim, the row, its terms, index and revision.
     *
     * @param array<string, mixed> $data
     * @param array<string, array{ids: list<int>, names: list<string>}> $terms
     */
    private function commit(Entry $entry, ContentType $type, Principal $p, string $status, string $title, string $slug, array $data, array $terms, ?string $excerpt, ?int $parentId, ?int $authorId, ?string $template, int $menuOrder, mixed $publishedAt, bool $new): void
    {
        DatabaseManager::getConnection()->transaction(function () use ($entry, $type, $p, $status, $title, $slug, $data, $terms, $excerpt, $parentId, $authorId, $template, $menuOrder, $publishedAt, $new): void {
            // Claim the next version: of two saves that loaded the same one, the second
            // to get here finds it taken and is refused, instead of silently replacing the first.
            $loaded = (int) $entry->getAttribute('version');
            if (!$new && QueryBuilder::table('entries')->where('id', $entry->getKey())->where('version', $loaded)->update(['version' => $loaded + 1]) !== 1) {
                throw ContentException::conflict('Someone else saved this ' . strtolower($type->singular()) . ' since you opened it. Reload to see their changes.');
            }
            $entry->forceFill([
                'status' => $status, 'title' => $title, 'slug' => $slug, 'data' => $data, 'excerpt' => $excerpt,
                'parent_id' => $parentId, 'author_id' => $authorId, 'template' => $template, 'menu_order' => $menuOrder,
                'published_at' => $publishedAt, 'version' => (int) $entry->version + 1,
            ]);
            $entry->save();
            foreach ($terms as $taxName => $checked) {
                $tax = $this->content->getTaxonomy($taxName);
                $this->terms->sync($entry, $tax, $this->terms->ensure($tax, $checked));
            }
            $this->reindex($entry, $type);
            $this->recordRevision($entry, $type, $p->userId);
            if ($p->userId !== null) {
                QueryBuilder::table('autosaves')->where('entry_id', $entry->getKey())->where('user_id', $p->userId)->delete();
            }
        });
    }

    /**
     * An entry as its type's checklist sees it: what is saved, with the unsaved values in
     * $input (title, excerpt, data, terms as taxonomy => list) laid over it. For the editor.
     *
     * @param array<string, mixed> $input
     * @return array{title: string, excerpt: ?string, data: array<string, mixed>, terms: array<string, int>}
     */
    public function checklistEntry(ContentType $type, ?Entry $entry, array $input): array
    {
        $saved = static fn(string $key): mixed => $entry?->getAttribute($key);
        $data = (array) ($saved('data') ?? []);
        if (is_array($input['data'] ?? null)) {
            $data = array_replace($data, $input['data']);
        }
        $given = [];
        foreach (is_array($input['terms'] ?? null) ? $input['terms'] : [] as $tax => $values) {
            $given[(string) $tax] = is_array($values) ? count($values) : 0;
        }

        return [
            'title' => array_key_exists('title', $input) ? trim((string) $input['title']) : (string) $saved('title'),
            'excerpt' => array_key_exists('excerpt', $input) ? ($input['excerpt'] === null ? null : (string) $input['excerpt']) : (($e = $saved('excerpt')) === null ? null : (string) $e),
            'data' => $data,
            'terms' => $this->termCounts($type, $entry, $given),
        ];
    }

    /**
     * How many terms of each of the type's taxonomies the entry has: $given where the save
     * sets them, what is saved otherwise.
     *
     * @param array<string, int> $given
     * @return array<string, int>
     */
    private function termCounts(ContentType $type, ?Entry $entry, array $given): array
    {
        $saved = $entry === null || $entry->getKey() === null ? [] : ($this->terms->idsFor([(int) $entry->getKey()])[(int) $entry->getKey()] ?? []);
        $out = [];
        foreach ($this->content->taxonomiesFor($type->name) as $tax) {
            $out[$tax->name] = $given[$tax->name] ?? count($saved[$tax->name] ?? []);
        }

        return $out;
    }

    /**
     * @param array<string, list<string>> $errors
     * @return array<string, array{ids: list<int>, names: list<string>}> taxonomy => checked values to sync
     */
    private function termInput(ContentType $type, array $input, Principal $p, array &$errors): array
    {
        if (!array_key_exists('terms', $input) || $input['terms'] === null) {
            return [];
        }
        if (!is_array($input['terms']) || ($input['terms'] !== [] && array_is_list($input['terms']))) {
            $errors['terms'][] = Translator::get('Must be an object: taxonomy => list of term ids.');
            return [];
        }
        $out = [];
        foreach ($input['terms'] as $taxName => $values) {
            $key = 'terms.' . $taxName;
            $tax = $this->content->getTaxonomy((string) $taxName);
            if ($tax === null || !$tax->appliesTo($type->name)) {
                $errors[$key][] = strtolower($type->plural()) . " don't use this taxonomy.";
            } elseif (!$p->can($tax->capability('assign'))) {
                $errors[$key][] = Translator::get('You can\'t assign {terms}.', ['terms' => mb_strtolower($tax->plural())]);
            } elseif (!is_array($values) || ($values !== [] && !array_is_list($values))) {
                $errors[$key][] = Translator::get('Must be a list.');
            } else {
                $out[$tax->name] = $this->terms->check($tax, $values, $p, $errors, $key);
            }
        }

        return $out;
    }

    /** @param array<string, list<string>> $errors */
    private function data(ContentType $type, Entry $entry, array $input, bool $draft, bool $new, array &$errors): array
    {
        $fields = $type->getFields();
        $known = array_flip(array_map(static fn(Field $f) => $f->name, $fields));
        $incoming = $input['data'] ?? [];
        if (!is_array($incoming) || ($incoming !== [] && array_is_list($incoming))) {
            $errors['data'][] = Translator::get('Must be an object of field values.');
            return (array) ($entry->data ?? []);
        }
        foreach (array_diff_key($incoming, $known) as $key => $_) {
            $errors['data.' . $key][] = Translator::get('Unknown field.');
        }
        $merged = array_intersect_key(array_replace((array) ($entry->data ?? []), $incoming), $known);
        if ($new) {
            foreach ($fields as $field) {
                if (!array_key_exists($field->name, $merged) && $field->getDefault() !== null) {
                    $merged[$field->name] = $field->getDefault();
                }
            }
        }
        // a field its conditions hide is never required (its value stays stored for when it shows again)
        $visible = Conditions::visibleFields($fields, $merged);
        $fields = array_map(static fn(Field $f) => ($visible[$f->name] ?? true) || !$f->isRequired() ? $f : (clone $f)->required(false), $fields);
        [$clean, $schemaErrors] = $this->content->fields->objectSchema($fields, $draft)->validate($merged);
        foreach ($schemaErrors as $key => $messages) {
            $errors[$key === '_' ? 'data' : 'data.' . $key] = $messages;
        }
        if ($schemaErrors !== []) {
            return $merged;
        }

        return $this->content->fields->finish($fields, (array) $clean, 'data.', $errors);
    }

    /**
     * @param array<string, list<string>> $errors
     * @return array{0: string, 1: ?string} status, published_at (UTC "Y-m-d H:i:s")
     */
    private function schedule(string $status, Entry $entry, array $input, array &$errors): array
    {
        $now = Clock::now(new DateTimeZone('UTC'));
        $at = $entry->published_at;
        if (array_key_exists('published_at', $input)) {
            try {
                $at = $input['published_at'] === null || $input['published_at'] === '' ? null : new DateTimeImmutable((string) $input['published_at'], new DateTimeZone('UTC'));
            } catch (\Exception) {
                $errors['published_at'][] = Translator::get('Not a date.');
                $at = $entry->published_at;
            }
        }
        if ($status === 'published' && $at === null) {
            $at = $now;
        } elseif ($status === 'published' && $at > $now) {
            $status = 'scheduled';
        } elseif ($status === 'scheduled' && ($at === null || $at <= $now)) {
            $errors['published_at'][] = Translator::get('Pick a date in the future to schedule.');
        } elseif ($status === 'private' && $at === null) {
            $at = $now;
        }

        return [$status, $at === null ? null : Caster::toDate($at)->format('Y-m-d H:i:s')];
    }

    /** @param array<string, list<string>> $errors */
    private function slug(ContentType $type, Entry $entry, array $input, string $title, ?int $parentId, bool $new, array &$errors): string
    {
        if (array_key_exists('slug', $input) && trim((string) $input['slug']) !== '') {
            $base = Str::slug(mb_substr((string) $input['slug'], 0, 180));
            if ($base === '') {
                $errors['slug'][] = Translator::get('Use letters or digits.');
                return (string) $entry->slug;
            }
            if (($owner = $this->reservedBy($type, $base, $parentId)) !== null) {
                $errors['slug'][] = Translator::get('The address /{base} belongs to {owner}. Pick another.', ['base' => $base, 'owner' => $owner]);
                return (string) $entry->slug;
            }
        } elseif ($new || $entry->slug === '' || ($entry->published_at === null && $title !== $entry->title)) {
            // Follow the title until the entry is first published.
            $base = Str::slug(mb_substr($title, 0, 180)) ?: Str::slug($type->singular()) ?: 'entry';
        } else {
            $base = (string) $entry->slug;
        }

        $slug = $this->uniqueSlug($type, $base, $parentId, $entry->getKey(), $entry->language());
        for ($i = 2; $this->reservedBy($type, $slug, $parentId) !== null; $i++) {      // a title like "Blog": take blog-2
            $slug = $this->uniqueSlug($type, "{$base}-{$i}", $parentId, $entry->getKey(), $entry->language());
        }

        return $slug;
    }

    /**
     * What already answers at /{slug} when an entry of $type would live there: only top-level entries of a type
     * served from the site root (pages) can collide. Returns a description, or null when the address is free.
     */
    public function reservedBy(ContentType $type, string $slug, ?int $parentId): ?string
    {
        if ($slug === 'feed' && $type->hasArchive() && $parentId === null) {
            return 'the ' . mb_strtolower($type->plural()) . ' feed';
        }
        if (preg_match('/^\d{4}$/', $slug) === 1 && Permalinks::supports($type)) {
            return 'the ' . mb_strtolower($type->plural()) . ' of that year';
        }
        if ($type->getPath() !== '' || $parentId !== null) {
            return null;
        }
        if (in_array($slug, self::RESERVED_PATHS, true)) {
            return 'the site itself';
        }
        if (ContentLanguages::has($slug)) {
            return 'the pages in that language';
        }
        foreach ($this->content->types() as $other) {
            if ($other->name !== $type->name && ($other->getPath() ?? '') !== '' && explode('/', trim((string) $other->getPath(), '/'))[0] === $slug) {
                return 'the ' . mb_strtolower($other->plural()) . ' archive';
            }
        }
        foreach ($this->content->taxonomies() as $taxonomy) {
            if (($taxonomy->getPath() ?? '') !== '' && explode('/', trim((string) $taxonomy->getPath(), '/'))[0] === $slug) {
                return 'the ' . mb_strtolower($taxonomy->plural()) . ' pages';
            }
        }
        // routes themes and plugins added (the base theme's /about, a plugin's /shop…)
        foreach (\Kestello\Cms\Cms::instance()?->routes->routes() ?? [] as $route) {
            $first = explode('/', trim($route->path, '/'))[0];
            if ($route->method === 'GET' && $first === $slug && !str_contains($first, '{')) {
                return 'another page of the site';
            }
        }

        return null;
    }

    /** $base, or $base-2, $base-3… unique among the type's entries in $language with the same parent (trash included). */
    public function uniqueSlug(ContentType $type, string $base, ?int $parentId, int|string|null $exceptId = null, string $language = ''): string
    {
        $base = trim(mb_substr($base, 0, 180), '-') ?: 'entry';
        $taken = Entry::query()->withTrashed()->ofType($type->name)->inLanguage($language)
            ->where(fn(QueryBuilder $q) => $q->where('slug', $base)->orWhereLike('slug', QueryBuilder::escapeLike($base) . '-%'))
            ->when($type->isHierarchical(), fn($q) => $parentId === null ? $q->whereNull('parent_id') : $q->where('parent_id', $parentId))
            ->when($exceptId !== null, fn($q) => $q->where('id', '!=', $exceptId))
            ->pluck('slug');
        if (!in_array($base, $taken, true)) {
            return $base;
        }
        for ($i = 2; ; $i++) {
            if (!in_array("{$base}-{$i}", $taken, true)) {
                return "{$base}-{$i}";
            }
        }
    }

    private function parentProblem(ContentType $type, Entry $entry, int $parentId): ?string
    {
        if (!$type->isHierarchical()) {
            return strtolower($type->plural()) . " don't have parents.";
        }
        if ($entry->getKey() !== null && $parentId === $entry->getKey()) {
            return 'An entry cannot be its own parent.';
        }
        $current = $parentId;
        for ($depth = 0; $current !== null && $depth < self::MAX_DEPTH; $depth++) {
            $row = QueryBuilder::table('entries')->select('id', 'type', 'parent_id', 'locale', 'deleted_at')->where('id', $current)->first();
            if ($row === null || ($depth === 0 && ($row['type'] !== $type->name || $row['deleted_at'] !== null))) {
                return 'Pick an existing ' . strtolower($type->singular()) . '.';
            }
            if ($depth === 0 && (string) $row['locale'] !== $entry->language()) {
                return 'Pick a ' . strtolower($type->singular()) . ' in the same language.';
            }
            if ($entry->getKey() !== null && (int) $row['id'] === $entry->getKey() && $depth > 0) {
                return 'That would put the entry inside itself.';
            }
            $current = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }

        return $depth >= self::MAX_DEPTH ? 'Pages can be nested at most ' . self::MAX_DEPTH . ' levels.' : null;
    }

    /** Rebuild the entry's rows in entry_index from its indexed() fields, and its media and entry links. */
    public function reindex(Entry $entry, ContentType $type): void
    {
        QueryBuilder::table('entry_index')->where('entry_id', $entry->getKey())->delete();
        $rows = [];
        foreach ($type->getFields() as $field) {
            $value = $entry->field($field->name);
            if (!$field->isIndexed() || $value === null || $value === '' || $value === []) {
                continue;
            }
            foreach ($this->content->fields->get($field->type)->index($value, $field) as $v) {
                $rows[] = [
                    'entry_id' => $entry->getKey(), 'field' => $field->name,
                    'value_string' => $v['string'] ?? null, 'value_number' => $v['number'] ?? null, 'value_date' => $v['date'] ?? null,
                ];
            }
        }
        if ($rows !== []) {
            QueryBuilder::table('entry_index')->insertMany($rows);
        }
        // Which media files the entry uses (the library warns before deleting them).
        \Kestello\Cms\Media\MediaLibrary::syncUsage((int) $entry->getKey(), $this->content->fields->mediaIn($type->getFields(), (array) $entry->data));
        // Which entries it links to (each of them can show what links to it).
        EntryRelations::sync((int) $entry->getKey(), $this->content->fields->relationsIn($type->getFields(), (array) $entry->data));
    }

    /** @return array<string, mixed> */
    public function snapshot(Entry $entry): array
    {
        $out = [];
        foreach (self::SNAPSHOT as $key) {
            $out[$key] = Caster::present($entry->{$key});
        }
        $out['terms'] = $this->terms->idsFor([(int) $entry->getKey()])[(int) $entry->getKey()] ?? [];
        ksort($out['terms']);

        return $out;
    }

    private function recordRevision(Entry $entry, ContentType $type, ?int $userId): void
    {
        if ($type->keepRevisions() === 0) {
            return;
        }
        $snapshot = $this->snapshot($entry);
        $last = Revision::query()->where('entry_id', $entry->getKey())->orderByDesc('id')->first();
        if ($last !== null && $last->snapshot == $snapshot) {
            return;
        }
        Revision::forceCreate(['entry_id' => $entry->getKey(), 'author_id' => $userId, 'snapshot' => $snapshot, 'created_at' => Model::freshTimestamp()]);
        $keep = Revision::query()->where('entry_id', $entry->getKey())->orderByDesc('id')->limit($type->keepRevisions())->pluck('id');
        if (count($keep) === $type->keepRevisions()) {
            Revision::query()->where('entry_id', $entry->getKey())->where('id', '<', min($keep))->delete();
        }
    }

    private function published(Entry $entry): void
    {
        $type = $this->content->get((string) $entry->type);
        $this->events?->dispatch(new EntryPublished((int) $entry->getKey(), (string) $entry->type, (string) $entry->title, $type === null ? null : $entry->path($type)));
    }

    /** Review steps: submitted (to pending), approved (from pending to live or scheduled). Sending back is Reviews::requestChanges. */
    private function reviewed(Entry $entry, ?string $was, ?int $userId): void
    {
        $now = (string) $entry->status;
        if ($now === 'pending' && $was !== 'pending') {
            $this->events?->dispatch(new EntrySubmitted((int) $entry->getKey(), (string) $entry->type, (string) $entry->title, $userId));
        } elseif ($was === 'pending' && in_array($now, ['published', 'scheduled', 'private'], true)) {
            $this->events?->dispatch(new EntryReviewed((int) $entry->getKey(), (string) $entry->type, (string) $entry->title, 'approved', $userId));
        }
    }

    private function typeOf(Entry $entry): ContentType
    {
        return $this->content->get((string) $entry->type) ?? throw ContentException::forbidden("The content type [{$entry->type}] is not available (is its plugin active?).");
    }
}
