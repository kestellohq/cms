<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Imports;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Start an import with the chosen options. */
final class StartImport extends ImportEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/imports/{id}/start')->where('id', self::ID);
    }

    public function summary(): string
    {
        return 'Start an import with the chosen options';
    }

    public function requiresRecentAuth(): bool
    {
        return true;
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'authors' => Schema::dictionary(500)->optional(),
            'default_author' => Schema::int()->nullable()->optional(),
            'media' => Schema::bool()->optional(),
            'redirects' => Schema::bool()->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $this->id($request);
        // a folder on the server (uploads_dir) is for the CLI only
        $options = [
            'authors' => (array) $request->input('authors', []),
            'default_author' => $request->input('default_author'),
            'media' => (bool) $request->input('media', true),
            'redirects' => (bool) $request->input('redirects', true),
        ];

        return $this->attempt(fn() => $this->imports()->start($id, $options, $request->principal));
    }
}
