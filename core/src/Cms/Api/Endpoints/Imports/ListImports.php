<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Imports;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Health\CoreHealthChecks;

/** The latest imports. */
final class ListImports extends ImportEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/imports');
    }

    public function summary(): string
    {
        return 'The latest imports';
    }

    public function collection(): bool
    {
        return false;
    }

    public function handle(ApiRequest $request): mixed
    {
        return ['imports' => $this->imports()->latest(), 'max_upload' => self::maxUpload()];
    }

    /** The largest file PHP accepts in one upload, in bytes; null when unlimited. */
    private static function maxUpload(): ?int
    {
        $limits = array_filter([CoreHealthChecks::bytes((string) ini_get('upload_max_filesize')), CoreHealthChecks::bytes((string) ini_get('post_max_size'))], static fn(int $b) => $b > 0);

        return $limits === [] ? null : min($limits);
    }
}
