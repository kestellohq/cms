<?php

declare(strict_types=1);

namespace Kestello\Framework\Http;

final readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $resetIn,
    ) {}

    /** @return array<string, string> RFC draft RateLimit-* headers. */
    public function headers(): array
    {
        $headers = [
            'RateLimit-Limit' => (string) $this->limit,
            'RateLimit-Remaining' => (string) $this->remaining,
            'RateLimit-Reset' => (string) $this->resetIn,
        ];

        if (!$this->allowed) {
            $headers['Retry-After'] = (string) max(1, $this->resetIn);
        }

        return $headers;
    }
}
