<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Search;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\Search\SearchController;

/** Public site search (what /search shows), for headless sites and apps. Limited per client. */
final class SearchSite extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/search');
    }
    public function isPublic(): bool
    {
        return true;
    }
    public function tags(): array
    {
        return ['search'];
    }
    public function summary(): string
    {
        return 'Search published content';
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'q' => Schema::string()->min(1)->max(200)->description('Words to find'),
            'type' => Schema::string()->max(32)->pattern('[a-z][a-z0-9_-]*')->optional()->description('Only this content type'),
            'page' => Schema::int()->min(1)->max(500)->default(1),
            'per_page' => Schema::int()->min(1)->max(20)->default(10),
            'locale' => Schema::string()->max(12)->pattern('[A-Za-z0-9_-]*')->default('any')->description('One language: main (or empty) for the main one, or a code such as es. Default: any, every language'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = Cms::instance() ?? throw ApiProblem::server();
        if (!SearchController::allowed($cms, $request->http, 'api')) {
            throw ApiProblem::tooManyRequests(60);
        }
        $type = $request->query('type');
        $locale = (string) $request->query('locale', 'any');

        return $cms->search->search((string) $request->query('q'), [
            'types' => is_string($type) ? [$type] : [], 'page' => (int) $request->query('page', 1), 'per_page' => (int) $request->query('per_page', 10),
            'language' => $locale === 'any' ? null : ($locale === 'main' ? '' : $locale),
        ]);
    }
}
