<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Contracts\Auth\RedirectAuthenticator;
use Kestello\Framework\Core\Response;

/** External sign-in methods, for custom login screens. The browser navigates to login_url. */
final class ListAuthProviders extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/auth/providers');
    }
    public function isPublic(): bool
    {
        return true;
    }
    public function summary(): string
    {
        return 'List external sign-in providers';
    }
    public function tags(): array
    {
        return ['auth'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $data = [];
        foreach (Cms::instance()?->auth->authenticators() ?? [] as $id => $a) {
            if ($a instanceof RedirectAuthenticator) {
                $data[] = ['id' => $id, 'label' => $a->label(), 'icon' => $a->icon(), 'login_url' => '/auth/' . $id . '/redirect'];
            }
        }

        return Response::json(['data' => $data]);
    }
}
