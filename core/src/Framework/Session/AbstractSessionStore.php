<?php

declare(strict_types=1);

namespace Kestello\Framework\Session;

use Closure;
use Kestello\Contracts\Session\SessionStore;

/** Shared bits: metadata provider, id validation, lifetime. */
abstract class AbstractSessionStore implements SessionStore
{
    /** Don't rewrite last_activity more often than this (lazy-write sessions). */
    protected const int TOUCH_INTERVAL = 60;

    /** @var (Closure(): SessionMetadata)|null */
    private ?Closure $metadata = null;

    public function __construct(protected readonly int $lifetime = 7200) {}

    /** @param Closure(): SessionMetadata $provider evaluated at write time */
    public function setMetadataProvider(Closure $provider): void
    {
        $this->metadata = $provider;
    }

    protected function metadata(): SessionMetadata
    {
        return $this->metadata !== null ? ($this->metadata)() : new SessionMetadata();
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function tracksDevices(): bool
    {
        return true;
    }

    protected static function hash(string $id): string
    {
        return SessionMetadata::hashId($id);
    }

    protected static function clip(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
