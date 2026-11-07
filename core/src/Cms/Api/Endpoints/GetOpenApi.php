<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\OpenApi;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Response;

/** The OpenAPI document (only the endpoints the caller may use). */
final class GetOpenApi extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/openapi.json');
    }
    public function isPublic(): bool
    {
        return true;
    }
    public function summary(): string
    {
        return 'OpenAPI document';
    }
    public function tags(): array
    {
        return ['openapi'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = Cms::instance() ?? throw new \LogicException('CMS not booted.');
        $document = (new OpenApi($cms->api))->build($request->principal, (string) $cms->config->get('app.url', ''));

        return Response::json($document);
    }
}
