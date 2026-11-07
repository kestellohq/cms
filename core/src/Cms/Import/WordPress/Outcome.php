<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

/**
 * What became of one staged item.
 *
 *   created    new here
 *   updated    changed in WordPress since the last import, and updated here
 *   unchanged  the same as last time
 *   kept       changed in WordPress, but also edited here since: left as it is here
 *   matched    already on this site (a term with the same slug): used as it is
 *   skipped    not imported, with the reason
 *   failed     could not be imported, with the reason; can be tried again
 *
 * @internal
 */
final readonly class Outcome
{
    public const array RESULTS = ['created', 'updated', 'unchanged', 'kept', 'matched', 'skipped', 'failed'];

    private function __construct(public string $result, public ?string $message, public ?int $targetId, public bool $needsLinks = false) {}

    public static function created(int $id, ?string $message = null, bool $needsLinks = false): self
    {
        return new self('created', $message, $id, $needsLinks);
    }

    public static function updated(int $id, ?string $message = null, bool $needsLinks = false): self
    {
        return new self('updated', $message, $id, $needsLinks);
    }

    public static function unchanged(int $id): self
    {
        return new self('unchanged', null, $id);
    }

    public static function kept(int $id, string $message): self
    {
        return new self('kept', $message, $id);
    }

    public static function matched(int $id, string $message): self
    {
        return new self('matched', $message, $id);
    }

    public static function skipped(string $message, ?int $id = null): self
    {
        return new self('skipped', $message, $id);
    }

    public static function failed(string $message): self
    {
        return new self('failed', $message, null);
    }
}
