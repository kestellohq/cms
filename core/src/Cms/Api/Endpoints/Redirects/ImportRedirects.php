<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Redirects;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Add or update rules from CSV (source,target,status,match,note). */
final class ImportRedirects extends RedirectEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/redirects/import');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function response(): ?Schema
    {
        return Schema::object([
            'created' => Schema::int(),
            'updated' => Schema::int(),
            'errors' => Schema::list(Schema::object(['line' => Schema::int()->description('The CSV line, counting the header as 1'), 'message' => Schema::string()])),
        ]);
    }

    public function summary(): string
    {
        return 'Import redirects from CSV';
    }

    public function body(): ?Schema
    {
        return Schema::object(['csv' => Schema::string()->max(2_000_000)->description('One rule per line: source,target,status,match,note. A header line is optional.')]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $result = self::redirects()->import((string) $request->body['csv']);
        self::audit($request, 'redirect.imported', null, ['created' => $result['created'], 'updated' => $result['updated'], 'errors' => count($result['errors'])]);

        return ['created' => $result['created'], 'updated' => $result['updated'],
            'errors' => array_map(static fn($line, $message) => ['line' => $line, 'message' => $message], array_keys($result['errors']), $result['errors'])];
    }
}
