<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * A background job used up its attempts, or could not be built, and was
 * moved to the failed jobs list.
 *
 * @api
 */
final readonly class JobFailed
{
    /**
     * @param string $job The job class.
     * @param string $queue The queue it ran on.
     * @param string $owner `core`, or the plugin that pushed it, such as `plugin:acme`.
     * @param \Throwable $exception The last error.
     */
    public function __construct(
        public string $job,
        public string $queue,
        public string $owner,
        public \Throwable $exception,
    ) {}
}
