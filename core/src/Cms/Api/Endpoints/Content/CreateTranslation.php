<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/**
 * A draft of an entry in another of the site's languages, linked to it as
 * its translation: the same fields and terms, ready to translate.
 */
final class CreateTranslation extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/content/{type}/entries/{id}/translations')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\EntryResource::class;
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Start a translation of an entry';
    }
    public function body(): ?Schema
    {
        return Schema::object([
            'locale' => Schema::string()->max(12)->description("The language to translate into: one of the site's other languages (GET /content/languages), or '' for the main one"),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $source = self::entry($request, $type);
        $copy = self::attempt(fn() => self::entries()->translate($source, (string) $request->input('locale'), $request->principal));

        return self::present($request, $type, $copy);
    }
}
