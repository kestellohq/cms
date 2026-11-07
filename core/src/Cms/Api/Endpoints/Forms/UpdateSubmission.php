<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Mark a submission new, read or spam. */
final class UpdateSubmission extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/form-submissions/{id}')->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Mark a submission';
    }
    public function body(): ?Schema
    {
        return Schema::object(['status' => Schema::enum(['new', 'read', 'spam'])]);
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::attempt(fn() => self::forms()->setStatus($request->intParam('id'), (string) $request->body['status']));
    }
}
