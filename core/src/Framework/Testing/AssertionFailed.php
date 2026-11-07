<?php

declare(strict_types=1);

namespace Kestello\Framework\Testing;

use RuntimeException;

/**
 * Thrown by TestCase assertions. The runner reports it as a failure (F),
 * as opposed to any other throwable, which is reported as an error (E).
 */
final class AssertionFailed extends RuntimeException {}
