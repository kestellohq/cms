<?php

declare(strict_types=1);

/*
 * File storage defaults. Sites override disks in config/storage.php; a disk
 * entry with a different driver replaces the default, otherwise its keys
 * are merged in. Check a disk with: php bin/cms storage:test media
 *
 *   // config/storage.php
 *   return [
 *       'disks' => [
 *           'media' => [
 *               'driver' => 's3',
 *               'bucket' => 'my-site-media',
 *               'region' => 'auto',                                   // R2
 *               'endpoint' => 'https://<account>.r2.cloudflarestorage.com',
 *               'key' => getenv('MEDIA_S3_KEY'),
 *               'secret' => getenv('MEDIA_S3_SECRET'),
 *               'url' => 'https://media.example.com',                 // public bucket / CDN
 *           ],
 *           'backups' => ['driver' => 's3', 'bucket' => 'backups', 'endpoint' => 'http://minio:9000', 'path_style' => true, ...],
 *           'staging-media' => ['driver' => 'readonly', 'disk' => 'media'],
 *           'media-both' => ['driver' => 'mirror', 'disks' => ['media', 'media-r2']],  // writes to both, reads the first
 *           'media-fast' => ['driver' => 'cached', 'disk' => 'media-r2', 'ttl' => 3600], // remembers sizes and types
 *       ],
 *       'sendfile' => 'x-accel-redirect',   // or 'x-sendfile'; null = PHP streams files
 *   ];
 *
 * Core disks: media (local storage/app/media, served at /uploads), public
 * (storage/app/public, served at /files), private, backups. The media
 * library can move to any disk with a public "url": System → File storage,
 * or php bin/cms media:move --to=<disk>.
 */
return [
    'disks' => [],
    'sendfile' => null,
    'sendfile_prefix' => '/protected-storage',
];
