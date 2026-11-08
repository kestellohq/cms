<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Themes;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** The new-theme wizard: a data-only child theme, optionally carrying the site's changes, optionally switched to. */
final class CreateTheme extends ThemeEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/themes');
    }
    public function summary(): string
    {
        return 'Create a theme';
    }

    /** It can switch the live theme (activate), and with it the code that runs: like ActivateTheme, a recent sign-in. */
    public function requiresRecentAuth(): bool
    {
        return true;
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'name' => Schema::string()->max(60),
            'slug' => Schema::string()->max(64)->optional(),
            'description' => Schema::string()->max(300)->optional(),
            'author' => Schema::string()->max(100)->optional(),
            'parent' => Schema::string()->max(64),
            'variation' => Schema::string()->max(41)->nullable()->optional(),
            'from_site' => Schema::bool()->optional(),
            'activate' => Schema::bool()->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $theme = $this->attempt(fn() => $this->packages()->create($request->body));
        if (!empty($request->body['activate'])) {
            $this->attempt(fn() => $this->cms()->extensions()->activateTheme($theme->slug()));
            $this->purgePages();
        }
        $this->cms()->audit->record('theme.created', ['theme', $theme->slug()], ['parent' => $theme->manifest->parent, 'from_site' => !empty($request->body['from_site'])], $request->userId());

        return ['created' => $theme->slug()] + $this->overview();
    }
}
