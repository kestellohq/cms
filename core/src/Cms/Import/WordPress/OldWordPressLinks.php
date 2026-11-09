<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Closure;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Media\Media;
use Kestello\Framework\Core\Middleware\MiddlewareInterface;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Old WordPress addresses that are a query on the home page, which a site
 * with WordPress's default "plain" permalinks has for every post: /?p=123,
 * /?page_id=45, /?attachment_id=67. Redirect rules match paths, and "/"
 * is this site's home page, so these are followed here: to the imported
 * post, page or file, when one imported WordPress site had that id.
 * Every other request passes through without a query.
 */
final class OldWordPressLinks implements MiddlewareInterface
{
    /** WordPress answers ?p= for any post, page or file; the others name one kind. */
    private const array KEYS = ['p' => ['entry', 'attachment'], 'page_id' => ['entry'], 'attachment_id' => ['attachment']];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->path !== '/' || !in_array($request->method, ['GET', 'HEAD'], true)) {
            return $next($request);
        }
        $target = null;
        try {
            $target = self::target((string) (parse_url($request->uri, PHP_URL_QUERY) ?? ''));
        } catch (\Throwable $e) {
            error_log('[import] old address: ' . $e->getMessage());     // never break the home page
        }

        return $target === null ? $next($request) : Response::redirect($target, 301);
    }

    /** Where an old "?p=123" query leads here, or null when it is not one, or not imported. */
    public static function target(string $query): ?string
    {
        parse_str($query, $params);
        foreach (self::KEYS as $key => $kinds) {
            $id = $params[$key] ?? null;
            if (!is_string($id) || preg_match('/^[1-9]\d{0,18}$/', $id) !== 1) {
                continue;
            }
            foreach ($kinds as $kind) {
                $targets = QueryBuilder::table('import_sources')->where('kind', $kind)->where('source_key', $id)->limit(2)->pluck('target_id');
                if (count($targets) === 1) {     // none: not imported; two: ids from two imported sites, no guessing
                    $target = self::address($kind, (int) $targets[0]);
                    if ($target !== null) {
                        return $target;
                    }
                }
            }
        }

        return null;
    }

    /** The public address of what an id became, or null while it is not public (a draft, scheduled, under a hidden page). */
    private static function address(string $kind, int $id): ?string
    {
        if ($kind === 'attachment') {
            $media = Media::query()->find($id);

            return $media instanceof Media ? $media->url() : null;
        }
        $entry = Entry::query()->find($id);

        return $entry instanceof Entry && $entry->isPublished() && $entry->publicAncestors() !== null ? $entry->path() : null;
    }
}
