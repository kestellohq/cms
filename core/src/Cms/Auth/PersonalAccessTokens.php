<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use DateTimeImmutable;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Personal access tokens: "kst_pat_<48 hex>". Only the SHA-256 hash is
 * stored; the plain token is returned once, on creation. The prefix lets
 * secret scanners recognise leaked tokens.
 */
final class PersonalAccessTokens
{
    public const string PREFIX = 'kst_pat_';
    public const string TABLE = 'personal_access_tokens';

    /** Columns safe to expose (never token_hash). */
    public const array PUBLIC_COLUMNS = ['id', 'user_id', 'name', 'scopes', 'expires_at', 'last_used_at', 'last_used_ip', 'created_at'];

    /**
     * @param list<string> $scopes
     * @return array{token: string, record: array<string, mixed>}
     */
    public function create(int $userId, string $name, array $scopes, ?DateTimeImmutable $expiresAt = null): array
    {
        $plain = self::PREFIX . bin2hex(random_bytes(24));

        $id = QueryBuilder::table(self::TABLE)->insert([
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => self::hash($plain),
            'scopes' => json_encode(array_values(array_unique($scopes)), JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['token' => $plain, 'record' => (array) $this->find($id)];
    }

    /**
     * Resolve a plain token to [token row, user row], or null when unknown,
     * expired, malformed, or the user is inactive.
     *
     * @return array{token: array<string, mixed>, user: array<string, mixed>}|null
     */
    public function verify(string $plain, ?string $ip = null): ?array
    {
        if (!preg_match('/^' . self::PREFIX . '[0-9a-f]{48}$/', $plain)) {
            return null;
        }

        $token = QueryBuilder::table(self::TABLE)->where('token_hash', self::hash($plain))->first();

        if ($token === null || !hash_equals((string) $token['token_hash'], self::hash($plain))) {
            return null;
        }
        if ($token['expires_at'] !== null && strtotime((string) $token['expires_at']) <= time()) {
            return null;
        }

        $user = QueryBuilder::table('users')->where('id', $token['user_id'])->first();
        if ($user === null || !(bool) ($user['is_active'] ?? true)) {
            return null;
        }

        // Touch at most once a minute to keep reads cheap.
        if ($token['last_used_at'] === null || strtotime((string) $token['last_used_at']) < time() - 60) {
            QueryBuilder::table(self::TABLE)->where('id', $token['id'])->update([
                'last_used_at' => date('Y-m-d H:i:s'),
                'last_used_ip' => $ip,
            ]);
        }

        return ['token' => $token, 'user' => $user];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return QueryBuilder::table(self::TABLE)->select(...self::PUBLIC_COLUMNS)->where('id', $id)->first();
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return QueryBuilder::table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->get();
    }

    /** Revoke one of the user's tokens. False when it isn't theirs (or doesn't exist). */
    public function revoke(int $userId, int $tokenId): bool
    {
        return QueryBuilder::table(self::TABLE)->where('id', $tokenId)->where('user_id', $userId)->delete() > 0;
    }

    public function revokeAll(int $userId): int
    {
        return QueryBuilder::table(self::TABLE)->where('user_id', $userId)->delete();
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** @return list<string> */
    public static function decodeScopes(mixed $json): array
    {
        $scopes = is_string($json) ? json_decode($json, true) : null;

        return is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
    }
}
