<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Closure;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Resources\EntryResource;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entries;
use Kestello\Cms\Content\Entry;

/**
 * Shared plumbing: every content endpoint needs content.access; the
 * per-type capabilities (content.<type>.*) are checked by Entries.
 */
abstract class ContentEndpoint extends Endpoint
{
    public const string TYPE = '[a-z][a-z0-9-]{0,31}';

    public function capability(): ?string
    {
        return 'content.access';
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function tags(): array
    {
        return ['content'];
    }

    protected static function cms(): Cms
    {
        return Cms::instance() ?? throw new \LogicException('Content endpoints need a booted CMS.');
    }

    protected static function entries(): Entries
    {
        return self::cms()->container->make(Entries::class);
    }

    protected static function type(ApiRequest $request): ContentType
    {
        return self::cms()->content->get((string) $request->param('type')) ?? throw ApiProblem::notFound('No such content type.');
    }

    protected static function entry(ApiRequest $request, ContentType $type, bool $withTrashed = false): Entry
    {
        $entry = Entry::query()->ofType($type->name)->withTrashed($withTrashed)->with('author', 'parent')->find($request->intParam('id'));
        if ($entry === null || !self::entries()->can($request->principal, $type, 'view', $entry)) {
            throw ApiProblem::notFound('No such ' . strtolower($type->singular()) . '.');
        }

        return $entry;
    }

    protected static function present(ApiRequest $request, ContentType $type, Entry $entry, bool $full = true): EntryResource
    {
        $entry->loadMissing('author', 'parent');

        return new EntryResource([
            'model' => $entry,
            'type' => $type,
            'can' => self::entries()->abilities($request->principal, $type, $entry),
            'assignee' => \Kestello\Cms\Content\Reviews::assignees([(int) $entry->getKey()])[(int) $entry->getKey()] ?? null,
            'full' => $full,
            'terms' => (new \Kestello\Cms\Content\Terms())->summaries([(int) $entry->getKey()])[(int) $entry->getKey()] ?? [],
            'autosave' => $full && $request->userId() !== null ? self::entries()->autosaveFor($entry, $request->userId()) : null,
        ]);
    }

    /** Run a write and turn ContentException into API problems. */
    protected static function attempt(Closure $write): mixed
    {
        try {
            return $write();
        } catch (ContentException $e) {
            throw match ($e->kind) {
                'invalid' => ApiProblem::validation($e->errors),
                'conflict' => ApiProblem::conflict($e->getMessage()),
                'not_found' => ApiProblem::notFound($e->getMessage()),
                default => ApiProblem::forbidden($e->getMessage()),
            };
        }
    }

    /** The writable entry fields (content is validated against the type by Entries). */
    protected static function entryBody(): \Kestello\Cms\Api\Schema
    {
        $S = \Kestello\Cms\Api\Schema::class;

        return $S::object([
            'title' => $S::string()->max(255)->optional(),
            'slug' => $S::string()->max(191)->optional(),
            'status' => $S::enum(Entry::STATUSES)->optional(),
            'data' => $S::any()->optional()->description('Field values: an object keyed by field name (see GET /content/types)'),
            'terms' => $S::any(16384, 3)->optional()->description('Terms per taxonomy: {"category": [3, 7], "tag": [12, "new tag"]}. Taxonomies left out are unchanged.'),
            'excerpt' => $S::string()->max(1000)->raw()->nullable()->optional(),
            'parent_id' => $S::int()->min(1)->nullable()->optional(),
            'author_id' => $S::int()->min(1)->nullable()->optional(),
            'template' => $S::string()->max(100)->nullable()->optional(),
            'menu_order' => $S::int()->min(-100000)->max(100000)->optional(),
            'published_at' => $S::string()->format('date-time')->nullable()->optional(),
        ]);
    }
}
