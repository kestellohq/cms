<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/**
 * Back to the theme's version, or (for one only the site has) delete it.
 * The last version stays in the history either way.
 */
final class ResetTemplate extends TemplateEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/design/templates/{kind}/{key}')->where('kind', self::KIND)->where('key', self::KEY);
    }
    public function summary(): string
    {
        return 'Reset or delete a template, part or pattern';
    }

    public function handle(ApiRequest $request): mixed
    {
        $kind = (string) $request->param('kind');
        $key = (string) $request->param('key');
        $this->document($request, $kind, $key);      // 404 when there is nothing
        $hasFile = $this->templates()->file($kind, $key) !== null;
        if (!$hasFile && $this->usedBy($kind, $key) !== []) {
            throw ApiProblem::conflict('Templates still use this ' . $kind . '. Take it out of them first.');
        }
        $this->attempt(fn() => $this->templates()->reset($kind, $key, $request->userId()));
        $this->changed($hasFile ? 'reset' : 'deleted', $kind, $key, $request->userId());

        return $hasFile ? $this->document($request, $kind, $key) : ['kind' => $kind, 'key' => $key, 'deleted' => true];
    }
}
