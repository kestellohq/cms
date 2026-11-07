<?php

declare(strict_types=1);

namespace Kestello\Contracts\Queue;

/** A job a worker holds until it deletes or releases it. @api */
final readonly class ReservedJob
{
    public function __construct(
        public string $id,
        public string $queue,
        public string $payload,
        public int $attempts,           // including this one
        public string $reservation,     // token proving this worker holds it
    ) {}
}
