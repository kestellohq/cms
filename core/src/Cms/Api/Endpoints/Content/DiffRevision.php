<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Revision;
use Kestello\Cms\Content\RevisionDiff;
use Kestello\Framework\Database\Orm\Caster;

/**
 * What a revision changed: against the revision before it (the default),
 * the entry as it is now (against=current), or another revision of the
 * same entry (against=<id>). Fields come back in editor order, each with
 * its lines: equal, insert, delete, or change with word-level parts.
 */
final class DiffRevision extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/{type}/entries/{id}/revisions/{revision}/diff')->where('type', self::TYPE)->where('id', '\d{1,18}')->where('revision', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Compare a revision';
    }
    public function query(): ?Schema
    {
        return Schema::object(['against' => Schema::string()->pattern('(previous|current|[0-9]{1,18})')->default('previous')]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type, withTrashed: true);
        $find = static fn(int $id): ?Revision => Revision::query()->where('entry_id', $entry->getKey())->with('author')->find($id);
        $revision = $find((int) $request->param('revision')) ?? throw ApiProblem::notFound('No such revision.');
        $against = (string) $request->query('against', 'previous');

        if ($against === 'current') {
            [$before, $after, $other] = [(array) $revision->snapshot, self::entries()->snapshot($entry), null];
        } elseif ($against === 'previous') {
            $other = Revision::query()->where('entry_id', $entry->getKey())->where('id', '<', $revision->getKey())->with('author')->orderByDesc('id')->first();
            [$before, $after] = [$other === null ? [] : (array) $other->snapshot, (array) $revision->snapshot];
        } else {
            $other = $find((int) $against) ?? throw ApiProblem::notFound('No such revision to compare with.');
            // Always older on the left.
            [$before, $after] = $other->getKey() < $revision->getKey() ? [(array) $other->snapshot, (array) $revision->snapshot] : [(array) $revision->snapshot, (array) $other->snapshot];
        }

        $describe = static fn(?Revision $r): ?array => $r === null ? null : [
            'id' => $r->getKey(), 'created_at' => Caster::present($r->created_at),
            'author' => $r->author === null ? null : ['id' => $r->author->getKey(), 'username' => (string) $r->author->username],
        ];

        return [
            'revision' => $describe($revision),
            'against' => $against === 'current' ? ['current' => true] : $describe($other),
            'changes' => self::cms()->container->make(RevisionDiff::class)->between($type, $before, $after),
        ];
    }
}
