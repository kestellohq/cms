<?php

declare(strict_types=1);

// Router for ResponseSendTest under PHP's built-in server: sends a Response with the
// status and the one header named in the query string, through Response::send().

require __DIR__ . '/../../../core/src/Framework/Core/Autoloader.php';
$autoloader = new Kestello\Framework\Core\Autoloader();
$autoloader->addNamespace('Kestello\\', __DIR__ . '/../../../core/src');
$autoloader->register();

$status = (int) ($_GET['status'] ?? 200);
$header = explode(':', (string) ($_GET['header'] ?? ''), 2);

$response = new Kestello\Framework\Core\Response('{}', $status);
if (count($header) === 2) {
    $response = $response->withHeader(trim($header[0]), trim($header[1]));
}
$response->send();
