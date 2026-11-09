<?php

declare(strict_types=1);

namespace Kestello\Framework\Support;

use InvalidArgumentException;

/**
 * Semantic versioning: parsing, comparison and constraint matching.
 *
 * Supported constraint syntax (Composer/npm-style):
 *   1.2.3   =1.2.3   >1.2   >=1.2.0   <2   <=2.1   !=1.5.0
 *   ^1.2    (>=1.2.0 <2.0.0; ^0.3 means >=0.3.0 <0.4.0)
 *   ~1.2    (>=1.2.0 <1.3.0)   ~1  (>=1.0.0 <2.0.0)
 *   1.2.*   1.x   *
 *   ">=1.2 <2.0"  (space or comma = AND)     "^1.0 || ^2.0"  (OR)
 *
 * Pre-release versions (1.0.0-beta.1) sort before their release.
 */
final class Semver
{
    private const string PATTERN = '/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/';

    public static function isValid(string $version): bool
    {
        return preg_match('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', trim($version)) === 1;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: string} major, minor, patch, pre-release
     */
    public static function parse(string $version): array
    {
        if (preg_match(self::PATTERN, trim($version), $m) !== 1) {
            throw new InvalidArgumentException("Invalid version \"{$version}\".");
        }

        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), $m[4] ?? ''];
    }

    /**
     * @return int -1, 0 or 1
     */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);

        for ($i = 0; $i < 3; $i++) {
            if ($pa[$i] !== $pb[$i]) {
                return $pa[$i] <=> $pb[$i];
            }
        }

        return self::comparePre($pa[3], $pb[3]);
    }

    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (preg_split('/\s*\|\|\s*/', $constraint) ?: [] as $alternative) {
            if (self::satisfiesAll($version, $alternative)) {
                return true;
            }
        }

        return false;
    }

    /**
     * satisfies(), with a pre-release counted as the version it leads to: 0.1.0-rc.1 meets
     * "^0.1" and ">=0.1.0". For Kestello's own version against what packages require, so a
     * release candidate runs the plugins and themes made for its release. Strict semver would
     * refuse every one of them until the final version.
     */
    public static function satisfiesRelease(string $version, string $constraint): bool
    {
        return self::satisfies(explode('-', explode('+', $version, 2)[0], 2)[0], $constraint);
    }

    /**
     * Validate a constraint string without evaluating it.
     */
    public static function isValidConstraint(string $constraint): bool
    {
        try {
            self::satisfies('0.0.0', $constraint);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function satisfiesAll(string $version, string $group): bool
    {
        // Join operators separated from their version by spaces (">= 1.2" → ">=1.2").
        $group = (string) preg_replace('/(>=|<=|!=|>|<|=|\^|~)\s+/', '$1', trim($group));

        foreach (preg_split('/[\s,]+/', $group) ?: [] as $part) {
            if ($part !== '' && !self::satisfiesOne($version, $part)) {
                return false;
            }
        }

        return true;
    }

    private static function satisfiesOne(string $version, string $c): bool
    {
        if ($c === '*') {
            return true;
        }

        if (preg_match('/^(>=|<=|!=|>|<|=)?(.+)$/', $c, $m) !== 1) {
            throw new InvalidArgumentException("Invalid constraint \"{$c}\".");
        }

        $op = $m[1];
        $target = $m[2];

        if ($op === '' && ($target[0] === '^' || $target[0] === '~')) {
            return self::satisfiesRange($version, ...self::caretOrTilde($target));
        }

        if ($op === '' && preg_match('/(^|\.)(\*|x|X)$/', $target) === 1) {
            return self::satisfiesRange($version, ...self::wildcard($target));
        }

        $cmp = self::compare($version, $target);

        return match ($op) {
            '', '=' => $cmp === 0,
            '!=' => $cmp !== 0,
            '>' => $cmp > 0,
            '>=' => $cmp >= 0,
            '<' => $cmp < 0,
            '<=' => $cmp <= 0,
        };
    }

    /**
     * @return array{0: string, 1: string} inclusive lower bound, exclusive upper bound
     */
    private static function caretOrTilde(string $c): array
    {
        $kind = $c[0];
        $raw = substr($c, 1);
        $given = count(explode('.', explode('-', $raw)[0]));
        [$major, $minor, $patch] = self::parse($raw);
        $lower = "{$major}.{$minor}.{$patch}";

        if ($kind === '~') {
            $upper = $given === 1 ? ($major + 1) . '.0.0' : "{$major}." . ($minor + 1) . '.0';
            return [$lower, $upper . '-0'];
        }

        $upper = match (true) {
            $major > 0 || $given === 1 => ($major + 1) . '.0.0',
            $minor > 0 || $given === 2 => '0.' . ($minor + 1) . '.0',
            default => '0.0.' . ($patch + 1),
        };

        return [$lower, $upper . '-0'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function wildcard(string $c): array
    {
        $parts = explode('.', $c);

        if (count($parts) === 2) {
            $major = (int) $parts[0];
            return ["{$major}.0.0", ($major + 1) . '.0.0-0'];
        }

        $major = (int) $parts[0];
        $minor = (int) $parts[1];

        return ["{$major}.{$minor}.0", "{$major}." . ($minor + 1) . '.0-0'];
    }

    private static function satisfiesRange(string $version, string $lower, string $upper): bool
    {
        return self::compare($version, $lower) >= 0 && self::compare($version, $upper) < 0;
    }

    private static function comparePre(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        if ($a === '') {
            return 1; // release > pre-release
        }
        if ($b === '') {
            return -1;
        }

        $ia = explode('.', $a);
        $ib = explode('.', $b);

        for ($i = 0, $n = max(count($ia), count($ib)); $i < $n; $i++) {
            if (!isset($ia[$i])) {
                return -1;
            }
            if (!isset($ib[$i])) {
                return 1;
            }
            if ($ia[$i] === $ib[$i]) {
                continue;
            }

            $na = ctype_digit($ia[$i]);
            $nb = ctype_digit($ib[$i]);

            return match (true) {
                $na && $nb => (int) $ia[$i] <=> (int) $ib[$i],
                $na => -1,
                $nb => 1,
                default => strcmp($ia[$i], $ib[$i]) <=> 0,
            };
        }

        return 0;
    }
}
