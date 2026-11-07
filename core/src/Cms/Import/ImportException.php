<?php

declare(strict_types=1);

namespace Kestello\Cms\Import;

use RuntimeException;

/** An import that cannot go on: the file is not an export, it is broken, or a run was refused. */
final class ImportException extends RuntimeException {}
