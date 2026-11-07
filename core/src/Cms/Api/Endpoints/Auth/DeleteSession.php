<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\Auth;
use Kestello\Cms\Cms;
use Kestello\Cms\Events\UserLoggedOut;

/** Sign out: the session ends on the server, not just in the browser. */
final class DeleteSession extends Endpoint
{
    use SessionOnly;

    public function route(): ApiRoute
    {
        return ApiRoute::delete('/auth/session');
    }
    public function capability(): ?string
    {
        return 'read';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function summary(): string
    {
        return 'Sign out';
    }
    public function tags(): array
    {
        return ['auth'];
    }

    public function handle(ApiRequest $request): mixed
    {
        Auth::getInstance()?->logout();
        Cms::instance()?->events->dispatch(new UserLoggedOut($request->userId()));

        return null;
    }
}
