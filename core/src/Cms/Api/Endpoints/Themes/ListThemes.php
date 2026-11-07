<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Themes;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

final class ListThemes extends ThemeEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/themes');
    }
    public function scopes(): array
    {
        return ['admin'];
    }
    public function summary(): string
    {
        return 'Installed themes, style variations and hosted fonts';
    }
    public function collection(): bool
    {
        return false;
    }

    public function handle(ApiRequest $request): mixed
    {
        return $this->overview();
    }
}
