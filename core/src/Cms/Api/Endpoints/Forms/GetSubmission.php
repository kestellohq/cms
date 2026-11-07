<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** One submission. Opening a new one marks it read. */
final class GetSubmission extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/form-submissions/{id}')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Get a submission';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $s = self::attempt(fn() => self::forms()->submission($id));

        return $s['status'] === 'new' ? self::forms()->setStatus($id, 'read') : $s;
    }
}
