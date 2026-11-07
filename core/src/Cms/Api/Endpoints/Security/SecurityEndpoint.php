<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Security;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\SecurityOverview;
use Kestello\Cms\Cms;

/**
 * System → Security: everyone's sign-in security. Needs security.manage
 * (administrators), and the "security" scope for tokens.
 */
abstract class SecurityEndpoint extends Endpoint
{
    public function capability(): ?string
    {
        return 'security.manage';
    }
    public function scopes(): array
    {
        return ['security'];
    }
    public function tags(): array
    {
        return ['security'];
    }

    protected static function cms(): Cms
    {
        return Cms::instance() ?? throw new \LogicException('Security endpoints need a booted CMS.');
    }

    protected static function overview(): SecurityOverview
    {
        return self::cms()->container->make(SecurityOverview::class);
    }

    /** The asking session's hashed id, to mark it in lists. */
    protected static function currentSession(ApiRequest $request): ?string
    {
        return $request->principal->method === 'session' ? $request->principal->credentialId : null;
    }

    protected static function person(): Schema
    {
        return Schema::object(['id' => Schema::int(), 'username' => Schema::string()->nullable()]);
    }
}
