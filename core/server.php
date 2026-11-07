<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in web server (bin/cms serve). Existing files in
 * public/ are served as they are; every other address goes to index.php,
 * including addresses with a dot in them (/sitemap.xml, /feed.xml), which
 * the built-in server would otherwise answer with its own 404.
 */

$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$file = $_SERVER['DOCUMENT_ROOT'] . $path;

if ($path !== '/' && !str_contains($path, '..') && is_file($file)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';

require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
