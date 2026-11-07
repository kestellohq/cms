<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Cms\Audit\AuditLog;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Json;

/**
 * What System → Security shows: who uses two-step sign-in, failed
 * sign-ins, everyone's sessions and tokens, and recent security events.
 * Reads only; ending sessions and tokens goes through AccountSecurity and
 * PersonalAccessTokens, whose callers record it in the audit log.
 *
 * Failed sign-ins come from the audit log (auth.login_failed), which never
 * holds what was typed into the login field.
 */
final class SecurityOverview
{
    /** Audit events about accounts and access, for the recent events card. */
    public const array EVENTS = [
        'auth.login', 'auth.registered', 'auth.password_changed', 'auth.password_reset_requested',
        'auth.2fa_enabled', 'auth.2fa_disabled', 'auth.session_revoked', 'auth.token_created', 'auth.token_revoked',
        'auth.identity_linked', 'auth.identity_unlinked',
        'users.created', 'users.updated', 'users.deleted', 'role.created', 'role.updated', 'role.deleted', 'role.reset',
    ];

    /** Sessions are asked for per person (stores index them by user): at most this many people. */
    public const int MAX_PEOPLE = 1000;

    public function __construct(private readonly AccountSecurity $accounts) {}

    /**
     * Two-step sign-in among active people, against the roles expected to use it.
     *
     * @param list<string> $roles roles expected to use it (auth.two_factor_roles)
     * @return array{roles: list<string>, required: int, covered: int, missing: list<array{id: int, username: string, role: string}>, everyone: array{total: int, covered: int}}
     */
    public function twoFactor(array $roles): array
    {
        $covered = array_flip(array_map('intval', QueryBuilder::table(TwoFactor::METHODS)->whereNotNull('confirmed_at')->pluck('user_id')));
        $people = QueryBuilder::table('users')->select('id', 'username', 'role')->where('is_active', 1)->orderBy('username')->get();
        $out = ['roles' => array_values($roles), 'required' => 0, 'covered' => 0, 'missing' => [], 'everyone' => ['total' => count($people), 'covered' => 0]];
        foreach ($people as $p) {
            $on = isset($covered[(int) $p['id']]);
            $out['everyone']['covered'] += $on ? 1 : 0;
            if (!in_array((string) $p['role'], $roles, true)) {
                continue;
            }
            $out['required']++;
            if ($on) {
                $out['covered']++;
            } elseif (count($out['missing']) < 100) {
                $out['missing'][] = ['id' => (int) $p['id'], 'username' => (string) $p['username'], 'role' => (string) $p['role']];
            }
        }

        return $out;
    }

    /**
     * Failed sign-ins: counts for the last day and week, the addresses they
     * came from most, and the latest ones.
     *
     * @return array{day: int, week: int, top_ips: list<array{ip: string, count: int}>, recent: list<array{id: int, occurred_at: int, ip: ?string, reason: string, user: ?array{id: int, username: string}}>}
     */
    public function failedSignIns(int $now, int $recent = 20): array
    {
        $failed = static fn() => QueryBuilder::table(AuditLog::TABLE)->where('event', 'auth.login_failed');
        $week = $now - 7 * 86_400;
        $top = $failed()->where('occurred_at', '>=', $week)->whereNotNull('ip')
            ->selectRaw('ip, COUNT(*) AS n')->groupBy('ip')->orderByDesc('n')->orderBy('ip')->limit(5)->get();
        $rows = $failed()->select('id', 'occurred_at', 'ip', 'subject_type', 'subject_id', 'data')->orderByDesc('occurred_at')->orderByDesc('id')->limit($recent)->get();
        $names = $this->usernames(array_map(static fn($r) => $r['subject_type'] === 'user' ? (int) $r['subject_id'] : 0, $rows));

        return [
            'day' => $failed()->where('occurred_at', '>=', $now - 86_400)->count(),
            'week' => $failed()->where('occurred_at', '>=', $week)->count(),
            'top_ips' => array_map(static fn($r) => ['ip' => (string) $r['ip'], 'count' => (int) $r['n']], $top),
            'recent' => array_map(static function (array $r) use ($names): array {
                $id = $r['subject_type'] === 'user' ? (int) $r['subject_id'] : 0;

                return [
                    'id' => (int) $r['id'],
                    'occurred_at' => (int) $r['occurred_at'],
                    'ip' => $r['ip'] === null ? null : (string) $r['ip'],
                    'reason' => (string) (Json::tryDecode($r['data'], [])['reason'] ?? 'unknown'),
                    'user' => isset($names[$id]) ? ['id' => $id, 'username' => $names[$id]] : null,
                ];
            }, $rows),
        ];
    }

    /**
     * Everyone's signed-in sessions, most recently active first. Empty when
     * the session store can't list them (files).
     *
     * @param string|null $current the hashed id of the asking session, marked as current
     * @return list<array{id: string, user: array{id: int, username: string}, ip: string, user_agent: string, created_at: int, last_active_at: int, current: bool}>
     */
    public function sessions(?string $current): array
    {
        if (!$this->accounts->tracksDevices()) {
            return [];
        }
        $out = [];
        $people = QueryBuilder::table('users')->select('id', 'username')->where('is_active', 1)->orderByDesc('id')->limit(self::MAX_PEOPLE)->get();
        foreach ($people as $p) {
            foreach ($this->accounts->sessions((int) $p['id'], $current) as $s) {
                $out[] = ['id' => $s['id'], 'user' => ['id' => (int) $p['id'], 'username' => (string) $p['username']]] + $s;
            }
        }
        usort($out, static fn(array $a, array $b) => $b['last_active_at'] <=> $a['last_active_at']);

        return $out;
    }

    /**
     * Every access token, newest first, with its owner. Never the secret.
     *
     * @return list<array<string, mixed>> rows of PersonalAccessTokens::PUBLIC_COLUMNS plus username
     */
    public function tokens(int $limit = 500): array
    {
        $rows = QueryBuilder::table(PersonalAccessTokens::TABLE)->select(...PersonalAccessTokens::PUBLIC_COLUMNS)->orderByDesc('id')->limit($limit)->get();
        $names = $this->usernames(array_map(static fn($r) => (int) $r['user_id'], $rows));

        return array_map(static fn(array $r) => $r + ['username' => $names[(int) $r['user_id']] ?? null], $rows);
    }

    /**
     * Recent account and access events from the audit log, with who did them.
     *
     * @return list<array{id: int, occurred_at: int, event: string, ip: ?string, subject_type: ?string, subject_id: ?string, data: array<string, mixed>, actor: ?array{id: int, username: ?string}}>
     */
    public function events(int $limit = 12): array
    {
        $rows = QueryBuilder::table(AuditLog::TABLE)->select('id', 'occurred_at', 'event', 'actor_id', 'ip', 'subject_type', 'subject_id', 'data')
            ->whereIn('event', self::EVENTS)->orderByDesc('id')->limit($limit)->get();
        $names = $this->usernames(array_map(static fn($r) => (int) $r['actor_id'], $rows));

        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'],
            'occurred_at' => (int) $r['occurred_at'],
            'event' => (string) $r['event'],
            'ip' => $r['ip'] === null ? null : (string) $r['ip'],
            'subject_type' => $r['subject_type'] === null ? null : (string) $r['subject_type'],
            'subject_id' => $r['subject_id'] === null ? null : (string) $r['subject_id'],
            'data' => (array) Json::tryDecode($r['data'], []),
            'actor' => $r['actor_id'] === null ? null : ['id' => (int) $r['actor_id'], 'username' => $names[(int) $r['actor_id']] ?? null],
        ], $rows);
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function usernames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (QueryBuilder::table('users')->select('id', 'username')->whereIn('id', $ids)->get() as $u) {
            $out[(int) $u['id']] = (string) $u['username'];
        }

        return $out;
    }
}
