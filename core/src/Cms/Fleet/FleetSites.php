<?php

declare(strict_types=1);

namespace Kestello\Cms\Fleet;

use Closure;
use InvalidArgumentException;
use Kestello\Contracts\Security\SecretStore;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\Caster;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Support\Json;
use OutOfBoundsException;
use Throwable;

/**
 * The sites a fleet console watches (app.fleet.enabled): each one's address, its fleet token
 * (a token made on that site with only the "fleet" scope, sealed with this site's app key and
 * never shown again), and the last report it gave (FleetReport).
 *
 *   add('Bakery', 'https://bakery.example', 'kst_pat_…')   stored, then checked
 *   check($id)    ask the site now; a failure is kept as the site's error, the last report stays
 *   checkAll()    every site, for the scheduled task
 *
 * Only https addresses on public hosts are asked (HttpClient), with no redirects.
 *
 * @internal
 */
final class FleetSites
{
    public const int MAX_SITES = 500;

    private const string TABLE = 'fleet_sites';
    private const string CONTEXT = 'fleet-site';
    private const int MAX_BYTES = 262_144;

    /** @param (Closure(): int)|null $now */
    public function __construct(
        private readonly HttpClient $http,
        private readonly SecretStore $secrets,
        private readonly ?Closure $now = null,
    ) {}

    /** @return list<array<string, mixed>> by name */
    public function all(): array
    {
        return array_map(self::present(...), QueryBuilder::table(self::TABLE)->orderBy('name')->orderBy('id')->get());
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $row = QueryBuilder::table(self::TABLE)->where('id', $id)->first();

        return $row === null ? throw new OutOfBoundsException('No such site.') : self::present($row);
    }

    /**
     * @return array<string, mixed> the stored site, checked once
     * @throws InvalidArgumentException field => message
     */
    public function add(string $name, string $url, #[\SensitiveParameter] string $token): array
    {
        $name = self::name($name);
        $url = self::address($url);
        $token = self::token($token);
        if (QueryBuilder::table(self::TABLE)->where('url', $url)->exists()) {
            throw new InvalidArgumentException('url: This site is already in the fleet.');
        }
        if (QueryBuilder::table(self::TABLE)->count() >= self::MAX_SITES) {
            throw new InvalidArgumentException('url: A fleet has at most ' . self::MAX_SITES . ' sites.');
        }
        $now = Model::freshTimestamp();
        $id = (int) QueryBuilder::table(self::TABLE)->insert([
            'name' => $name, 'url' => $url, 'token' => $this->secrets->seal($token, self::CONTEXT), 'token_last4' => substr($token, -4),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->check($id);
    }

    /**
     * A new name or token. A new token is checked straight away.
     *
     * @param array{name?: string, token?: string} $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input): array
    {
        $this->find($id);
        $set = ['updated_at' => Model::freshTimestamp()];
        if (isset($input['name'])) {
            $set['name'] = self::name($input['name']);
        }
        if (isset($input['token'])) {
            $token = self::token($input['token']);
            $set += ['token' => $this->secrets->seal($token, self::CONTEXT), 'token_last4' => substr($token, -4)];
        }
        QueryBuilder::table(self::TABLE)->where('id', $id)->update($set);

        return isset($input['token']) ? $this->check($id) : $this->find($id);
    }

    public function remove(int $id): void
    {
        $this->find($id);
        QueryBuilder::table(self::TABLE)->where('id', $id)->delete();
    }

    /**
     * Ask the site for its report now.
     *
     * @return array<string, mixed> the site as stored after
     */
    public function check(int $id): array
    {
        $row = QueryBuilder::table(self::TABLE)->where('id', $id)->first() ?? throw new OutOfBoundsException('No such site.');
        $set = ['checked_at' => gmdate('Y-m-d H:i:s', $this->time()), 'updated_at' => Model::freshTimestamp()];
        try {
            $token = $this->secrets->open((string) $row['token'], self::CONTEXT);
            $set['report'] = Json::encode($this->fetch((string) $row['url'], $token));
            $set['error'] = null;
        } catch (Throwable $e) {
            $set['error'] = mb_substr($e->getMessage(), 0, 500);
        }
        QueryBuilder::table(self::TABLE)->where('id', $id)->update($set);

        return $this->find($id);
    }

    /** @return array{checked: int, failed: int} */
    public function checkAll(): array
    {
        $out = ['checked' => 0, 'failed' => 0];
        foreach (QueryBuilder::table(self::TABLE)->orderBy('id')->pluck('id') as $id) {
            $site = $this->check((int) $id);
            $out[$site['error'] === null ? 'checked' : 'failed']++;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function fetch(string $url, #[\SensitiveParameter] string $token): array
    {
        $res = $this->http->request('GET', $url . '/api/v1/fleet/report', ['bearer' => $token, 'timeout' => 15, 'max_redirects' => 0, 'max_bytes' => self::MAX_BYTES]);
        $status = $res->status;
        if ($status === 401 || $status === 403) {
            throw new \RuntimeException('The site refused the token. Make a new one there with only the "fleet" scope, by an administrator.');
        }
        if ($status === 404) {
            throw new \RuntimeException('No fleet report at this address. Is it a Kestello site, and the address of its home page?');
        }
        if ($status !== 200) {
            throw new \RuntimeException("The site answered HTTP {$status}.");
        }
        $json = json_decode($res->body, true);

        return FleetReport::normalize(is_array($json) ? ($json['data'] ?? null) : null);
    }

    /** "https://bakery.example" or "https://agency.example/bakery": https, no query, no trailing slash. */
    public static function address(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $p = parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') === '' || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443) || strlen($url) > 300) {
            throw new InvalidArgumentException('url: The address of the site\'s home page, starting with https://.');
        }

        return 'https://' . strtolower((string) $p['host']) . rtrim((string) ($p['path'] ?? ''), '/');
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('name: 1 to 120 characters.');
        }

        return $name;
    }

    private static function token(#[\SensitiveParameter] string $token): string
    {
        $token = trim($token);
        if (strlen($token) < 20 || strlen($token) > 500 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new InvalidArgumentException('token: Paste the whole token, as the site showed it.');
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> never the token
     */
    private static function present(array $row): array
    {
        $report = is_string($row['report'] ?? null) ? json_decode($row['report'], true) : null;

        return [
            'id' => (int) $row['id'], 'name' => (string) $row['name'], 'url' => (string) $row['url'], 'token_last4' => (string) $row['token_last4'],
            'report' => is_array($report) ? $report : null,
            'checked_at' => Caster::present($row['checked_at']), 'error' => $row['error'] === null ? null : (string) $row['error'],
        ];
    }

    private function time(): int
    {
        return $this->now !== null ? ($this->now)() : time();
    }
}
