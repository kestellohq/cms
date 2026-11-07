<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use RuntimeException;

/** A bearer token was sent but no guard accepted it. */
final class InvalidCredentials extends RuntimeException {}
