<?php

declare(strict_types=1);

/*
 * Core platform defaults (layer 4). Sites override these in config/platform.php
 * (pinned, read-only in the admin), with KESTELLO_<SUBSYSTEM>_<OPTION> env vars,
 * or in the admin under System → Infrastructure.
 *
 *   // config/platform.php
 *   return [
 *       'admin_editable' => false,                 // lock the whole screen
 *       'cache' => ['driver' => 'redis', 'host' => 'redis', 'prefix' => 'site1:'],
 *   ];
 */
return [
    'cache' => ['driver' => 'file'],
    'session' => ['driver' => 'database'],
];
