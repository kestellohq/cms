<?php

declare(strict_types=1);

/**
 * Core entry point. Entry scripts (public/index.php, bin/cms) resolve the
 * active core directory and require this file. It only wires autoloading
 * for the Kestello\ namespace; Kestello\Kernel does the rest.
 */

// Before anything else is parsed: newer syntax would fail with a confusing error on an older PHP.
if (PHP_VERSION_ID < 80400) {
    $message = 'Kestello needs PHP 8.4 or newer; this server runs PHP ' . PHP_VERSION . '. Choose a newer PHP version in your hosting control panel.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    exit(1);
}

require_once __DIR__ . '/src/Framework/Core/Autoloader.php';

(static function (): void {
    $autoloader = new Kestello\Framework\Core\Autoloader();
    $autoloader->addNamespace('Kestello\\', __DIR__ . '/src');
    $autoloader->register();
})();

require_once __DIR__ . '/src/Support/functions.php';
