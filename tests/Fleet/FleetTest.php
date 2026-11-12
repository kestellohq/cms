<?php

declare(strict_types=1);

namespace Kestello\Tests\Fleet;

use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Cms\Fleet\FleetReport;
use Kestello\Cms\Fleet\FleetSites;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Support\AppKey;
use Kestello\Tests\Auth\AuthTestCase;

/** The fleet: a site reporting itself to a "fleet" token, and Site → Fleet watching other sites. */
final class FleetTest extends AuthTestCase
{
    private const string TOKEN = 'kst_pat_0123456789abcdef0123456789abcdef0123456789abcdef';

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser();
        $this->createUser(['username' => 'bob', 'email' => 'bob@example.test', 'role' => 'user']);
        $this->transport = new FakeTransport();
        $this->cms->container->instance(FleetSites::class, new FleetSites(new HttpClient($this->transport, static fn(): array => ['93.184.216.34']), AppKey::secretStore(), static fn(): int => 1_800_000_000));
    }

    private function signIn(string $login = 'ana'): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    private function withToken(string $token, string $path): Response
    {
        $this->nextRequest();
        $_SESSION = [];

        return $this->cms->routes->dispatch(new Request('GET', '/api/v1' . $path, '/api/v1' . $path, ['Authorization' => 'Bearer ' . $token], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.9']));
    }

    /** @return array<string, mixed> */
    private function report(string $version = '0.1.0'): array
    {
        return [
            'format' => 1, 'generated_at' => 1_800_000_000,
            'site' => ['name' => 'Bakery', 'url' => 'https://bakery.example'],
            'kestello' => ['version' => $version, 'php' => '8.5.0', 'update' => ['version' => '0.2.0', 'prerelease' => false], 'checked_at' => 1_799_990_000, 'error' => null],
            'packages' => ['installed' => 2, 'updates' => [['type' => 'plugin', 'slug' => 'events', 'name' => 'Events', 'version' => '1.0.0', 'to' => '1.1.0']], 'licenses' => [['type' => 'plugin', 'slug' => 'shop', 'name' => 'Shop', 'problem' => 'refused']]],
            'health' => ['status' => 'critical', 'counts' => ['good' => 20, 'recommended' => 1, 'critical' => 1], 'problems' => [['label' => 'Backups are not scheduled', 'status' => 'critical']]],
            'backups' => ['count' => 3, 'last_at' => 1_799_900_000],
        ];
    }

    public function testASiteReportsItselfToAFleetTokenAndNothingMore(): void
    {
        $tokens = new PersonalAccessTokens();
        $admin = (int) $this->db->table('users')->where('username', 'ana')->value('id');
        $fleet = $tokens->create($admin, 'Agency fleet', ['fleet'])['token'];

        $r = $this->withToken($fleet, '/fleet/report');
        $this->assertSame(200, $r->statusCode(), $r->content());
        $data = $this->json($r)['data'];
        $this->assertSame(1, $data['format']);
        $this->assertSame($this->cms->version(), $data['kestello']['version']);
        $this->assertArrayHasKey('packages', $data, 'null when extensions are not booted, as in this test');
        $kept = FleetReport::normalize($data);
        foreach (['generated_at', 'site', 'kestello', 'health', 'backups'] as $part) {
            $this->assertSame($data[$part], $kept[$part], "what a site sends is what a console keeps: {$part}");
        }

        $this->assertSame(403, $this->withToken($fleet, '/security')->statusCode(), 'the fleet scope reads the report only');
        $this->assertSame(403, $this->withToken($fleet, '/fleet/sites')->statusCode());

        // a fleet token of someone who doesn't manage the site gets nothing
        $bob = (int) $this->db->table('users')->where('username', 'bob')->value('id');
        $this->assertSame(403, $this->withToken($tokens->create($bob, 'mine', ['fleet'])['token'], '/fleet/report')->statusCode());
    }

    public function testAReportFromAnotherSiteIsKeptOnlyAsFarAsItHasTheRightShape(): void
    {
        $hostile = $this->report();
        $hostile['site']['name'] = str_repeat('x', 5000);
        $hostile['health']['status'] = '<script>';
        $hostile['packages']['licenses'][] = ['slug' => 'x', 'problem' => 'whatever'];
        $hostile['packages']['updates'] = array_fill(0, 500, $hostile['packages']['updates'][0]);
        $hostile['extra'] = 'dropped';

        $kept = FleetReport::normalize($hostile);
        $this->assertSame(120, mb_strlen($kept['site']['name']));
        $this->assertSame('good', $kept['health']['status']);
        $this->assertCount(1, $kept['packages']['licenses']);
        $this->assertCount(FleetReport::MAX_ITEMS, $kept['packages']['updates']);
        $this->assertArrayNotHasKey('extra', $kept);

        foreach ([null, 'text', ['format' => 2, 'kestello' => []], ['format' => 1]] as $bad) {
            try {
                FleetReport::normalize($bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (\UnexpectedValueException $e) {
                $this->assertSame('The site did not answer with a fleet report.', $e->getMessage());
            }
        }
    }

    public function testTheConsoleAddsChecksAndRemovesSites(): void
    {
        $this->cms->config->set('app.fleet.enabled', true);
        $this->transport->on('GET https://bakery.example/api/v1/fleet/report', ['status' => 200, 'json' => ['data' => $this->report()]]);
        $this->signIn();

        $r = $this->api('POST', '/fleet/sites', ['name' => 'Bakery', 'url' => 'https://Bakery.example/', 'token' => self::TOKEN]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Fleet\AddFleetSite::class, $r);
        $site = $this->json($r)['data'];
        $this->assertSame(['https://bakery.example', 'cdef', null, '0.2.0'], [$site['url'], $site['token_last4'], $site['error'], $site['report']['kestello']['update']['version']]);
        $this->assertStringNotContainsString(self::TOKEN, $r->content(), 'the token is never shown again');
        $this->assertStringNotContainsString(self::TOKEN, (string) $this->db->table('fleet_sites')->value('token'), 'stored sealed');
        $this->assertSame('Bearer ' . self::TOKEN, $this->transport->sent[0]->headers['Authorization']);
        $this->assertContains('fleet.site_added', $this->auditEvents());

        // the token is revoked there: the error is kept, and so is the last good report
        $this->transport->on('GET https://bakery.example/api/v1/fleet/report', ['status' => 401, 'json' => []]);
        $checked = $this->json($this->api('POST', "/fleet/sites/{$site['id']}/check"))['data'];
        $this->assertStringContainsString('refused the token', (string) $checked['error']);
        $this->assertSame('0.1.0', $checked['report']['kestello']['version']);

        $list = $this->api('GET', '/fleet/sites');
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Fleet\ListFleetSites::class, $list);
        $this->assertCount(1, $this->json($list)['data']['sites']);

        $this->assertSame(422, $this->api('POST', '/fleet/sites', ['name' => 'Again', 'url' => 'https://bakery.example', 'token' => self::TOKEN])->statusCode(), 'one entry per site');
        foreach (['http://plain.example', 'https://user:pw@x.example', 'https://x.example:8443', 'https://x.example/?a=1'] as $url) {
            $bad = $this->api('POST', '/fleet/sites', ['name' => 'X', 'url' => $url, 'token' => self::TOKEN]);
            $this->assertSame(422, $bad->statusCode(), $url);
            $this->assertArrayHasKey('url', $this->json($bad)['errors'] ?? [], $url);
        }

        $this->assertSame(204, $this->api('DELETE', "/fleet/sites/{$site['id']}")->statusCode());
        $this->assertSame(0, $this->db->table('fleet_sites')->count());
        $this->assertSame(404, $this->api('DELETE', "/fleet/sites/{$site['id']}")->statusCode());
    }

    public function testTheConsoleIsOffUnlessConfiguredAndOnlyForAdministrators(): void
    {
        $this->signIn();
        $this->assertSame(404, $this->api('GET', '/fleet/sites')->statusCode(), 'off by default');

        $this->cms->config->set('app.fleet.enabled', true);
        $this->assertSame(200, $this->api('GET', '/fleet/sites')->statusCode());
        $this->signIn('bob');
        $this->assertSame(403, $this->api('GET', '/fleet/sites')->statusCode());
    }

    public function testEveryFleetSiteIsCheckedOnSchedule(): void
    {
        $sites = $this->cms->container->make(FleetSites::class);
        $this->transport->on('GET https://bakery.example/api/v1/fleet/report', ['status' => 200, 'json' => ['data' => $this->report()]]);
        $this->transport->on('GET https://gone.example/api/v1/fleet/report', ['status' => 404, 'json' => []]);
        $sites->add('Bakery', 'https://bakery.example', self::TOKEN);
        $sites->add('Gone', 'https://gone.example', self::TOKEN);

        $this->assertSame(['checked' => 1, 'failed' => 1], $sites->checkAll());
        $this->assertSame(['Bakery', 'Gone'], array_column($sites->all(), 'name'));
        $this->assertStringContainsString('No fleet report at this address', (string) $sites->all()[1]['error']);
    }
}
