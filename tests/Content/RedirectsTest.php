<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Redirects\Redirects;
use Kestello\Cms\Redirects\RedirectsModule;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Tests\Auth\AuthTestCase;

/** Redirects, automatic redirects when entries move, and the not-found log. */
final class RedirectsTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        foreach ([new ContentModule(), new RedirectsModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->createUser(['username' => 'cora', 'email' => 'cora@example.test', 'role' => 'contributor']);
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Model::resetRegistry();
    }

    private function visit(string $uri, string $referrer = ''): Response
    {
        $this->nextRequest();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->cms->routes->dispatch(new Request('GET', $uri, (string) parse_url($uri, PHP_URL_PATH), $referrer === '' ? [] : ['Referer' => $referrer], $query, [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
    }

    private function page(string $title, ?int $parent = null): array
    {
        $r = $this->api('POST', '/content/page/entries', ['title' => $title, 'status' => 'published'] + ($parent ? ['parent_id' => $parent] : []));
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    private function goesTo(string $uri): array
    {
        $r = $this->visit($uri);

        return [$r->statusCode(), $r->headers()['Location'] ?? null];
    }

    public function testMovedEntriesKeepTheirOldAddresses(): void
    {
        $about = $this->page('About');
        $this->page('Team', $about['id']);
        $this->api('PATCH', "/content/page/entries/{$about['id']}", ['slug' => 'company']);

        $this->assertSame([301, '/company'], $this->goesTo('/about'));
        $this->assertSame([301, '/company/team'], $this->goesTo('/about/team'), 'children follow by prefix');
        $this->assertSame([301, '/company/team?ref=mail'], $this->goesTo('/about/team?ref=mail'), 'the query string is kept');
        $this->assertSame([404, null], $this->goesTo('/aboutx'), 'a prefix ends at a slash');

        $this->api('PATCH', "/content/page/entries/{$about['id']}", ['slug' => 'us']);
        $this->assertSame([301, '/us'], $this->goesTo('/about'), 'no chains: the first address goes straight to the newest');
        $this->api('PATCH', "/content/page/entries/{$about['id']}", ['slug' => 'about']);
        $this->assertSame(200, $this->visit('/about')->statusCode(), 'moving back: the page answers again');
        $this->assertSame([301, '/about'], $this->goesTo('/company'));
        $this->assertSame([], QueryBuilder::table('redirects')->where('source', '/about')->pluck('id'), 'and no rule loops back');

        $draft = $this->json($this->api('POST', '/content/page/entries', ['title' => 'Hidden']))['data'];
        $this->api('PATCH', "/content/page/entries/{$draft['id']}", ['slug' => 'secret']);
        $this->assertSame(0, QueryBuilder::table('redirects')->where('source', '/hidden')->count(), 'drafts had no public address to keep');
    }

    public function testRulesOnlyApplyToAddressesThatFoundNothing(): void
    {
        $this->page('Pricing');
        $add = fn(array $body) => $this->api('POST', '/redirects', $body);

        $this->assertSame(200, $add(['source' => '/pricing', 'target' => '/elsewhere'])->statusCode());
        $this->assertSame(200, $this->visit('/pricing')->statusCode(), 'a live page is never hidden');
        $this->assertSame(200, $add(['source' => '/old-shop', 'target' => '/shop', 'match' => 'prefix', 'status' => 302])->statusCode());
        $this->assertSame([302, '/shop/hats/red'], $this->goesTo('/old-shop/hats/red'));
        $this->assertSame(200, $add(['source' => '^/news/(\d+)/([a-z-]+)$', 'target' => '/blog/$2', 'match' => 'regex'])->statusCode());
        $this->assertSame([301, '/blog/hello-world'], $this->goesTo('/news/2019/hello-world'));
        $this->assertSame([404, null], $this->goesTo('/news/x/hello'), 'whole-path matches only');
        $this->assertSame(200, $add(['source' => '/gone', 'status' => 410])->statusCode());
        $this->assertSame(410, $this->visit('/gone')->statusCode());
        $this->assertSame(200, $add(['source' => '/x', 'target' => 'https://example.org/new'])->statusCode());
        $this->assertSame([301, 'https://example.org/new'], $this->goesTo('/x'));
        $this->assertSame(200, $add(['source' => '(a+)+b', 'target' => '/never', 'match' => 'regex'])->statusCode());
        $this->assertSame(404, $this->visit('/' . str_repeat('a', 400))->statusCode(), 'a runaway pattern gives up instead of hanging');

        $this->assertSame(200, $add(['source' => '/old-blog', 'target' => '/', 'match' => 'prefix'])->statusCode());
        $this->assertSame([301, '/evil.test'], $this->goesTo('/old-blog//evil.test'), 'a computed target never points at another host');

        $errors = fn(array $body) => array_keys($this->json($add($body))['errors'] ?? []);
        $this->assertSame(200, $add(['source' => '/loop-a', 'target' => '/loop-b'])->statusCode());
        $this->assertSame(['target'], $errors(['source' => '/loop-b', 'target' => '/loop-a']), 'no circles between rules');
        $this->assertSame(['source'], $errors(['source' => '/', 'target' => '/a']), 'not the home page');
        $this->assertSame(['source'], $errors(['source' => '//evil.test', 'target' => '/a']));
        $this->assertSame(['target'], $errors(['source' => '/a', 'target' => 'javascript:alert(1)']));
        $this->assertSame(['target'], $errors(['source' => '/a', 'target' => '//evil.test']));
        $this->assertSame(['target'], $errors(['source' => '/a', 'target' => '/a/']), 'no loops');
        $this->assertSame(['It leads back to the same address. Enter another one.'], $this->json($add(['source' => '/a', 'target' => '/a']))['errors']['target'], 'in the words of the form');
        $this->assertSame(
            ['That address already redirects back here, so visitors would go round in a circle.'],
            $this->json($add(['source' => '/loop-b', 'target' => '/loop-a']))['errors']['target'],
        );
        \Kestello\Support\Translator::add('core', 'sr', ['It leads back to the same address. Enter another one.' => 'Vodi nazad na istu adresu. Unesite drugu.']);
        try {
            \Kestello\Support\Translator::withLocale('sr', fn() => $this->cms->container->make(\Kestello\Cms\Redirects\Redirects::class)->save(['source' => '/sr', 'target' => '/sr']));
            $this->fail('a loop is refused');
        } catch (\Kestello\Cms\Content\ContentException $e) {
            $this->assertSame(['Vodi nazad na istu adresu. Unesite drugu.'], $e->errors['target'], 'in the admin\'s language');
        }
        $this->assertSame(['source'], $errors(['source' => '([', 'target' => '/a', 'match' => 'regex']));
        $this->assertSame(['source'], $errors(['source' => '/x/', 'target' => '/y']), 'one rule per address');
        $this->assertSame(['status'], $errors(['source' => '/b', 'target' => '/c', 'status' => 200]));

        $this->api('DELETE', '/auth/session');
        $this->api('POST', '/auth/session', ['login' => 'cora', 'password' => self::PASSWORD]);
        $this->assertSame(403, $this->api('GET', '/redirects')->statusCode(), 'contributors can\'t manage redirects');
    }

    public function testTheNotFoundLogAndCsv(): void
    {
        $this->visit('/missing-page', 'https://news.example/story?token=secret');
        $this->visit('/missing-page/');
        $this->visit('/wp-login.php');
        $this->visit('/.env');
        $this->visit('/api/v1/nothing');
        $log = $this->json($this->api('GET', '/redirects/not-found'))['data'];
        $this->assertMatchesRegularExpression('/^\\d{4}-\\d\\d-\\d\\dT\\d\\d:\\d\\d:\\d\\dZ$/', (string) $log[0]['last_seen_at'], 'UTC, with its zone');
        $this->assertSame(
            [['/missing-page', 2, 'https://news.example/story']],
            array_map(static fn($r) => [$r['path'], $r['hits'], $r['referrer']], $log),
            'one row per address, referrer without its query, bot probes and API calls left out',
        );

        $csv = "source,target,status,match,note\n/a,/b,301,exact,\n/c,/d,302,,from old site\n/broken,javascript:x,301,exact,\n=cmd,/e,301,exact,";
        $result = $this->json($this->api('POST', '/redirects/import', ['csv' => $csv]))['data'];
        $this->assertSame([2, 0], [$result['created'], $result['updated']]);
        $this->assertSame([4, 5], array_column($result['errors'], 'line'));
        $this->assertSame(1, $this->json($this->api('POST', '/redirects/import', ['csv' => '/a,/z']))['data']['updated'], 'existing sources are updated');
        $this->assertSame([301, '/z'], $this->goesTo('/a'));

        $this->cms->container->make(Redirects::class)->save(['source' => '/f', 'target' => '/g', 'note' => '=HYPERLINK("http://evil")']);
        $export = $this->api('GET', '/redirects/export');
        $this->assertStringContainsString('text/csv', $export->headers()['Content-Type']);
        $this->assertStringContainsString("'=HYPERLINK", $export->content(), 'formula-looking cells are made inert');

        $redirects = $this->cms->container->make(Redirects::class);
        for ($i = 0; $i < 5; $i++) {
            $redirects->logNotFound("/old-{$i}");
        }
        $redirects->pruneLog(3);
        $this->assertSame(3, QueryBuilder::table('not_found_log')->count());
    }
}
