<?php

declare(strict_types=1);

namespace Kestello\Framework\Http\Client;

/**
 * Sends one request (no redirects: the client follows them itself, so every
 * hop is re-checked). Tests swap in FakeTransport.
 *
 * @api
 */
interface Transport
{
    /** @throws HttpException on network errors, timeouts, or a body over $request->maxBytes */
    public function send(PendingRequest $request): HttpResponse;
}
