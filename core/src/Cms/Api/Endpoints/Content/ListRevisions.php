<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Revision;
use Kestello\Framework\Database\Orm\Caster;

/** Revisions of an entry, newest first, with what changed against the previous one. */
final class ListRevisions extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/{type}/entries/{id}/revisions')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'List revisions of an entry';
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type, withTrashed: true);
        $revisions = Revision::query()->where('entry_id', $entry->getKey())->with('author')->orderByDesc('id')->limit(200)->get()->all();
        $out = [];
        foreach ($revisions as $i => $rev) {
            $previous = $revisions[$i + 1] ?? null;
            $snap = (array) $rev->snapshot;
            $changed = [];
            if ($previous !== null) {
                $old = (array) $previous->snapshot;
                foreach ($snap as $key => $value) {
                    if ($key === 'data') {
                        foreach (array_unique([...array_keys((array) $value), ...array_keys((array) ($old['data'] ?? []))]) as $f) {
                            if ((((array) $value)[$f] ?? null) != (((array) ($old['data'] ?? []))[$f] ?? null)) {
                                $changed[] = 'data.' . $f;
                            }
                        }
                    } elseif (($old[$key] ?? null) != $value) {
                        $changed[] = $key;
                    }
                }
            }
            $out[] = [
                'id' => $rev->getKey(),
                'created_at' => Caster::present($rev->created_at),
                'author' => $rev->author === null ? null : ['id' => $rev->author->getKey(), 'username' => (string) $rev->author->username],
                'title' => (string) ($snap['title'] ?? ''),
                'status' => (string) ($snap['status'] ?? ''),
                'changed' => $previous === null ? null : $changed,
                'snapshot' => $snap,
                'current' => $i === 0,
            ];
        }

        return $out;
    }
}
