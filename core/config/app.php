<?php

declare(strict_types=1);

/**
 * Core defaults for the "app" config group.
 *
 * The site's config/app.php (written by the installer) overrides these
 * key by key. Never edit this file on a live site: core updates replace it.
 */
return [
    'name' => 'Kestello',
    'description' => '',
    'url' => '',
    'timezone' => 'UTC',
    'locale' => 'en',            // admin and site language (language packs in resources/lang)
    'fallback_locale' => 'en',
    'debug' => false,
    'secret_key' => '',

    // A public demo: everyone signs in as "demo", only content, design, menus,
    // forms and images can be changed, nothing is emailed, and the site goes back
    // to its snapshot every hour (Kestello\Cms\Demo). Only the server can turn it off.
    'demo' => false,

    // Reverse proxies allowed to set X-Forwarded-For (IPs or CIDR ranges).
    // Empty = the client IP is always the connecting address.
    'trusted_proxies' => [],

    // Package zips (plugins and themes with code). trusted_keys: publishers whose
    // signed plugins and themes the admin may install (never core: see release.keys), as a list of
    // ['name' => 'Acme Plugins', 'public_key' => '<base64 Ed25519 key from package:keygen>'].
    // admin_unsigned: let the admin install unsigned packages too (development sites).
    // The CLI (plugin:install <zip>) always may: whoever runs it can change code anyway.
    'packages' => [
        'trusted_keys' => [],
        'admin_unsigned' => false,
    ],

    // The update pipeline's health check after new files are in place.
    // health: auto (a fresh PHP process when proc_open works, else HTTP) | process | http
    // php: the PHP CLI binary for the process check (null: found from the running PHP)
    // health_url: where the server reaches the site for the HTTP check (null: app.url)
    // check_every: hours between checks of the sources plugins and themes name for
    //   their updates ("updates" in their manifest); 0 turns the scheduled check off
    // prereleases: offer plugin and theme pre-releases (1.2.0-beta.1) too
    'updates' => [
        'health' => 'auto',
        'php' => null,
        'health_url' => null,
        'check_every' => 12,
        'prereleases' => false,
    ],

    // Saved page HTML, for sites without the Node renderer below: pages go out as
    // real HTML once an administrator's browser has drawn them (in the background
    // of the admin). Off on a public demo, and when ssr.url is set.
    'prerender' => true,

    // Optional server-side rendering of Vue pages (core/ssr/server.mjs).
    // null = client-side rendering only. Failures always fall back to the client.
    'ssr' => [
        'url' => getenv('KESTELLO_SSR_URL') ?: null,
        'timeout' => 1.5,
    ],
];
