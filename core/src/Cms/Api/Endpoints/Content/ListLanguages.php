<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\I18n\ContentLanguages;
use Kestello\Cms\I18n\SiteLanguage;
use Kestello\Framework\Database\QueryBuilder;

/**
 * The languages the site's content is written in, each with its name and
 * how many entries are in it, and the languages that can be added.
 */
final class ListLanguages extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/languages');
    }
    public function summary(): string
    {
        return "List the languages the site's content is written in";
    }

    /** One object, though the name starts with List. */
    public function collection(): bool
    {
        return false;
    }

    public function response(): ?Schema
    {
        $language = Schema::object(['code' => Schema::string(), 'name' => Schema::string()]);

        return Schema::object([
            'main' => Schema::string()->description('The main language\'s own code, such as en'),
            'languages' => Schema::list(Schema::object([
                'code' => Schema::string()->description("'' for the main language"),
                'name' => Schema::string(),
                'main' => Schema::bool(),
                'prefix' => Schema::string()->description("What its addresses start with: '' or /es"),
                'entries' => Schema::int()->description('Entries in it, trash left out'),
                'paths' => Schema::map(Schema::string())->description('Its own words for the types\' and taxonomies\' address prefixes, by prefix; empty for the main language'),
            ])),
            'choices' => Schema::list($language)->description('Languages that can be added, each named in its own words'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $counts = QueryBuilder::table('entries')->whereNull('deleted_at')->groupBy('locale')->select('locale')->selectRaw('COUNT(*) AS n')->get();
        $count = static fn(string $code): int => (int) (array_values(array_filter($counts, static fn(array $r) => (string) $r['locale'] === $code))[0]['n'] ?? 0);
        $main = SiteLanguage::site();
        $languages = [['code' => '', 'name' => SiteLanguage::name($main), 'main' => true, 'prefix' => '', 'entries' => $count(''), 'paths' => (object) []]];
        foreach (ContentLanguages::others() as $code) {
            $languages[] = ['code' => $code, 'name' => SiteLanguage::name($code), 'main' => false, 'prefix' => ContentLanguages::prefix($code), 'entries' => $count($code),
                'paths' => (object) (\Kestello\Cms\I18n\TranslatedPaths::all()[$code] ?? [])];      // its own prefixes for types and taxonomies
        }
        $choices = [];
        foreach (ContentLanguages::COMMON as $code) {
            if ($code !== ContentLanguages::code($main)) {
                $choices[] = ['code' => $code, 'name' => SiteLanguage::name($code)];
            }
        }

        return ['main' => ContentLanguages::code($main), 'languages' => $languages, 'choices' => $choices];
    }
}
