<?php

declare(strict_types=1);

/*
 * The plugin and theme registry (registry.kestello.com), and the root keys every registry file
 * is trusted through. A site's config/registry.php can override any of this (a mirror, or no
 * registry at all); never edit this file on a live site: core updates replace it.
 */
return [
    // false: Kestello never contacts the registry (installs by upload or from publisher feeds only)
    'enabled' => true,

    // the registry's files, or a mirror of them: mirrors need no keys of their own, because
    // everything is signed and checked against the root keys below
    'url' => 'https://registry.kestello.com/v1/',

    // the public halves of the registry's root keys: A signs, B is the spare, kept offline.
    // They sign only keys.json (the index keys and revocations) and organisation certificates.
    // Replacing a root key is the one registry change that needs a Kestello release.
    'root_keys' => [
        ['name' => 'Kestello root A', 'public_key' => 'RhbiRnOJyiLygoUVTVz8otZAoHlnJqquLelOxCIaVIw='],
        ['name' => 'Kestello root B (spare)', 'public_key' => '6xpEAcQD3oZcPy4UwR0Sbpt7PowlnQNurBL3uXwZH/Q='],
    ],
];
