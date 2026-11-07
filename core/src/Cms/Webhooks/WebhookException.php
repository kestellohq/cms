<?php

declare(strict_types=1);

namespace Kestello\Cms\Webhooks;

use RuntimeException;

/** A webhook could not be saved (errors per field), or was not found (status 404). */
final class WebhookException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors, string $message = 'The webhook is not valid.', public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
