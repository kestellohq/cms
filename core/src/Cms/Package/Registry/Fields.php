<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Kestello\Cms\Package\Update\PackageFeed;
use Kestello\Framework\Support\Semver;

/**
 * Small checks shared by the registry readers. Each returns the clean value, or null when the
 * value is missing or not acceptable, and never guesses: the caller decides whether a null
 * refuses the file or skips one entry.
 *
 * @internal
 */
final class Fields
{
    /** Package names: the manifest's slug rule, 3 to 64 characters (registry-format.md §1.1). */
    private const string NAME = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** Leeway for the site's clock on every date check, either way. */
    public const int CLOCK_TOLERANCE = 86_400;

    public static function packageName(mixed $v): ?string
    {
        return is_string($v) && strlen($v) >= 3 && strlen($v) <= 64 && preg_match(self::NAME, $v) === 1 ? $v : null;
    }

    public static function organisationId(mixed $v): ?string
    {
        return is_string($v) && strlen($v) >= 2 && strlen($v) <= 39 && preg_match(self::NAME, $v) === 1 ? $v : null;
    }

    /** One line of text, 1 to $max characters, without control characters. */
    public static function line(mixed $v, int $max): ?string
    {
        return is_string($v) && $v !== '' && mb_check_encoding($v, 'UTF-8') && mb_strlen($v) <= $max && preg_match('/[\x00-\x1f\x7f]/', $v) !== 1 ? $v : null;
    }

    /** Markdown: up to $max characters, line breaks allowed; missing is ''. */
    public static function text(mixed $v, int $max): ?string
    {
        if ($v === null) {
            return '';
        }

        return is_string($v) && mb_check_encoding($v, 'UTF-8') && mb_strlen($v) <= $max && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $v) !== 1 ? $v : null;
    }

    /** An ISO 8601 date and time with a zone ("2026-12-01T10:00:00Z"), as a Unix timestamp. */
    public static function time(mixed $v): ?int
    {
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $v) !== 1) {
            return null;
        }
        $t = strtotime($v);

        return $t === false ? null : $t;
    }

    public static function version(mixed $v): ?string
    {
        return is_string($v) && strlen($v) <= 64 && Semver::isValid($v) ? $v : null;
    }

    public static function constraint(mixed $v): ?string
    {
        return is_string($v) && strlen($v) <= 100 && Semver::isValidConstraint($v) ? $v : null;
    }

    public static function sha256(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[a-f0-9]{64}$/', $v) === 1 ? $v : null;
    }

    /** A key id: 16 lowercase hex characters (PackageSignature::keyId()). */
    public static function keyId(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[a-f0-9]{16}$/', $v) === 1 ? $v : null;
    }

    /** A raw 32-byte Ed25519 public key from base64. */
    public static function publicKey(mixed $v): ?string
    {
        $raw = is_string($v) ? base64_decode($v, true) : false;

        return is_string($raw) && strlen($raw) === 32 ? $raw : null;
    }

    public static function https(mixed $v): ?string
    {
        return is_string($v) && PackageFeed::isHttps($v) ? $v : null;
    }

    /**
     * A path inside the registry, relative to its base address: lowercase segments of letters,
     * digits, dots, dashes and underscores, each starting with a letter or digit (so never "..").
     */
    public static function path(mixed $v): ?string
    {
        return is_string($v) && strlen($v) <= 300 && preg_match('#^(?:[a-z0-9][a-z0-9._-]*/)*[a-z0-9][a-z0-9._-]*$#', $v) === 1 ? $v : null;
    }

    /**
     * A list of at most $max values that all pass $check; null when it isn't one.
     *
     * @template T
     * @param callable(mixed): (T|null) $check
     * @return list<T>|null
     */
    public static function listOf(mixed $v, int $max, callable $check): ?array
    {
        if ($v === null) {
            return [];
        }
        if (!is_array($v) || !array_is_list($v) || count($v) > $max) {
            return null;
        }
        $out = [];
        foreach ($v as $item) {
            $clean = $check($item);
            if ($clean === null) {
                return null;
            }
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * JSON text to an array, or a RegistryException naming $what.
     *
     * @return array<mixed>
     */
    public static function json(string $bytes, string $what): array
    {
        $data = json_decode($bytes, true, 32);
        if (!is_array($data) || array_is_list($data)) {
            throw new RegistryException("The registry's {$what} is not readable JSON.");
        }

        return $data;
    }
}
