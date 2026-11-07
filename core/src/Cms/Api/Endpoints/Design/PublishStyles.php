<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Design\ThemeStyles;

/**
 * Publish global styles: a style variation, changed token values and
 * (with theme.custom_css) custom CSS. Every page picks them up at once;
 * the previous version stays as a revision.
 */
final class PublishStyles extends DesignEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/design/styles');
    }
    public function summary(): string
    {
        return 'Publish global styles';
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'variation' => Schema::string()->max(41)->nullable()->optional(),
            'values' => Schema::any(),
            'custom_css' => Schema::string()->max(ThemeStyles::MAX_CSS + 1000)->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $canCss = $request->principal->can('theme.custom_css');
        if (!$canCss && array_key_exists('custom_css', $request->body) && trim((string) $request->body['custom_css']) !== $this->styles()->published()['custom_css']) {
            throw ApiProblem::forbidden('Writing custom CSS needs the "Write custom CSS" permission.');
        }
        [$choice, $errors] = $this->styles()->normalize($request->body, $canCss);
        if ($errors !== []) {
            throw ApiProblem::validation($errors);
        }
        $slug = $this->themeSlug();
        $revision = $this->documents()->save($slug, 'styles', ThemeStyles::KEY, $choice, $request->userId());
        $this->styles()->forget();
        $this->purgePages();
        $this->cms()->audit->record('theme.styles.published', ['theme', $slug], ['revision' => $revision, 'variation' => $choice['variation'], 'changed' => array_keys($choice['values']), 'custom_css' => $choice['custom_css'] !== ''], $request->userId());

        return $this->overview($request);
    }
}
