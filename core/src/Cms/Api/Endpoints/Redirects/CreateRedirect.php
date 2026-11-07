<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Redirects;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Redirects\Redirects;

/** Add a redirect rule. */
final class CreateRedirect extends RedirectEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/redirects');
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Add a redirect';
    }

    public function body(): ?Schema
    {
        return self::schema(false);
    }

    public static function schema(bool $partial): Schema
    {
        $fields = [
            'source' => Schema::string()->max(500)->description('/old-path, a prefix such as /old-blog, or a regular expression'),
            'target' => Schema::string()->max(1000)->optional()->description('/new-path or https://…; leave out for 410 Gone'),
            'match' => Schema::enum(Redirects::MATCHES)->optional(),
            'status' => Schema::int()->optional()->description('301, 302, 307, 308 or 410'),
            'note' => Schema::string()->max(255)->optional(),
        ];
        if ($partial) {
            $fields['source'] = $fields['source']->optional();
        }

        return Schema::object($fields);
    }

    public function handle(ApiRequest $request): mixed
    {
        $saved = self::attempt(fn() => self::redirects()->save($request->body));
        self::audit($request, 'redirect.created', $saved['id'], ['source' => $saved['source'], 'target' => $saved['target']]);

        return $saved;
    }
}
