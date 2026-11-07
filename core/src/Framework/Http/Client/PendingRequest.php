<?php

declare(strict_types=1);

namespace Kestello\Framework\Http\Client;

/**
 * A request that passed policy checks, ready for a transport. $ip is the
 * vetted address the transport must connect to (DNS-rebinding protection).
 *
 * @internal
 */
final readonly class PendingRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $method,
        public string $url,
        public string $host,
        public int $port,
        public ?string $ip,
        public array $headers,
        public ?string $body,
        public float $timeout,
        public float $connectTimeout,
        public int $maxBytes,
        public mixed $sink = null,        // resource: write the body here instead of into the response
        public mixed $bodyStream = null,  // resource: upload this instead of $body
        public ?int $bodyLength = null,
    ) {}
}
