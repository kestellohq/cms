<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Design\DesignCanvas;

/** Entries a template can be previewed with in the builder. */
final class ListPreviewEntries extends TemplateEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/design/templates/{kind}/{key}/preview-entries')->where('kind', self::KIND)->where('key', self::KEY);
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function summary(): string
    {
        return 'Entries to preview a template with';
    }
    public function collection(): bool
    {
        return false;
    }

    public function handle(ApiRequest $request): mixed
    {
        return ['entries' => $this->cms()->container->make(DesignCanvas::class)->sampleEntries((string) $request->param('kind'), (string) $request->param('key'), $request->principal)];
    }
}
