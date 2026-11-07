<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Design\ThemeStyles;

/** Publish an earlier version of the global styles again (its values are checked against today's theme). */
final class RestoreStyles extends DesignEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/design/styles/revisions/{id}/restore')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Restore a version of the global styles';
    }

    public function handle(ApiRequest $request): mixed
    {
        $slug = $this->themeSlug();
        $rev = $this->documents()->revision($request->intParam('id'));
        if ($rev === null || $rev['theme'] !== $slug || $rev['kind'] !== 'styles' || $rev['key'] !== ThemeStyles::KEY) {
            throw ApiProblem::notFound('There is no such version of this theme\'s styles.');
        }
        if ($rev['data'] === null) {
            $this->documents()->reset($slug, 'styles', ThemeStyles::KEY, $request->userId());
        } else {
            // keep what still fits the theme; custom CSS comes back only if it still passes the check
            $data = $rev['data'];
            $data['values'] = array_intersect_key((array) ($data['values'] ?? []), $this->styles()->definition()['tokens']);
            [$choice, $errors] = $this->styles()->normalize($data, true);
            if (isset($errors['custom_css'])) {
                $choice['custom_css'] = '';
            }
            unset($errors['custom_css']);
            if (isset($errors['variation'])) {
                $choice['variation'] = null;
            }
            $this->documents()->save($slug, 'styles', ThemeStyles::KEY, $choice, $request->userId(), note: 'Restored an earlier version');
        }
        $this->styles()->forget();
        $this->purgePages();
        $this->cms()->audit->record('theme.styles.restored', ['theme', $slug], ['revision_id' => $request->intParam('id')], $request->userId());

        return $this->overview($request);
    }
}
