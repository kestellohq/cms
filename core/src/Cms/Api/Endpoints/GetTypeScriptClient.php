<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\OpenApi;
use Kestello\Cms\Api\TypeScriptClient;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Response;

/**
 * The TypeScript client, covering the endpoints the caller may use (like
 * /openapi.json). php bin/cms api:client writes the complete one.
 */
final class GetTypeScriptClient extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/openapi.ts');
    }
    public function isPublic(): bool
    {
        return true;
    }
    public function produces(): ?string
    {
        return 'text/plain';
    }

    public function summary(): string
    {
        return 'TypeScript client for the endpoints you may use';
    }
    public function tags(): array
    {
        return ['openapi'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = Cms::instance() ?? throw new \LogicException('CMS not booted.');
        $document = (new OpenApi($cms->api))->build($request->principal, (string) $cms->config->get('app.url', ''));

        return new Response(TypeScriptClient::generate($document, gmdate('Y-m-d')), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="kestello.ts"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
