<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;

/**
 * Public: the token a form needs to be sent, fetched by the form block
 * when someone starts filling it in (pages are cached, so it can't be in
 * the page). It proves a browser loaded the form at least a moment ago.
 */
final class GetFormToken extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/forms/{id}/token')->where('id', '\d{1,18}');
    }
    public function isPublic(): bool
    {
        return true;
    }
    public function capability(): ?string
    {
        return null;
    }
    public function scopes(): array
    {
        return [];
    }
    public function rateLimit(): ?array
    {
        return [60, 60];
    }
    public function summary(): string
    {
        return 'Get a form token';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $form = self::forms()->publicDefinition($id) ?? throw ApiProblem::notFound('No such form.');
        if (!$form['open']) {
            throw ApiProblem::conflict($form['closed_message']);
        }

        return Response::json(['data' => ['token' => self::forms()->token($id)]])->withHeader('Cache-Control', 'no-store');
    }
}
