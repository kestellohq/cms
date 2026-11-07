<?php

declare(strict_types=1);

namespace Kestello\Framework\Http\Client;

use RuntimeException;

/** A request failed: network error, timeout, or (HttpBlocked) refused by policy. @api */
class HttpException extends RuntimeException
{
    public function __construct(string $message, public readonly ?HttpResponse $response = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
