<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Package\Registry\RegistryCatalog;
use Kestello\Framework\Testing\TestCase;

/** Searching the registry and showing its text in the admin (Plugins → Add new). */
final class RegistryCatalogTest extends TestCase
{
    /** @return array{slug: string, name: string, summary: string, categories: list<string>} */
    private static function entry(string $slug, string $name, string $summary, array $categories = []): array
    {
        return ['slug' => $slug, 'name' => $name, 'summary' => $summary, 'categories' => $categories];
    }

    public function testWordsAreLowerCaseUniqueAndLimited(): void
    {
        $this->assertSame(['site', 'search'], RegistryCatalog::words("  Site,  SEARCH site\t"));
        $this->assertSame([], RegistryCatalog::words('   '));
        $this->assertCount(10, RegistryCatalog::words(implode(' ', range(1, 30))));
        $this->assertSame([str_repeat('a', RegistryCatalog::MAX_QUERY)], RegistryCatalog::words(str_repeat('a', 500)));
    }

    public function testEveryWordMustBeFound(): void
    {
        $meili = self::entry('meilisearch', 'Meilisearch', 'Site search on a Meilisearch server.', ['integrations']);
        $this->assertSame(0, RegistryCatalog::score($meili, 'Kestello', []), 'no search: everything, unranked');
        $this->assertNotNull(RegistryCatalog::score($meili, 'Kestello', ['site', 'search']));
        $this->assertNull(RegistryCatalog::score($meili, 'Kestello', ['search', 'forms']), 'a strong first match does not hide a missing word');
        $this->assertNotNull(RegistryCatalog::score($meili, 'Kestello', ['kestello']), 'the organisation counts');
        $this->assertNotNull(RegistryCatalog::score($meili, 'Kestello', ['integrations']), 'and the categories');
    }

    public function testNamesRankAboveSummaries(): void
    {
        $words = ['search'];
        $exact = RegistryCatalog::score(self::entry('search', 'Search', 'Find things.'), 'A', $words);
        $prefix = RegistryCatalog::score(self::entry('search-plus', 'Search Plus', 'Find more.'), 'A', $words);
        $inside = RegistryCatalog::score(self::entry('meilisearch', 'Meilisearch', 'Fast.'), 'A', $words);
        $summary = RegistryCatalog::score(self::entry('typesense', 'Typesense', 'Site search on a server.'), 'A', $words);
        $other = RegistryCatalog::score(self::entry('forms', 'Forms', 'Contact forms.'), 'Search Co', $words);
        $this->assertTrue($exact > $prefix && $prefix > $inside && $inside > $summary && $summary > $other, json_encode([$exact, $prefix, $inside, $summary, $other]));
    }

    public function testRegistryTextNeverLoadsImagesAndMarksLinks(): void
    {
        $html = RegistryCatalog::html("# Events\n\n![tracker](https://evil.example/pixel.gif) Lists **events**.\n\n[Docs](https://acme.example/docs) [bad](javascript:alert(1))\n\n<script>alert(1)</script>");
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<strong>events</strong>', $html);
        $this->assertMatchesRegularExpression('/<a href="https:\/\/acme.example\/docs" rel="[^"]*nofollow[^"]*">Docs<\/a>/', $html);
        $this->assertSame('', RegistryCatalog::html("  \n "));
    }
}
