<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Resources\FormResource;
use Kestello\Cms\Api\Schema;

/** Change a form. Pages showing it are refreshed. */
final class UpdateForm extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/forms/{id}')->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Change a form';
    }
    public function body(): ?Schema
    {
        return SaveFormBody::schema();
    }

    public function resource(): ?string
    {
        return FormResource::class;
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $form = self::attempt(fn() => self::forms()->save($request->body, $id));
        self::purgePages($id);
        self::audit($request, 'form.updated', ['form', $id], ['title' => $form['title'], 'status' => $form['status']]);

        return new FormResource($form);
    }
}
