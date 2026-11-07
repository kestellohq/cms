<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Themes;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Download a Google font once and serve it from this site. */
final class AddFont extends ThemeEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/themes/fonts');
    }
    public function capability(): ?string
    {
        return 'theme.design';
    }
    public function summary(): string
    {
        return 'Add a Google font, hosted on this site';
    }
    public function rateLimit(): ?array
    {
        return [10, 60];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'family' => Schema::string()->max(61),
            'weights' => Schema::list(Schema::int()->min(100)->max(900))->max(9),
            'italic' => Schema::bool()->optional(),
            'category' => Schema::enum(array_keys(\Kestello\Cms\Design\ThemeFonts::CATEGORIES))->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $font = $this->attempt(fn() => $this->fonts()->addGoogle((string) $request->body['family'], (array) $request->body['weights'], (bool) ($request->body['italic'] ?? false), (string) ($request->body['category'] ?? 'sans-serif')));
        $this->cms()->audit->record('theme.font_added', ['font', $font['family']], ['files' => count($font['files']), 'bytes' => $font['bytes']], $request->userId());

        return ['fonts' => $this->fontList()];
    }
}
