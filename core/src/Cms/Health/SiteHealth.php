<?php

declare(strict_types=1);

namespace Kestello\Cms\Health;

use Closure;
use InvalidArgumentException;
use Kestello\Contracts\Cache\Store;
use Kestello\Framework\Core\ExecutionContext;

/**
 * $cms->health: the Site Health checks registry.
 *
 *   $cms->health->check('acme-backup.recent', 'Recent backup', function (): HealthResult {
 *       $last = Backups::lastRun();
 *       return $last > time() - 86400 * 2 ? HealthResult::good('Last backup ' . Date::ago($last))
 *                                          : HealthResult::critical('No backup in two days.');
 *   }, category: 'reliability');
 *
 * Checks run in their owner's context; one that throws is reported as
 * critical with its error, and never breaks the report. Plugin check ids
 * start with "<slug>.". Reports are cached briefly (checks may hit the
 * network), refresh with run(fresh: true).
 *
 * @api
 */
final class SiteHealth
{
    public const array CATEGORIES = ['security', 'performance', 'reliability', 'configuration'];
    private const string CACHE_KEY = 'health:report';

    /** @var array<string, array{label: string, check: Closure(): HealthResult, category: string, owner: string}> */
    private array $checks = [];

    private ?Closure $cache = null;

    /** @internal @param Closure(): ?Store $cache */
    public function setCache(Closure $cache): void
    {
        $this->cache = $cache;
    }

    /** @param Closure(): HealthResult $check */
    public function check(string $id, string $label, Closure $check, string $category = 'configuration'): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/', $id) !== 1) {
            throw new InvalidArgumentException("Invalid health check id \"{$id}\".");
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException("Unknown health category \"{$category}\" (" . implode(', ', self::CATEGORIES) . ').');
        }
        $owner = ExecutionContext::current();
        if ($owner !== 'core') {
            $slug = explode(':', $owner, 2)[1] ?? '';
            if (!str_starts_with($id, $slug . '.')) {
                throw new InvalidArgumentException("Health checks from {$owner} must start with \"{$slug}.\".");
            }
        }
        if (isset($this->checks[$id]) && $this->checks[$id]['owner'] !== $owner) {
            throw new InvalidArgumentException("Health check \"{$id}\" is already registered.");
        }
        $this->checks[$id] = ['label' => $label, 'check' => $check, 'category' => $category, 'owner' => $owner];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->checks);
    }

    /**
     * Run every check (or the given ids), worst first.
     *
     * @param list<string>|null $only
     * @return array{generated_at: int, status: string, counts: array<string, int>, checks: list<array<string, mixed>>}
     */
    public function run(bool $fresh = false, ?array $only = null, int $ttl = 300): array
    {
        $store = $only === null && $this->cache !== null ? ($this->cache)() : null;
        if (!$fresh && $store !== null) {
            $cached = $store->get(self::CACHE_KEY);
            if (is_array($cached) && ($cached['ids'] ?? null) === $this->ids()) {
                unset($cached['ids']);
                return $cached;
            }
        }

        $checks = [];
        foreach ($this->checks as $id => $def) {
            if ($only !== null && !in_array($id, $only, true)) {
                continue;
            }
            $checks[] = $this->runOne($id, $def);
        }
        usort($checks, static fn(array $a, array $b) => [HealthResult::rank($b['status']), $a['category'], $a['id']] <=> [HealthResult::rank($a['status']), $b['category'], $b['id']]);

        $counts = [HealthResult::GOOD => 0, HealthResult::RECOMMENDED => 0, HealthResult::CRITICAL => 0];
        foreach ($checks as $c) {
            $counts[$c['status']]++;
        }
        $report = [
            'generated_at' => time(),
            'status' => $counts[HealthResult::CRITICAL] > 0 ? HealthResult::CRITICAL : ($counts[HealthResult::RECOMMENDED] > 0 ? HealthResult::RECOMMENDED : HealthResult::GOOD),
            'counts' => $counts,
            'checks' => $checks,
        ];
        if ($store !== null) {
            try {
                $store->put(self::CACHE_KEY, $report + ['ids' => $this->ids()], $ttl);
            } catch (\Throwable) {
                // a report that can't be cached is still a report
            }
        }

        return $report;
    }

    /** @param array{label: string, check: Closure(): HealthResult, category: string, owner: string} $def @return array<string, mixed> */
    private function runOne(string $id, array $def): array
    {
        $started = hrtime(true);
        try {
            $result = ExecutionContext::run($def['owner'], $def['check']);
            if (!$result instanceof HealthResult) {
                throw new \UnexpectedValueException('The check did not return a HealthResult.');
            }
        } catch (\Throwable $e) {
            $result = HealthResult::critical('The check itself failed: ' . $e->getMessage());
        }

        return [
            'id' => $id,
            'label' => $def['label'],
            'category' => $def['category'],
            'owner' => $def['owner'],
            'status' => $result->status,
            'message' => $result->message,
            'details' => $result->details,
            'action' => $result->action === null ? null : ['label' => $result->action[0], 'url' => $result->action[1]],
            'ms' => (int) round((hrtime(true) - $started) / 1e6),
        ];
    }
}
