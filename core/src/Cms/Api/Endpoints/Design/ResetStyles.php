<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Design\ThemeStyles;

/** Go back to the theme's own styles. Kept as a revision, so it can be restored. */
final class ResetStyles extends DesignEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/design/styles');
    }
    public function summary(): string
    {
        return 'Reset global styles to the theme\'s';
    }

    public function handle(ApiRequest $request): mixed
    {
        $slug = $this->themeSlug();
        $this->documents()->reset($slug, 'styles', ThemeStyles::KEY, $request->userId());
        $this->purgePages();
        $this->cms()->audit->record('theme.styles.reset', ['theme', $slug], [], $request->userId());

        return $this->overview($request);
    }
}
