<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Themes;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

final class RemoveFont extends ThemeEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/themes/fonts/{slug}')->where('slug', 'gf-[a-z0-9-]{1,60}');
    }
    public function capability(): ?string
    {
        return 'theme.design';
    }
    public function summary(): string
    {
        return 'Remove a hosted font';
    }

    public function handle(ApiRequest $request): mixed
    {
        $slug = (string) $request->param('slug');
        if (!$this->fonts()->remove($slug)) {
            throw ApiProblem::notFound('No such font.');
        }
        $this->purgePages();
        $this->cms()->audit->record('theme.font_removed', ['font', $slug], [], $request->userId());

        return ['fonts' => $this->fontList()];
    }
}
