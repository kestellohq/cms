<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/** Delete a form with all its submissions and their files. */
final class DeleteForm extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/forms/{id}')->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Delete a form';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $form = self::attempt(fn() => self::forms()->find($id));
        self::forms()->delete($id);
        self::purgePages($id);
        self::audit($request, 'form.deleted', ['form', $id], ['title' => $form['title']]);

        return null;
    }
}
