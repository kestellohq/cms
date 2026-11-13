<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Resources\TermResource;
use Kestello\Cms\Api\Schema;

/** A term's translation into another of the site's languages: the same name, ready to translate. */
final class CreateTermTranslation extends TermEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/taxonomies/{taxonomy}/terms/{id}/translations')->where('taxonomy', self::TYPE)->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Translate a term';
    }
    public function body(): ?Schema
    {
        return Schema::object([
            'locale' => Schema::string()->max(12)->description("The language to translate into: one of the site's other languages (GET /content/languages), or '' for the main one"),
        ]);
    }

    public function resource(): ?string
    {
        return TermResource::class;
    }

    /** 409: the term already has a translation in that language. */
    public function problems(): array
    {
        return [...parent::problems(), 409];
    }

    public function handle(ApiRequest $request): mixed
    {
        $tax = self::taxonomy($request, manage: true);
        $term = self::term($request, $tax);
        $copy = self::attempt(fn() => self::terms()->translate($tax, $term, (string) $request->input('locale'), $request->principal));

        return new TermResource(self::row($copy, $tax, 0));
    }
}
