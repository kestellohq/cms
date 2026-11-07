<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Themes;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Delete an inactive theme made or imported in the admin. Themes with code are removed by a developer. */
final class DeleteTheme extends ThemeEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/themes/{slug}')->where('slug', '[a-z0-9-]{1,64}');
    }
    public function summary(): string
    {
        return 'Delete a theme';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }

    public function handle(ApiRequest $request): mixed
    {
        $slug = (string) $request->param('slug');
        $this->attempt(fn() => $this->packages()->delete($slug));
        $this->cms()->audit->record('theme.deleted', ['theme', $slug], [], $request->userId());

        return $this->overview();
    }
}
