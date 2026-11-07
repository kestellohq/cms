<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Contracts\Session\SessionStore;
use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Redis\RedisClient;
use Kestello\Framework\Session\AbstractSessionStore;
use Kestello\Framework\Session\DatabaseSessionStore;
use Kestello\Framework\Session\FailoverSessionStore;
use Kestello\Framework\Session\FileSessionStore;
use Kestello\Framework\Session\RedisSessionStore;
use Kestello\Framework\Session\SessionMetadata;
use Kestello\Framework\Testing\TestCase;

/** Session stores, driven directly through the handler interface. */
final class SessionStoreTest extends TestCase
{
    private ?int $user = null;

    /** @return array<string, SessionStore> */
    private function stores(int $lifetime = 3600): array
    {
        $stores = ['file' => new FileSessionStore($this->tempDir() . '/sessions', $lifetime)];

        $sqlite = Connection::sqliteMemory();
        $sqlite->schema()->create('sessions', function (Blueprint $t): void {
            $t->char('id', 64)->primary();
            $t->foreignId('user_id')->nullable();
            $t->string('ip', 45);
            $t->string('user_agent', 500);
            $t->longText('payload');
            $t->bigInteger('created_at');
            $t->bigInteger('last_activity');
        });
        $stores['database'] = new DatabaseSessionStore($sqlite, $lifetime);

        if (($url = (string) getenv('KESTELLO_TEST_REDIS')) !== '') {
            $stores['redis'] = new RedisSessionStore(RedisClient::fromConfig(['url' => $url]), 'kst-test:' . bin2hex(random_bytes(3)) . ':', $lifetime);
        }

        foreach ($stores as $store) {
            if ($store instanceof AbstractSessionStore) {
                $store->setMetadataProvider(fn() => new SessionMetadata($this->user, '203.0.113.7', 'TestAgent/1.0'));
            }
        }

        return $stores;
    }

    private static function id(): string
    {
        return bin2hex(random_bytes(24));
    }

    public function testReadWriteDestroyAndValidateId(): void
    {
        foreach ($this->stores() as $name => $s) {
            $id = self::id();
            $this->assertSame('', $s->read($id), "{$name}: unknown id reads empty");
            $this->assertFalse($s->validateId($id), "{$name}: unknown id is invalid (strict mode)");

            $this->assertTrue($s->write($id, 'a|i:1;'), $name);
            $this->assertSame('a|i:1;', $s->read($id), $name);
            $this->assertTrue($s->validateId($id), $name);
            $this->assertTrue($s->updateTimestamp($id, 'a|i:1;'), $name);

            $s->write($id, 'a|i:2;');
            $this->assertSame('a|i:2;', $s->read($id), "{$name}: overwritten");

            $this->assertTrue($s->destroy($id), $name);
            $this->assertSame('', $s->read($id), "{$name}: destroyed");
        }
    }

    public function testIdsAreStoredHashed(): void
    {
        $dir = $this->tempDir();
        $file = new FileSessionStore($dir);
        $id = self::id();
        $file->write($id, 'x|i:1;');
        $this->assertSame(['sess_' . hash('sha256', $id)], array_map('basename', glob($dir . '/sess_*')));

        $db = Connection::sqliteMemory();
        $db->schema()->create('sessions', function (Blueprint $t): void {
            $t->char('id', 64)->primary();
            $t->foreignId('user_id')->nullable();
            $t->string('ip', 45);
            $t->string('user_agent', 500);
            $t->longText('payload');
            $t->bigInteger('created_at');
            $t->bigInteger('last_activity');
        });
        (new DatabaseSessionStore($db))->write($id, 'x|i:1;');
        $this->assertSame([hash('sha256', $id)], $db->table('sessions')->pluck('id'));
    }

    public function testDeviceListAndRevocation(): void
    {
        foreach ($this->stores() as $name => $s) {
            if (!$s->tracksDevices()) {
                $this->assertSame([], $s->forUser(1), "{$name}: no device list");
                continue;
            }

            $this->user = null;
            $guest = self::id();
            $s->write($guest, 'g|i:1;');

            $this->user = 5;
            [$laptop, $phone] = [self::id(), self::id()];
            $s->write($laptop, 'u|i:5;');
            $s->write($phone, 'u|i:5;');
            $this->user = 6;
            $other = self::id();
            $s->write($other, 'u|i:6;');

            $devices = $s->forUser(5);
            $this->assertCount(2, $devices, $name);
            $this->assertSame(['203.0.113.7', 'TestAgent/1.0', 5], [$devices[0]['ip'], $devices[0]['user_agent'], $devices[0]['user_id']], $name);
            $this->assertFalse(in_array($laptop, array_column($devices, 'id'), true), "{$name}: raw ids never listed");

            // "Sign out everywhere else" from the laptop.
            $this->assertSame(1, $s->destroyForUser(5, SessionMetadata::hashId($laptop)), $name);
            $this->assertSame('', $s->read($phone), "{$name}: phone signed out");
            $this->assertSame('u|i:5;', $s->read($laptop), "{$name}: laptop kept");
            $this->assertSame('u|i:6;', $s->read($other), "{$name}: other user untouched");

            // Revoke one device by its listed id.
            $s->destroyHashed(SessionMetadata::hashId($laptop));
            $this->assertSame([], $s->forUser(5), $name);

            // Logging out (user id cleared) removes it from the user's list.
            $this->user = null;
            $s->write($other, 'guest-now|b:1;');
            $this->assertSame([], $s->forUser(6), "{$name}: re-indexed on logout");
            $this->assertSame('g|i:1;', $s->read($guest), $name);
        }
    }

    public function testExpiredSessionsAreNotRead(): void
    {
        foreach ($this->stores(lifetime: 1) as $name => $s) {
            $id = self::id();
            $s->write($id, 'x|i:1;');
            sleep(2);
            $this->assertSame('', $s->read($id), "{$name}: expired");
            $this->assertFalse($s->validateId($id), $name);
            $this->assertTrue($s->gc(1) !== false, $name);
        }
    }

    public function testFailoverSwitchesToFallback(): void
    {
        $broken = new DatabaseSessionStore(Connection::sqliteMemory()); // no sessions table
        $fallback = new FileSessionStore($this->tempDir());
        $logs = [];
        $store = new FailoverSessionStore($broken, $fallback, function (string $m) use (&$logs) {
            $logs[] = $m;
        });

        $id = self::id();
        $this->assertSame('', $store->read($id));
        $this->assertTrue($store->degraded());
        $this->assertTrue($store->write($id, 'x|i:1;'));
        $this->assertSame('x|i:1;', $fallback->read($id));
        $this->assertSame('file', $store->name());
        $this->assertCount(1, $logs);

        $breaker = $this->tempDir() . '/circuit/session';
        (new FailoverSessionStore($broken, $fallback, static fn() => null, $breaker))->read($id);
        $this->assertTrue(is_file($breaker), 'breaker tripped');
        $next = new FailoverSessionStore($broken, $fallback, function (string $m) use (&$logs) {
            $logs[] = $m;
        }, $breaker);
        $this->assertTrue($next->degraded(), 'next request skips the primary');
        $this->assertSame('x|i:1;', $next->read($id));
        $this->assertCount(1, $logs, 'no retry, no new log line');
    }
}
