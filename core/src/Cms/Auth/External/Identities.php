<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\External;

use Kestello\Contracts\Auth\ExternalIdentity;
use Kestello\Framework\Database\QueryBuilder;

/** The user_identities table. @api */
final class Identities
{
    /** @return array<string, mixed>|null */
    public function find(string $provider, string $subject): ?array
    {
        return QueryBuilder::table('user_identities')->where('provider', $provider)->where('subject', $subject)->first();
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return QueryBuilder::table('user_identities')->where('user_id', $userId)->orderBy('id')->get();
    }

    public function link(int $userId, ExternalIdentity $identity): int
    {
        return (int) QueryBuilder::table('user_identities')->insert([
            'user_id' => $userId,
            'provider' => $identity->provider,
            'subject' => $identity->subject,
            'email' => $identity->email !== null ? mb_substr($identity->email, 0, 255) : null,
            'created_at' => time(),
            'last_used_at' => time(),
        ]);
    }

    public function touch(int $id): void
    {
        QueryBuilder::table('user_identities')->where('id', $id)->update(['last_used_at' => time()]);
    }

    /** @return array<string, mixed>|null the removed row */
    public function unlink(int $userId, int $id): ?array
    {
        $row = QueryBuilder::table('user_identities')->where('id', $id)->where('user_id', $userId)->first();
        if ($row !== null) {
            QueryBuilder::table('user_identities')->where('id', $id)->delete();
        }

        return $row;
    }
}
