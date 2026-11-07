<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Save a draft of a template, part or pattern, or publish it ("publish": true). */
final class SaveTemplate extends TemplateEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/design/templates/{kind}/{key}')->where('kind', self::KIND)->where('key', self::KEY);
    }
    public function summary(): string
    {
        return 'Save or publish a template, part or pattern';
    }

    /** @param array<string, Schema> $extra */
    public static function schema(array $extra = []): Schema
    {
        return Schema::object($extra + [
            'title' => Schema::string()->max(120)->optional(),
            'description' => Schema::string()->max(300)->optional(),
            'blocks' => Schema::any(1048576, 40),
            'layout' => Schema::enum(['site', 'bare'])->optional(),
            'for' => Schema::list(Schema::string()->max(64))->nullable()->optional(),
            'area' => Schema::string()->max(20)->optional(),
            'category' => Schema::string()->max(40)->optional(),
            'synced' => Schema::bool()->optional(),
            'publish' => Schema::bool()->optional(),
        ]);
    }

    public function body(): ?Schema
    {
        return self::schema();
    }

    public function handle(ApiRequest $request): mixed
    {
        $kind = (string) $request->param('kind');
        $key = (string) $request->param('key');
        $publish = (bool) ($request->body['publish'] ?? false);
        $this->attempt(fn() => $this->templates()->save($kind, $key, $request->body, $request->userId(), $publish));
        if ($publish) {
            $this->changed('published', $kind, $key, $request->userId());
        }

        return $this->document($request, $kind, $key);
    }
}
