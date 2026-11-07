<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Platform\PlatformException;
use Kestello\Framework\Core\Response;

/** Switch or reconfigure a driver. It is tested first and only saved when the test passes. */
final class UpdatePlatform extends PlatformEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/system/platform/{subsystem}')->where('subsystem', '[a-z][a-z0-9_-]{0,49}');
    }
    public function summary(): string
    {
        return 'Change driver settings';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }

    public function handle(ApiRequest $request): mixed
    {
        $subsystem = $this->subsystem($request);
        $driver = (string) $request->input('driver');
        $options = (array) $request->input('options', []);
        $test = $this->platform()->test($subsystem, $driver, $options);
        if (!$test['ok']) {
            throw ApiProblem::validation($test['errors'] !== [] ? $test['errors'] : ['driver' => [$test['message']]], 'Not saved: ' . $test['message']);
        }
        try {
            $this->platform()->update($subsystem, $driver, $options, $request->userId(), skipTest: true);
        } catch (PlatformException $e) {
            throw $e->errors !== [] ? ApiProblem::validation($e->errors, $e->getMessage()) : ApiProblem::conflict($e->getMessage());
        }

        return Response::json(['data' => $this->platform()->describe($subsystem)]);
    }
}
