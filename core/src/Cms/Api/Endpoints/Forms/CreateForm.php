<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Resources\FormResource;
use Kestello\Cms\Api\Schema;

/** Create a form. */
final class CreateForm extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/forms');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Create a form';
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
        $form = self::attempt(fn() => self::forms()->save($request->body, null, $request->userId()));
        self::audit($request, 'form.created', ['form', $form['id']], ['title' => $form['title']]);

        return new FormResource($form);
    }
}
