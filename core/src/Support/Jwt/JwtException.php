<?php

declare(strict_types=1);

namespace Kestello\Support\Jwt;

/** A token is malformed, wrongly signed, expired or has unexpected claims. @api */
final class JwtException extends \RuntimeException {}
