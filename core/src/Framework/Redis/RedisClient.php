<?php

declare(strict_types=1);

namespace Kestello\Framework\Redis;

/**
 * Minimal RESP2 client over a socket stream. Works with Redis, Valkey,
 * KeyDB, Dragonfly and managed services (TLS via tls: true / rediss://).
 *
 *   $redis = RedisClient::fromConfig(['host' => 'redis', 'password' => '…']);
 *   $redis->command('SET', 'k', 'v', 'EX', 60);
 *   [$a, $b] = $redis->pipeline([['GET', 'a'], ['GET', 'b']]);
 *
 * Connects lazily; reconnects once if the connection dropped between requests.
 *
 * @api
 */
final class RedisClient
{
    /** @var resource|null */
    private $stream = null;

    /**
     * @param array{host?: string, port?: int, username?: string, password?: string, database?: int,
     *              tls?: bool, timeout?: float, read_timeout?: float, verify_peer?: bool} $config
     */
    public function __construct(private readonly array $config = []) {}

    /**
     * Accepts host/port/... keys, or a "url" key: redis[s]://[user:pass@]host[:port][/db].
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        if (isset($config['url']) && is_string($config['url']) && $config['url'] !== '') {
            $parts = parse_url($config['url']);
            if ($parts === false || !in_array($parts['scheme'] ?? '', ['redis', 'rediss'], true)) {
                throw new RedisException('Redis URL must look like redis://host:6379 or rediss://user:pass@host:6380/0.');
            }
            $config += array_filter([
                'host' => $parts['host'] ?? null,
                'port' => $parts['port'] ?? null,
                'username' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
                'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
                'database' => isset($parts['path']) && trim($parts['path'], '/') !== '' ? (int) trim($parts['path'], '/') : null,
                'tls' => ($parts['scheme'] ?? '') === 'rediss' ? true : null,
            ], static fn($v) => $v !== null);
        }
        unset($config['url']);

        return new self($config);
    }

    /** Run one command and return its reply (strings, ints, null, nested lists). */
    public function command(string $name, string|int|float ...$args): mixed
    {
        return $this->send([[$name, ...$args]])[0];
    }

    /**
     * Send several commands in one round trip.
     *
     * @param list<list<string|int|float>> $commands
     * @return list<mixed> replies in order (error replies are returned as RedisException objects)
     */
    public function pipeline(array $commands): array
    {
        return $commands === [] ? [] : $this->send($commands, throwErrors: false);
    }

    /** Server-side Lua script (EVAL). */
    public function eval(string $script, array $keys = [], array $args = []): mixed
    {
        return $this->command('EVAL', $script, count($keys), ...$keys, ...$args);
    }

    /**
     * Iterate keys matching a pattern with SCAN (never KEYS).
     *
     * @return \Generator<int, string>
     */
    public function scan(string $match, int $count = 500): \Generator
    {
        $cursor = '0';
        do {
            [$cursor, $keys] = $this->command('SCAN', $cursor, 'MATCH', $match, 'COUNT', $count);
            foreach ((array) $keys as $key) {
                yield (string) $key;
            }
        } while ((string) $cursor !== '0');
    }

    public function ping(): bool
    {
        return $this->command('PING') === 'PONG';
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    // ── Protocol ────────────────────────────────────────────────────

    /**
     * @param list<list<string|int|float>> $commands
     * @return list<mixed>
     */
    private function send(array $commands, bool $throwErrors = true): array
    {
        $payload = '';
        foreach ($commands as $command) {
            $payload .= self::encodeCommand($command);
        }

        $reused = is_resource($this->stream);
        $this->connect();

        try {
            $this->write($payload);
        } catch (RedisConnectionLost $e) {
            $this->close();
            if (!$reused) {
                throw new RedisException('Redis connection lost: ' . $e->getMessage(), 0, $e);
            }
            // A reused socket that went stale between requests: nothing was
            // delivered, so reconnecting and resending once is safe.
            $this->connect();
            try {
                $this->write($payload);
            } catch (RedisConnectionLost $again) {
                $this->close();
                throw new RedisException('Redis connection lost: ' . $again->getMessage(), 0, $again);
            }
        }

        $replies = [];
        try {
            foreach ($commands as $_) {
                $replies[] = $this->read();
            }
        } catch (RedisConnectionLost $e) {
            $this->close(); // never resend after a partial exchange
            throw new RedisException('Redis connection lost: ' . $e->getMessage(), 0, $e);
        }

        if ($throwErrors) {
            foreach ($replies as $reply) {
                if ($reply instanceof RedisException) {
                    throw $reply;
                }
            }
        }

        return $replies;
    }

    /** @param list<string|int|float> $args */
    private static function encodeCommand(array $args): string
    {
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $arg = (string) $arg;
            $out .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        return $out;
    }

    private function connect(): void
    {
        if (is_resource($this->stream)) {
            return;
        }

        $c = $this->config;
        $tls = (bool) ($c['tls'] ?? false);
        $address = ($tls ? 'tls://' : 'tcp://') . ($c['host'] ?? '127.0.0.1') . ':' . (int) ($c['port'] ?? 6379);
        $context = stream_context_create($tls ? ['ssl' => [
            'verify_peer' => (bool) ($c['verify_peer'] ?? true),
            'verify_peer_name' => (bool) ($c['verify_peer'] ?? true),
            'SNI_enabled' => true,
        ]] : []);

        $stream = @stream_socket_client($address, $errno, $error, (float) ($c['timeout'] ?? 2.0), STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new RedisException("Cannot connect to Redis at {$address}: {$error} ({$errno})");
        }

        $readTimeout = (float) ($c['read_timeout'] ?? 5.0);
        stream_set_timeout($stream, (int) $readTimeout, (int) (fmod($readTimeout, 1) * 1e6));
        $this->stream = $stream;

        try {
            if (($c['password'] ?? '') !== '') {
                $auth = ($c['username'] ?? '') !== ''
                    ? self::encodeCommand(['AUTH', $c['username'], $c['password']])
                    : self::encodeCommand(['AUTH', $c['password']]);
                $this->write($auth);
                if (($reply = $this->read()) instanceof RedisException) {
                    throw new RedisException('Redis authentication failed: ' . $reply->getMessage());
                }
            }
            if ((int) ($c['database'] ?? 0) !== 0) {
                $this->write(self::encodeCommand(['SELECT', (int) $c['database']]));
                if (($reply = $this->read()) instanceof RedisException) {
                    throw $reply;
                }
            }
        } catch (\Throwable $e) {
            $this->close();
            throw $e instanceof RedisException ? $e : new RedisException($e->getMessage(), 0, $e);
        }
    }

    private function write(string $data): void
    {
        $remaining = $data;
        while ($remaining !== '') {
            $written = @fwrite($this->stream, $remaining);
            if ($written === false || $written === 0) {
                throw new RedisConnectionLost('write failed');
            }
            $remaining = substr($remaining, $written);
        }
    }

    private function read(): mixed
    {
        $line = $this->readLine();
        $type = $line[0];
        $body = substr($line, 1);

        switch ($type) {
            case '+':
                return $body;
            case '-':
                return new RedisException($body);
            case ':':
                return (int) $body;
            case '$':
                $length = (int) $body;
                if ($length === -1) {
                    return null;
                }
                $data = '';
                while (strlen($data) < $length + 2) {
                    $chunk = fread($this->stream, $length + 2 - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        $this->failIfTimedOut();
                        throw new RedisConnectionLost('connection closed while reading');
                    }
                    $data .= $chunk;
                }
                return substr($data, 0, $length);
            case '*':
                $count = (int) $body;
                if ($count === -1) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->read();
                }
                return $items;
            default:
                $this->close();
                throw new RedisException('Unexpected Redis reply: ' . substr($line, 0, 40));
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->stream);
        if ($line === false || $line === '') {
            $this->failIfTimedOut();
            throw new RedisConnectionLost('connection closed');
        }

        return rtrim($line, "\r\n");
    }

    private function failIfTimedOut(): void
    {
        if (is_resource($this->stream) && (stream_get_meta_data($this->stream)['timed_out'] ?? false)) {
            $this->close();
            throw new RedisException('Redis read timed out.');
        }
    }
}
