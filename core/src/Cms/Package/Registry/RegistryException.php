<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use UnexpectedValueException;

/**
 * A registry file that can't be trusted or read: refused whole. The message says which file and
 * why, in words an administrator can act on ("The registry's list has expired.").
 *
 * @api thrown by Registry, the registry's public reader
 */
final class RegistryException extends UnexpectedValueException {}
