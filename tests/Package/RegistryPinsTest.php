<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\Registry\RegistryTrust;
use Kestello\Cms\Package\Update\KeyPins;
use Kestello\Framework\Testing\TestCase;

/** Pins for packages from the registry: the organisation, its moves and hand-over keys. */
final class RegistryPinsTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $stored = [];

    private function pins(): KeyPins
    {
        return new KeyPins(
            fn(string $owner): ?string => $this->stored[$owner] ?? null,
            function (string $owner, ?string $json): void {
                $this->stored[$owner] = $json;
            },
        );
    }

    /** @return array{status: string, key: string} */
    private static function signed(string $key): array
    {
        return ['status' => PackageSignature::SIGNED, 'key' => $key];
    }

    public function testARegistryPackageIsPinnedToItsOrganisationNotAKey(): void
    {
        $pins = $this->pins();
        $pins->remember('plugin:events', self::signed('1111111111111111'), [], 'acme');
        $this->assertSame(['acme', null], [$pins->organisation('plugin:events'), $pins->pinned('plugin:events')]);

        $acme = ['organisation' => 'acme', 'moved_from' => []];
        $this->assertNull($pins->problem('plugin:events', self::signed('2222222222222222'), $acme), 'acme changed keys through the root');
        $this->assertStringContainsString('not a version the registry vouches for', (string) $pins->problem('plugin:events', self::signed('1111111111111111')), 'even the same key, outside the registry');
        $this->assertNotNull($pins->problem('plugin:events', ['status' => PackageSignature::UNTRUSTED, 'key' => '2222222222222222'], $acme), 'the signature must still check out');
        $this->assertNotNull($pins->problem('plugin:events', ['status' => PackageSignature::UNSIGNED, 'key' => null], $acme));
        $this->assertStringContainsString("hasn't moved", (string) $pins->problem('plugin:events', self::signed('3333333333333333'), ['organisation' => 'northwind', 'moved_from' => []]));
        $this->assertNull($pins->problem('plugin:events', self::signed('3333333333333333'), ['organisation' => 'northwind', 'moved_from' => ['acme']]), 'a root-signed move');
    }

    public function testHandOverKeysWorkForRegistryPackagesToo(): void
    {
        $pins = $this->pins();
        $pins->remember('plugin:events', self::signed('1111111111111111'), ['4444444444444444'], 'acme');
        $this->assertSame(['4444444444444444'], $pins->pinned('plugin:events'));
        $this->assertNull($pins->problem('plugin:events', self::signed('4444444444444444')), 'the installed version named this key');

        // installed from elsewhere afterwards: back to a key pin, the organisation forgotten
        $pins->remember('plugin:events', self::signed('4444444444444444'), []);
        $this->assertSame([null, ['4444444444444444']], [$pins->organisation('plugin:events'), $pins->pinned('plugin:events')]);
    }

    public function testPinsStoredByEarlierVersionsStillRead(): void
    {
        $this->stored['plugin:events'] = '["1111111111111111"]';
        $pins = $this->pins();
        $this->assertSame([null, ['1111111111111111']], [$pins->organisation('plugin:events'), $pins->pinned('plugin:events')]);
        $this->assertNull($pins->problem('plugin:events', self::signed('1111111111111111')));
        $this->assertStringContainsString('not signed by the publisher', (string) $pins->problem('plugin:events', self::signed('2222222222222222'), ['organisation' => 'acme', 'moved_from' => []]), 'a key pin ignores the registry');

        foreach (['', 'null', '[]', '{}', '{"organisation":""}', '{"organisation":5}', 'not json'] as $junk) {
            $this->stored['plugin:events'] = $junk;
            $this->assertNull($pins->problem('plugin:events', self::signed('2222222222222222')), "no pin: {$junk}");
        }
    }

    public function testMovesAreFollowedBackFromTheCurrentOrganisation(): void
    {
        $moves = [
            ['from' => 'acme', 'to' => 'northwind', 'issued_at' => 1],
            ['from' => 'other', 'to' => 'elsewhere', 'issued_at' => 2],
            ['from' => 'northwind', 'to' => 'globex', 'issued_at' => 3],
        ];
        $this->assertSame(['northwind', 'acme'], RegistryTrust::movedFrom($moves, 'globex'));
        $this->assertSame(['acme'], RegistryTrust::movedFrom($moves, 'northwind'));
        $this->assertSame([], RegistryTrust::movedFrom($moves, 'acme'));
        // a package that moved back and forth: each organisation once, never the current one, and no loop
        $this->assertSame(['acme'], RegistryTrust::movedFrom([['from' => 'acme', 'to' => 'northwind', 'issued_at' => 1], ['from' => 'northwind', 'to' => 'acme', 'issued_at' => 2], ['from' => 'acme', 'to' => 'northwind', 'issued_at' => 3]], 'northwind'));
    }
}
