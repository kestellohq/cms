<?php

declare(strict_types=1);

namespace Kestello\Contracts\Media;

use RuntimeException;

/** An image that could not be decoded, transformed or encoded. @api */
class ImageException extends RuntimeException {}
