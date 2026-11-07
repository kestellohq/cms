<?php

declare(strict_types=1);

namespace Kestello\Cms\Redirects;

use Kestello\Cms\Content\ContentException;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Html;
use Kestello\Support\Translator;

/**
 * Redirects and the "not found" log.
 *
 * Rules apply only to addresses that answer 404, so a redirect can never
 * hide a live page (or /admin, /login). To move a live page, change its
 * address: the old one is recorded here automatically.
 *
 *   exact   /old-page            → /new-page
 *   prefix  /old-blog            → /blog          (/old-blog/a/b → /blog/a/b)
 *   regex   ^/news/(\d+)/(.+)$   → /blog/$2
 *
 * Status 301, 302, 307 or 308, or 410 Gone (no target).
 */
final class Redirects
{
    public const array MATCHES = ['exact', 'prefix', 'regex'];
    public const array STATUSES = [301, 302, 307, 308, 410];
    public const int MAX_RULES = 10_000;
    public const int MAX_PATTERN_RULES = 2_000;     // prefix and regex rules are matched in PHP
    public const int MAX_LOG = 2_000;               // distinct "not found" addresses kept

    /**
     * The rule for a request path that found nothing, with the target to send the visitor to.
     *
     * @return array{id: int, status: int, target: string}|null
     */
    public function resolve(string $path, string $query = ''): ?array
    {
        $path = self::normalize($path);
        if ($path === null) {
            return null;
        }

        $row = QueryBuilder::table('redirects')->where('match', 'exact')->where('source', $path)->first();
        $target = $row === null ? null : (string) $row['target'];
        if ($row === null) {
            foreach ($this->patternRules() as $rule) {
                $target = self::apply($rule, $path);
                if ($target !== null) {
                    $row = $rule;
                    break;
                }
            }
        }
        if ($row === null || $target === null) {
            return null;
        }
        if ((int) $row['status'] !== 410 && self::normalize((string) (parse_url($target, PHP_URL_PATH) ?? '')) === $path && !str_contains($target, '://')) {
            return null;    // would send the visitor back here
        }
        if ($query !== '' && (int) $row['status'] !== 410 && !str_contains($target, '?')) {
            $target .= '?' . $query;
        }

        return ['id' => (int) $row['id'], 'status' => (int) $row['status'], 'target' => $target];
    }

    public function hit(int $id): void
    {
        QueryBuilder::table('redirects')->where('id', $id)->update(['hits' => QueryBuilder::raw('hits + 1'), 'last_hit_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** Remember an address that found nothing (no visitor data: the path and the page that linked to it). */
    public function logNotFound(string $path, string $referrer = ''): void
    {
        $path = self::normalize($path);
        if ($path === null) {
            return;
        }
        $referrer = mb_substr(self::cleanReferrer($referrer), 0, 500);
        $now = gmdate('Y-m-d H:i:s');
        $updated = QueryBuilder::table('not_found_log')->where('path', $path)
            ->update(['hits' => QueryBuilder::raw('hits + 1'), 'last_seen_at' => $now] + ($referrer !== '' ? ['referrer' => $referrer] : []));
        if ($updated > 0) {
            return;
        }
        try {
            QueryBuilder::table('not_found_log')->insert(['path' => $path, 'referrer' => $referrer, 'hits' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now]);
        } catch (\Throwable) {
            // another request logged it a moment ago
        }
    }

    /** Keep the log to the most recent MAX_LOG addresses. Returns rows removed. */
    public function pruneLog(int $keep = self::MAX_LOG): int
    {
        $cutoff = QueryBuilder::table('not_found_log')->orderBy('last_seen_at', 'DESC')->orderBy('id', 'DESC')->offset($keep)->limit(1)->first();
        if ($cutoff === null) {
            return 0;
        }

        return QueryBuilder::table('not_found_log')->where(static fn($q) => $q->where('last_seen_at', '<', $cutoff['last_seen_at'])
            ->orWhere(static fn($w) => $w->where('last_seen_at', $cutoff['last_seen_at'])->where('id', '<=', $cutoff['id'])))->delete();
    }

    // ── managing rules ───────────────────────────────────────────────

    /**
     * Create or update a rule. Throws ContentException (field errors) or OutOfBoundsException (no such rule).
     *
     * @param array<string, mixed> $input source, match, target, status, note
     * @return array<string, mixed>
     */
    public function save(array $input, ?int $id = null, string $origin = 'manual', ?int $entryId = null): array
    {
        $current = $id === null ? null : (QueryBuilder::table('redirects')->where('id', $id)->first() ?? throw new \OutOfBoundsException('No such redirect.'));
        $merged = array_merge($current ?? ['match' => 'exact', 'status' => 301, 'note' => '', 'target' => ''], array_intersect_key($input, array_flip(['source', 'match', 'target', 'status', 'note'])));
        [$row, $errors] = self::validate($merged);
        if ($errors === [] && $id === null && QueryBuilder::table('redirects')->count() >= self::MAX_RULES) {
            $errors['source'][] = Translator::get('The site has the maximum of {max} redirects. Remove some first.', ['max' => self::MAX_RULES]);
        }
        if ($errors === [] && $row['match'] !== 'exact' && ($current === null || $current['match'] === 'exact')
            && QueryBuilder::table('redirects')->where('match', '!=', 'exact')->count() >= self::MAX_PATTERN_RULES) {
            $errors['match'][] = Translator::get('The site has the maximum of {max} prefix and pattern redirects.', ['max' => self::MAX_PATTERN_RULES]);
        }
        if ($errors === []) {
            $dupe = QueryBuilder::table('redirects')->where('match', $row['match'])->where('source', $row['source'])->when($id !== null, fn($q) => $q->where('id', '!=', $id))->value('id');
            if ($dupe !== null) {
                $errors['source'][] = Translator::get('There is already a redirect for this address.');
            }
        }
        if ($errors === [] && $this->leadsBack($row)) {
            $errors['target'][] = Translator::get('That address already redirects back here, so visitors would go round in a circle.');
        }
        if ($errors !== []) {
            throw ContentException::invalid($errors);
        }

        $now = gmdate('Y-m-d H:i:s');
        if ($id === null) {
            $id = (int) QueryBuilder::table('redirects')->insert($row + ['origin' => $origin, 'entry_id' => $entryId, 'hits' => 0, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            QueryBuilder::table('redirects')->where('id', $id)->update($row + ['updated_at' => $now]);
        }

        return self::present(QueryBuilder::table('redirects')->where('id', $id)->first() ?? []);
    }

    public function delete(int $id): bool
    {
        return QueryBuilder::table('redirects')->where('id', $id)->delete() > 0;
    }

    /**
     * An entry moved from $from to $to: send the old address (and, with $children, everything below it) to the
     * new one. Rules that pointed at $from now point at $to (no chains), and a rule for $to itself is dropped
     * (the entry moved back), so nothing loops.
     */
    public function recordMove(string $from, string $to, ?int $entryId, bool $children): void
    {
        $from = self::normalize($from);
        $to = self::normalize($to);
        if ($from === null || $to === null || $from === $to) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        QueryBuilder::table('redirects')->where('target', $from)->update(['target' => $to, 'updated_at' => $now]);
        if ($children) {
            // rules below the old address keep their tail: /a/x → /b/x
            foreach (QueryBuilder::table('redirects')->whereLike('target', QueryBuilder::escapeLike($from) . '/%')->get() as $r) {
                QueryBuilder::table('redirects')->where('id', $r['id'])->update(['target' => $to . substr((string) $r['target'], strlen($from)), 'updated_at' => $now]);
            }
        }
        QueryBuilder::table('redirects')->whereIn('match', ['exact', 'prefix'])->where('source', $to)->where('origin', 'auto')->delete();
        $match = $children ? 'prefix' : 'exact';
        $existing = QueryBuilder::table('redirects')->where('match', $match)->where('source', $from)->value('id');
        if ($existing !== null) {
            QueryBuilder::table('redirects')->where('id', $existing)->update(['target' => $to, 'status' => 301, 'updated_at' => $now]);
            return;
        }
        QueryBuilder::table('redirects')->insert(['source' => $from, 'match' => $match, 'target' => $to, 'status' => 301, 'origin' => 'auto',
            'entry_id' => $entryId, 'note' => '', 'hits' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }

    // ── CSV ──────────────────────────────────────────────────────────

    /** source,target,status,match,note: one rule per line, a header line is optional. */
    public function export(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['source', 'target', 'status', 'match', 'note'], escape: '');
        foreach (QueryBuilder::table('redirects')->orderBy('source')->get() as $r) {
            fputcsv($out, array_map(static fn($v) => self::csvSafe((string) $v), [$r['source'], $r['target'], $r['status'], $r['match'], $r['note']]), escape: '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * Add rules from CSV. Rows with problems are skipped and reported by line; existing sources are updated.
     *
     * @return array{created: int, updated: int, errors: array<int, string>}
     */
    public function import(string $csv, int $maxRows = 5000): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        foreach (array_slice($lines, 0, $maxRows + 1) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, escape: '');
            if ($i === 0 && strtolower(trim((string) $cells[0])) === 'source') {
                continue;
            }
            $input = ['source' => trim((string) ($cells[0] ?? '')), 'target' => trim((string) ($cells[1] ?? '')), 'status' => (int) (($cells[2] ?? '') ?: 301),
                'match' => trim((string) ($cells[3] ?? '')) ?: 'exact', 'note' => mb_substr(trim((string) ($cells[4] ?? '')), 0, 255)];
            [$row, $errors] = self::validate($input);
            $existing = $errors === [] ? QueryBuilder::table('redirects')->where('match', $row['match'])->where('source', $row['source'])->value('id') : null;
            try {
                $this->save($input, $existing === null ? null : (int) $existing, 'import');
                $result[$existing === null ? 'created' : 'updated']++;
            } catch (ContentException $e) {
                $result['errors'][$i + 1] = implode(' ', array_merge(...array_values($e->errors ?: [[$e->getMessage()]])));
            }
        }
        if (count($lines) > $maxRows + 1) {
            $result['errors'][$maxRows + 2] = "Only the first {$maxRows} rows were read.";
        }

        return $result;
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** Whether following the existing rules from the new rule's target comes back to its source. @param array<string, mixed> $rule */
    private function leadsBack(array $rule): bool
    {
        $path = (string) $rule['target'];
        for ($hop = 0; $hop < 10 && str_starts_with($path, '/'); $hop++) {
            $path = self::normalize((string) (parse_url($path, PHP_URL_PATH) ?? '')) ?? '';
            if ($path === '') {
                return false;
            }
            $matches = $rule['match'] === 'exact' ? $path === $rule['source'] : self::apply($rule + ['status' => 301], $path) !== null;
            if ($matches) {
                return true;
            }
            $next = $this->resolve($path);
            if ($next === null || $next['target'] === '') {
                return false;
            }
            $path = $next['target'];
        }

        return $hop >= 10;      // a chain this long is a mistake either way
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'source' => (string) $row['source'], 'match' => (string) $row['match'], 'target' => (string) $row['target'],
            'status' => (int) $row['status'], 'origin' => (string) $row['origin'], 'entry_id' => $row['entry_id'] === null ? null : (int) $row['entry_id'],
            'note' => (string) $row['note'], 'hits' => (int) $row['hits'], 'last_hit_at' => $row['last_hit_at'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * "/Path/" → "/Path"; null for anything that isn't a plain absolute path.
     *
     * One spelling per address, the one browsers send: characters beyond
     * ASCII percent-encoded and escapes in upper case, so "/čaj", "/%c4%8daj"
     * and "/%C4%8Daj" are the same rule and match the same request. A "%"
     * that starts no escape is itself escaped ("%25").
     */
    public static function normalize(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1 || strlen($path) > 2000) {
            return null;
        }
        $path = (string) strtok($path, '?#');
        $path = (string) preg_replace_callback('/%[0-9a-f]{2}|%|[^\x21-\x7e]/i', static fn(array $m): string => $m[0][0] === '%' && strlen($m[0]) === 3 ? strtoupper($m[0]) : rawurlencode($m[0]), $path);
        if (strlen($path) > 500) {
            return null;
        }

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** @param array<string, mixed> $in @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    private static function validate(array $in): array
    {
        $errors = [];
        $match = (string) ($in['match'] ?? 'exact');
        if (!in_array($match, self::MATCHES, true)) {
            $errors['match'][] = Translator::get('Pick exact, prefix or pattern.');
        }
        $status = (int) ($in['status'] ?? 301);
        if (!in_array($status, self::STATUSES, true)) {
            $errors['status'][] = Translator::get('Use 301, 302, 307, 308 or 410.');
        }

        $source = trim((string) ($in['source'] ?? ''));
        if ($match === 'regex') {
            if ($source === '' || mb_strlen($source) > 300) {
                $errors['source'][] = Translator::get('Enter a pattern of at most 300 characters.');
            } elseif (@preg_match(self::regex($source), '') === false) {
                $errors['source'][] = Translator::get('This is not a valid regular expression.');
            }
        } else {
            $normalized = self::normalize($source);
            if ($normalized === null) {
                $errors['source'][] = Translator::get('Start with /, for example /old-page.');
            } elseif ($normalized === '/') {
                $errors['source'][] = Translator::get('The home page can\'t be redirected.');
            } else {
                $source = $normalized;
            }
        }

        $target = trim((string) ($in['target'] ?? ''));
        if ($status === 410) {
            $target = '';
        } elseif ($target === '') {
            $errors['target'][] = Translator::get('Enter where visitors should go.');
        } elseif (mb_strlen($target) > 1000 || preg_match('/[\x00-\x20\x7f]/', $target) === 1) {
            $errors['target'][] = Translator::get('Use a path like /new-page or an https:// address, without spaces.');
        } elseif (str_starts_with($target, '/')) {
            if (str_starts_with($target, '//') || str_contains($target, '\\')) {
                $errors['target'][] = Translator::get('Use a path like /new-page or an https:// address.');
            } elseif ($match !== 'regex' && self::normalize($target) === $source) {
                $errors['target'][] = Translator::get('It leads back to the same address. Enter another one.');
            }
        } elseif (!Html::safeUrl($target, ['http', 'https'])) {
            $errors['target'][] = Translator::get('Use a path like /new-page or an https:// address.');
        }

        $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 255);

        return [['source' => $source, 'match' => $match, 'target' => $target, 'status' => $status, 'note' => $note], $errors];
    }

    /** @return list<array<string, mixed>> */
    private function patternRules(): array
    {
        return QueryBuilder::table('redirects')->whereIn('match', ['prefix', 'regex'])->orderBy('id')->limit(self::MAX_PATTERN_RULES)->get();
    }

    /** The target for $path under a prefix or regex rule, or null when it doesn't match. @param array<string, mixed> $rule */
    private static function apply(array $rule, string $path): ?string
    {
        $source = (string) $rule['source'];
        $target = (string) $rule['target'];
        if ($rule['match'] === 'prefix') {
            if ($path !== $source && !str_starts_with($path, $source . '/')) {
                return null;
            }

            return (int) $rule['status'] === 410 ? '' : self::local(rtrim($target, '/') . substr($path, strlen($source)));
        }

        // patterns come from editors: cap the work one match may do, so a bad pattern can't hang a request
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '20000');
        try {
            $result = @preg_replace_callback(self::regex($source), static fn(array $m) => preg_replace_callback('/\$(\d)/', static fn($g) => rawurlencode(rawurldecode($m[(int) $g[1]] ?? '')), $target) ?? '', $path, 1, $count);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
        if (!is_string($result) || $count !== 1) {
            return null;
        }

        return (int) $rule['status'] === 410 ? '' : self::local($result);
    }

    /**
     * A computed target that starts with "/" stays on this site: "//evil.test"
     * or "/\evil.test" would be read by browsers as another host (a prefix
     * rule to "/" plus a request for "/old//evil.test" builds exactly that).
     */
    private static function local(string $target): string
    {
        if (!str_starts_with($target, '/') && !str_starts_with($target, '\\')) {
            return $target;
        }

        return '/' . ltrim($target, '/\\');
    }

    /** Whole-path match: "^" and "$" are implied, so a pattern can't match the middle of an address. */
    private static function regex(string $pattern): string
    {
        $pattern = preg_replace(['/^\^/', '/\$$/'], '', $pattern) ?? $pattern;

        return '#^(?:' . str_replace('#', '\#', $pattern) . ')$#u';
    }

    private static function cleanReferrer(string $referrer): string
    {
        $parts = parse_url($referrer);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');   // no query: it can hold tokens
    }

    /** Spreadsheet apps run cells starting with = + - @ as formulas: prefix them so exports are inert. */
    private static function csvSafe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
