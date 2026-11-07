<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Security;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\AccountSecurity;
use Kestello\Framework\Core\Response;

/** Everyone's signed-in sessions. meta.supported is false when sessions are kept in files. */
final class ListSecuritySessions extends SecurityEndpoint
{
    public function __construct(private readonly AccountSecurity $accounts) {}

    public function route(): ApiRoute
    {
        return ApiRoute::get('/security/sessions');
    }
    public function summary(): string
    {
        return 'List everyone\'s sessions';
    }

    public function response(): ?Schema
    {
        return Schema::object([
            'id' => Schema::string()->description('Hashed; use it to sign the session out'),
            'user' => self::person(), 'ip' => Schema::string(), 'user_agent' => Schema::string(),
            'created_at' => Schema::int(), 'last_active_at' => Schema::int(),
            'current' => Schema::bool()->description('The session making this request'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        return Response::json([
            'data' => self::overview()->sessions(self::currentSession($request)),
            'meta' => ['supported' => $this->accounts->tracksDevices()],
        ]);
    }
}
