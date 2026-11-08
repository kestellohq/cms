<?php

/*
 * Holds the update lock the way an update does, starts a long "health
 * check" child process, then dies by SIGKILL like an updater the server
 * stopped. The child inherits the lock. Used by UpdateRecoveryTest.
 *
 *   php check-outlives-updater.php <storage> <seconds the check runs>
 */

declare(strict_types=1);

use Kestello\Cms\Package\Update\Files;

[, $storage, $seconds] = $argv;
require dirname(__DIR__, 3) . '/core/bootstrap.php';

Files::locked($storage, static function () use ($seconds): void {
    $null = ['file', '/dev/null', 'a'];
    proc_open([PHP_BINARY, '-r', 'sleep(' . (int) $seconds . ');'], [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes);
    posix_kill(posix_getpid(), 9);
});
