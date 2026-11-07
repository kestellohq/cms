<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Publish an earlier version of a template, part or pattern again (checked against today's blocks). */
final class RestoreTemplate extends TemplateEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/design/templates/revisions/{id}/restore')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Restore a version of a template, part or pattern';
    }

    public function handle(ApiRequest $request): mixed
    {
        $t = $this->templates();
        $rev = $t->revision($request->intParam('id'));
        if ($rev === null || $rev['theme'] !== $t->themeSlug() || !in_array($rev['kind'], ['template', 'part', 'pattern'], true)) {
            throw ApiProblem::notFound('There is no such version.');
        }
        [$kind, $key] = [$rev['kind'], $rev['key']];
        if ($rev['data'] === null) {
            $this->attempt(fn() => $t->reset($kind, $key, $request->userId()));
        } else {
            // blocks that no longer exist are dropped rather than refusing the whole version
            [$clean] = $t->normalize($kind, $key, $rev['data'], strict: false);
            $exists = $t->get($kind, $key, drafts: true) !== null;
            $this->attempt(fn() => $t->save($kind, $key, $clean, $request->userId(), publish: true, create: !$exists));
        }
        $this->changed('restored', $kind, $key, $request->userId(), ['revision_id' => $request->intParam('id')]);

        return $this->document($request, $kind, $key);
    }
}
