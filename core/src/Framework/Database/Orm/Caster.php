<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Kestello\Contracts\Database\CastsAttributes;
use Kestello\Support\AppKey;
use Kestello\Support\Collection;

/**
 * Attribute casts. Database values go in and out as scalars; PHP code
 * sees the cast type.
 *
 *   int, float, bool, string           scalars
 *   array / json, collection           JSON text ↔ array / Collection
 *   datetime, date, timestamp          DateTimeImmutable in UTC (datetime and date are
 *                                      stored as text, timestamp as unix seconds)
 *   SomeBackedEnum::class              the enum's value
 *   encrypted, encrypted:array         sealed with the app key (AppKey::secretStore()),
 *                                      bound to table and column
 *   SomeCast::class                    a Kestello\Contracts\Database\CastsAttributes
 *
 * @internal used by Model
 */
final class Caster
{
    private const string DATETIME = 'Y-m-d H:i:s';

    /** @var array<string, CastsAttributes> */
    private static array $custom = [];

    public static function validate(string $type): void
    {
        if (self::kind($type) === null) {
            throw new InvalidArgumentException("Unknown cast [{$type}].");
        }
    }

    public static function get(string $type, mixed $value, object $model, string $key, string $context): mixed
    {
        $kind = self::kind($type);
        if ($kind === 'custom') {
            return self::custom($type)->get($model, $key, $value);
        }
        if ($value === null) {
            return $kind === 'collection' ? new Collection() : null;
        }

        return match ($kind) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => is_string($value) ? !in_array(strtolower($value), ['0', '', 'f', 'false'], true) : (bool) $value,
            'string' => (string) $value,
            'array' => is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR),
            'collection' => new Collection(is_array($value) ? $value : (array) json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR)),
            'datetime', 'date' => $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : new DateTimeImmutable((string) $value, self::utc()),
            'timestamp' => (new DateTimeImmutable('@' . (int) $value))->setTimezone(self::utc()),
            'enum' => $type::from(is_numeric($value) && self::enumIsInt($type) ? (int) $value : $value),
            'encrypted' => AppKey::secretStore()->open((string) $value, $context),
            'encrypted:array' => json_decode(AppKey::secretStore()->open((string) $value, $context), true, 512, JSON_THROW_ON_ERROR),
        };
    }

    public static function set(string $type, mixed $value, object $model, string $key, string $context): mixed
    {
        $kind = self::kind($type);
        if ($kind === 'custom') {
            return self::custom($type)->set($model, $key, $value);
        }
        if ($value === null) {
            return null;
        }

        return match ($kind) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            'array', 'collection' => json_encode($value instanceof Collection ? $value->all() : $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'datetime' => self::toDate($value)->format(self::DATETIME),
            'date' => self::toDate($value)->format('Y-m-d'),
            'timestamp' => self::toDate($value)->getTimestamp(),
            'enum' => $value instanceof BackedEnum ? $value->value : $type::from($value)->value,
            'encrypted' => AppKey::secretStore()->seal((string) $value, $context),
            'encrypted:array' => AppKey::secretStore()->seal(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $context),
        };
    }

    /** A cast value as it appears in toArray(). */
    public static function present(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof Collection => $value->toArray(),
            default => $value,
        };
    }

    public static function toDate(mixed $value): DateTimeImmutable
    {
        return match (true) {
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc()),
            is_int($value) => (new DateTimeImmutable('@' . $value))->setTimezone(self::utc()),
            is_string($value) && $value !== '' => (new DateTimeImmutable($value, self::utc()))->setTimezone(self::utc()),
            default => throw new InvalidArgumentException('Not a date: ' . get_debug_type($value)),
        };
    }

    private static function kind(string $type): ?string
    {
        return match ($type) {
            'int', 'integer' => 'int',
            'float', 'double', 'decimal' => 'float',
            'bool', 'boolean' => 'bool',
            'string' => 'string',
            'array', 'json' => 'array',
            'collection' => 'collection',
            'datetime', 'immutable_datetime' => 'datetime',
            'date' => 'date',
            'timestamp' => 'timestamp',
            'encrypted', 'encrypted:string' => 'encrypted',
            'encrypted:array' => 'encrypted:array',
            default => match (true) {
                enum_exists($type) && is_subclass_of($type, BackedEnum::class) => 'enum',
                class_exists($type) && is_subclass_of($type, CastsAttributes::class) => 'custom',
                default => null,
            },
        };
    }

    private static function custom(string $class): CastsAttributes
    {
        return self::$custom[$class] ??= new $class();
    }

    private static function enumIsInt(string $enum): bool
    {
        return (string) (new \ReflectionEnum($enum))->getBackingType() === 'int';
    }

    private static function utc(): DateTimeZone
    {
        static $utc = null;

        return $utc ??= new DateTimeZone('UTC');
    }
}
