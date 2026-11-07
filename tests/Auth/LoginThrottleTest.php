<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Auth\LoginThrottle;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Framework\Testing\TestCase;

final class LoginThrottleTest extends TestCase
{
    public function testAttemptsInFlightAreCountedBeforeTheSecretIsChecked(): void
    {
        $t = new LoginThrottle(new RateLimiter(new ArrayStore()));
        // Six requests arrive together: none has finished checking its password yet.
        $waits = array_map(static fn() => $t->reserve('login', '203.0.113.7', 'user:1'), range(1, 6));
        $this->assertSame([0, 0, 0, 0, 0], array_slice($waits, 0, 5));
        $this->assertTrue($waits[5] > 0, 'the sixth is refused');
    }

    public function testSuccessesDoNotUseUpTheIpLimit(): void
    {
        $t = new LoginThrottle(new RateLimiter(new ArrayStore()));
        foreach (range(1, 20) as $i) {
            $this->assertSame(0, $t->reserve('login', '203.0.113.7', "user:{$i}"), "colleague {$i}");
            $t->succeeded('login', '203.0.113.7', "user:{$i}");
        }
    }

    public function testSomeoneGuessingFromOneAddressDoesNotLockTheOwnerOut(): void
    {
        $t = new LoginThrottle(new RateLimiter(new ArrayStore()));
        // An attacker hammers the admin account from one address, well past its limits.
        foreach (range(1, 40) as $_) {
            $t->reserve('login', '198.51.100.66', 'user:1');
        }
        $this->assertTrue($t->blockedFor('login', '198.51.100.66', 'user:1') > 0, 'the attacker is stopped');
        $this->assertSame(0, $t->reserve('login', '203.0.113.7', 'user:1'), 'the owner, from their own address, can still sign in');
    }

    public function testGuessesSpreadOverManyAddressesAreStillStopped(): void
    {
        $t = new LoginThrottle(new RateLimiter(new ArrayStore()));
        $refused = 0;
        foreach (range(1, 60) as $n) {          // 60 addresses, 4 guesses each: under every per-address limit
            foreach (range(1, 4) as $_) {
                $refused += $t->reserve('login', "192.0.2.{$n}", 'user:1') > 0 ? 1 : 0;
            }
        }
        $this->assertTrue($refused > 100, "the account as a whole has a limit too ({$refused} refused)");
    }
}
