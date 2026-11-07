<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Core\Response;

/** Remove the admin settings for a subsystem (back to the config file, environment or defaults). */
final class ResetPlatform extends PlatformEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/system/platform/{subsystem}')->where('subsystem', '[a-z][a-z0-9_-]{0,49}');
    }
    public function summary(): string
    {
        return 'Reset driver settings';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function body(): ?Schema
    {
        return null;
    }

    public function handle(ApiRequest $request): mixed
    {
        $subsystem = $this->subsystem($request);
        $this->platform()->reset($subsystem, $request->userId());

        return Response::json(['data' => $this->platform()->describe($subsystem)]);
    }
}
