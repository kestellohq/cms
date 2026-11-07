<?php

declare(strict_types=1);

namespace Kestello\Framework\Http\Client;

/** Refused before sending: private address, undeclared host, bad scheme or port. @api */
final class HttpBlocked extends HttpException {}
