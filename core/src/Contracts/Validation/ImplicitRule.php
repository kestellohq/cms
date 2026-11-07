<?php

declare(strict_types=1);

namespace Kestello\Contracts\Validation;

/** A rule that runs even when the field is missing or empty (like "required"). @api */
interface ImplicitRule extends Rule {}
