<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Themes;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;

/** Download a theme as a zip; for the running theme, ?site=1 (default) includes the site's changes. */
final class ExportTheme extends ThemeEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/themes/{slug}/export')->where('slug', '[a-z0-9-]{1,64}');
    }
    public function scopes(): array
    {
        return ['admin'];
    }
    public function summary(): string
    {
        return 'Export a theme as a zip';
    }
    public function rateLimit(): ?array
    {
        return [20, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        $slug = (string) $request->param('slug');
        $withSite = ($request->http->query['site'] ?? '1') !== '0';
        $path = $this->attempt(fn() => $this->packages()->export($slug, $withSite));
        $contents = (string) file_get_contents($path);
        @unlink($path);
        $this->cms()->audit->record('theme.exported', ['theme', $slug], ['site' => $withSite], $request->userId());

        return new Response($contents, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'attachment; filename="' . $slug . '-' . gmdate('Y-m-d') . '.zip"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
