<?php

declare(strict_types=1);

namespace Kestello\Cms\Audit;

use Closure;
use Kestello\Cms\Auth\Roles;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Log\Log;
use Kestello\Support\Json;

/**
 * $cms->audit: an append-only record of security-relevant actions.
 *
 *   $cms->audit->record('acme-shop.refund', subject: ['order', $id], data: ['amount' => 20]);
 *
 * Core records auth events (auth.*), platform changes and token changes.
 * Plugin events must start with the plugin slug. Values under keys that
 * look secret (password, token, secret, code…) are redacted. Writing never
 * throws: if the database is down the entry goes to the PHP error log.
 *
 * @api
 */
final class AuditLog
{
    public const string TABLE = 'audit_log';

    private const string REDACTED_KEYS = '/pass(word)?|token|secret|code|key|otp|cookie|authorization/i';

    /** @var Closure(): array{actor_id: ?int, ip: ?string, user_agent: ?string, request_id: ?string}|null */
    private ?Closure $context = null;

    /** @var list<Closure(string, ?array): void> */
    private array $listeners = [];

    /** @internal core reacts to recorded changes (the page cache flushes on settings and menu changes). */
    public function listen(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /** @param Closure(): array{actor_id: ?int, ip: ?string, user_agent: ?string, request_id: ?string} $context */
    public function setContextProvider(Closure $context): void
    {
        $this->context = $context;
    }

    /**
     * @param array{0: string, 1: string|int}|null $subject [type, id], e.g. ['user', 5]
     * @param array<string, mixed>                 $data
     */
    public function record(string $event, ?array $subject = null, array $data = [], ?int $actorId = null): void
    {
        $owner = ExecutionContext::current();
        if ($owner !== 'core') {
            Roles::assertOwnedName($event, $owner, '.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{1,99}$/', $event) !== 1) {
            throw new \InvalidArgumentException("Invalid audit event name \"{$event}\".");
        }

        $ctx = $this->context !== null ? ($this->context)() : [];
        $row = [
            'occurred_at' => time(),
            'event' => $event,
            'actor_id' => $actorId ?? ($ctx['actor_id'] ?? null),
            'subject_type' => $subject !== null ? substr((string) $subject[0], 0, 50) : null,
            'subject_id' => $subject !== null ? substr((string) $subject[1], 0, 100) : null,
            'ip' => $ctx['ip'] ?? null,
            'user_agent' => isset($ctx['user_agent']) ? mb_substr((string) $ctx['user_agent'], 0, 500) : null,
            'request_id' => $ctx['request_id'] ?? \Kestello\Framework\Http\RequestId::current(),
            'owner' => $owner,
            'data' => $data === [] ? null : Json::encode(self::redact($data)),
        ];

        try {
            if (!DatabaseManager::hasConnection()) {
                throw new \RuntimeException('no database connection');
            }
            QueryBuilder::table(self::TABLE)->insert($row);
        } catch (\Throwable $e) {
            Log::error('audit ' . $event . ' (not stored: ' . $e->getMessage() . '): ' . Json::encode($row));
        }
        foreach ($this->listeners as $listener) {
            try {
                $listener($event, $subject);
            } catch (\Throwable $e) {
                Log::error('audit listener failed for ' . $event . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Newest first, keyset-paginated by id.
     *
     * @param array{event?: string, actor_id?: int, subject_type?: string, subject_id?: string, since?: int, before_id?: int} $filters
     *        "event" may end with "*" for a prefix match ("auth.*")
     * @return list<array<string, mixed>>
     */
    public function query(array $filters = [], int $limit = 50): array
    {
        $q = QueryBuilder::table(self::TABLE)->orderBy('id', 'DESC')->limit(max(1, min($limit, 500)));

        if (isset($filters['event']) && $filters['event'] !== '') {
            str_ends_with($filters['event'], '*')
                ? $q->whereLike('event', rtrim($filters['event'], '*') . '%')
                : $q->where('event', $filters['event']);
        }
        foreach (['actor_id', 'subject_type', 'subject_id'] as $column) {
            if (isset($filters[$column])) {
                $q->where($column, $filters[$column]);
            }
        }
        if (isset($filters['since'])) {
            $q->where('occurred_at', '>=', (int) $filters['since']);
        }
        if (isset($filters['before_id'])) {
            $q->where('id', '<', (int) $filters['before_id']);
        }

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['occurred_at'] = (int) $row['occurred_at'];
            $row['actor_id'] = $row['actor_id'] === null ? null : (int) $row['actor_id'];
            $row['data'] = Json::tryDecode($row['data'], []);

            return $row;
        }, $q->get());
    }

    /** Delete entries older than $days. Returns how many were removed. */
    public function prune(int $days): int
    {
        return QueryBuilder::table(self::TABLE)->where('occurred_at', '<', time() - max(1, $days) * 86400)->delete();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            // Strings only: counts and flags under such keys ("tokens_revoked": 2) aren't secrets.
            if (is_string($key) && is_string($value) && preg_match(self::REDACTED_KEYS, $key) === 1) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
