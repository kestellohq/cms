<?php

declare(strict_types=1);

namespace Kestello\Framework\Http\Client;

/**
 * The response was larger than the request's max_bytes; reading stopped there.
 *
 * @api
 */
final class HttpTooLarge extends HttpException {}
