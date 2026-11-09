<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Closure;
use InvalidArgumentException;
use Kestello\Contracts\Security\SecretStore;
use Throwable;

/**
 * License keys for paid plugins and themes, entered by an administrator.
 *
 * Kept sealed with the app key; only the last four characters are ever shown again. A key is
 * sent only to its package's own feed (PackageUpdates), never shown in the admin or the API.
 *
 * @internal
 */
final class PackageLicenses
{
    private const string CONTEXT = 'package-license';

    /**
     * @param Closure(string $owner): ?string $load  the stored JSON {sealed, last4}, or null
     * @param Closure(string $owner, ?string $json): void $save  null forgets
     */
    public function __construct(
        private readonly SecretStore $secrets,
        private readonly Closure $load,
        private readonly Closure $save,
    ) {}

    /** @throws InvalidArgumentException when the key can't be one */
    public function set(string $owner, string $key): void
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 500 || preg_match('/[\x00-\x20\x7f]/', $key) === 1) {
            throw new InvalidArgumentException('A license key is 1 to 500 characters with no spaces.');
        }
        ($this->save)($owner, (string) json_encode([
            'sealed' => $this->secrets->seal($key, self::CONTEXT . ':' . $owner),
            'last4' => mb_substr($key, -4),
        ]));
    }

    /** The key, or null when none is set (or it was sealed with a key this site no longer has). */
    public function get(string $owner): ?string
    {
        $stored = $this->stored($owner);
        if ($stored === null) {
            return null;
        }
        try {
            return $this->secrets->open($stored['sealed'], self::CONTEXT . ':' . $owner);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{set: bool, last4: ?string} for the admin: never the key */
    public function describe(string $owner): array
    {
        $stored = $this->stored($owner);

        return ['set' => $stored !== null, 'last4' => $stored['last4'] ?? null];
    }

    public function remove(string $owner): void
    {
        ($this->save)($owner, null);
    }

    /** @return array{sealed: string, last4: string}|null */
    private function stored(string $owner): ?array
    {
        $data = json_decode((string) ($this->load)($owner), true);

        return is_array($data) && is_string($data['sealed'] ?? null) && is_string($data['last4'] ?? null) ? ['sealed' => $data['sealed'], 'last4' => $data['last4']] : null;
    }
}
