<?php

declare(strict_types=1);

namespace Kestello\Contracts\Queue;

/**
 * A unit of background work. The constructor arguments are the payload
 * (stored as JSON, so they must be scalars, null or arrays of those), and
 * each must be readable as a public property of the same name; promoted
 * `public readonly` parameters do this for free:
 *
 *   final class SendDigest implements Job
 *   {
 *       public function __construct(public readonly int $userId) {}
 *       public function handle(Mailer $mail): void { ... }          // dependencies injected
 *
 *       // all optional:
 *       public function retries(): int { return 3; }                 // retries after the first attempt
 *       public function backoff(int $attempt): int { return 60; }   // seconds before retry $attempt
 *       public function timeout(): int { return 60; }               // seconds per attempt
 *       public function uniqueKey(): ?string { return "digest:{$this->userId}"; } // skip duplicates while pending
 *   }
 *
 *   $cms->queue->job(SendDigest::class);   // register once (plugins: in boot())
 *   $cms->queue->push(new SendDigest(5));
 *
 * @api
 */
interface Job {}
