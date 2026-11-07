<?php

declare(strict_types=1);

namespace Kestello\Contracts\Search;

/**
 * A search engine (the "search" platform subsystem). Core ships the
 * database engine; plugins add Meilisearch, Typesense and others.
 *
 * Documents live in collections ("content" holds public entries). An
 * engine stores what it is given and answers queries; deciding what is
 * public is the caller's job, so an engine never sees drafts or private
 * fields. Every document carries `publishedAt`; queries pass `now` and an
 * engine must not return documents published after it (scheduled posts).
 *
 * @api
 */
interface Engine
{
    /** Short machine name ("database", "meilisearch"). */
    public function name(): string;

    /**
     * Add or replace documents (matched by collection and id).
     *
     * @param list<SearchDocument> $documents
     * @throws SearchException
     */
    public function index(array $documents): void;

    /**
     * @param list<string> $ids
     * @throws SearchException
     */
    public function remove(string $collection, array $ids): void;

    /** Remove every document of a collection (before a full reindex). @throws SearchException */
    public function flush(string $collection): void;

    /** @throws SearchException */
    public function search(SearchQuery $query): SearchResults;

    /** Throw when the engine can't be reached (Site Health, "Test connection"). @throws SearchException */
    public function ping(): void;
}
