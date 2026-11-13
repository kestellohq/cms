<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Delete a submission and its files. */
final class DeleteSubmission extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/form-submissions/{id}')->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Delete a submission';
    }

    public function status(): int
    {
        return 204;
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $s = self::attempt(fn() => self::forms()->submission($id));
        self::forms()->deleteSubmission($id);
        self::audit($request, 'form.submission_deleted', ['form', $s['form_id']], ['submission' => $id]);

        return null;
    }
}
