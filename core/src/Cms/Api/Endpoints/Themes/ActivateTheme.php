<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Themes;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

final class ActivateTheme extends ThemeEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/themes/{slug}/activate')->where('slug', '[a-z0-9-]{1,64}');
    }
    public function summary(): string
    {
        return 'Switch the site to this theme';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }

    public function handle(ApiRequest $request): mixed
    {
        $slug = (string) $request->param('slug');
        if (!isset($this->cms()->extensions()->themes()[$slug])) {
            throw \Kestello\Cms\Api\ApiProblem::notFound('No such theme.');
        }
        $previous = $this->cms()->extensions()->activeTheme()?->slug();
        $this->attempt(fn() => $this->cms()->extensions()->activateTheme($slug));
        $this->purgePages();
        $this->cms()->audit->record('theme.activated', ['theme', $slug], ['previous' => $previous], $request->userId());

        return $this->overview();
    }
}
