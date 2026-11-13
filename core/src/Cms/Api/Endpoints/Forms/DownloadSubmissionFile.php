<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Core\Response;

/**
 * A file someone uploaded with a form. Always a download (never shown
 * inline), so a hostile file can't run in the admin's browser.
 */
final class DownloadSubmissionFile extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/form-submissions/{id}/files/{field}/{index}')->where('id', '\d{1,18}')->where('field', '[a-z][a-z0-9_]{0,63}')->where('index', '\d{1,2}');
    }
    public function summary(): string
    {
        return 'Download an uploaded file';
    }

    public function produces(): ?string
    {
        return 'application/octet-stream';
    }

    public function handle(ApiRequest $request): mixed
    {
        $s = self::attempt(fn() => self::forms()->submission($request->intParam('id')));
        $file = $s['data'][(string) $request->param('field')][(int) $request->param('index')] ?? null;
        if (!is_array($file) || !isset($file['key'])) {
            throw ApiProblem::notFound('No such file.');
        }
        $path = self::attempt(fn() => self::forms()->filePath((string) $file['key']));
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw ApiProblem::notFound('The file is gone.');
        }
        $name = preg_replace('/[^\w.\- ]+/u', '_', (string) ($file['name'] ?? 'file')) ?: 'file';

        return Response::stream($handle, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode((string) ($file['name'] ?? 'file')),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
