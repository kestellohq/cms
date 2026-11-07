<?php

declare(strict_types=1);

/*
 * Kestello front controller. Keep this file tiny: it is part of the site,
 * not the core, so core updates never replace it.
 *
 * Production installs run a versioned core (core/current.php returns the
 * active release, e.g. "1.4.2"); development runs core/ directly.
 */

$root = dirname(__DIR__);
$core = is_file($root . '/core/current.php')
    ? $root . '/core/releases/' . (require $root . '/core/current.php')
    : $root . '/core';

require $core . '/bootstrap.php';

Kestello\Kernel::create($root)->handleHttp();
