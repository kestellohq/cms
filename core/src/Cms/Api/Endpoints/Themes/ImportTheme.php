<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Themes;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/**
 * Install a theme zip (multipart field "file"; "replace=1" updates an
 * installed theme of the same name). Data-only themes only.
 */
final class ImportTheme extends ThemeEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/themes/import');
    }
    public function summary(): string
    {
        return 'Import a theme zip';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [10, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        $file = $request->http->files['file'] ?? null;
        // the request's files come from PHP's upload handling ($_FILES), never from the body
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_file($file['tmp_name'])) {
            throw ApiProblem::validation(['file' => ['Choose a theme zip to upload.']]);
        }
        $replace = in_array((string) ($request->http->post['replace'] ?? ''), ['1', 'true'], true);
        $result = $this->attempt(fn() => $this->packages()->import((string) $file['tmp_name'], $replace));
        if ($result['replaced'] && $this->cms()->extensions()->activeTheme()?->slug() === $result['theme']->slug()) {
            $this->purgePages();
        }
        $this->cms()->audit->record($result['replaced'] ? 'theme.updated' : 'theme.imported', ['theme', $result['theme']->slug()], ['version' => $result['theme']->version(), 'fonts' => $result['fonts']], $request->userId());

        return ['imported' => $result['theme']->slug(), 'replaced' => $result['replaced']] + $this->overview();
    }
}
