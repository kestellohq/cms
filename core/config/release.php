<?php

declare(strict_types=1);

/*
 * Where Kestello's own updates come from, and the keys they are signed with.
 * A site's config/release.php can override any of this (a fork with its own releases);
 * never edit this file on a live site: core updates replace it.
 */
return [
    // GitHub repository whose releases are Kestello's versions (kestello-core-<version>.zip)
    'repository' => 'kestellohq/cms',

    // check for a new version this often, in hours (0: never; Updates can still check by hand)
    'check_every' => 12,

    // also offer pre-releases (1.5.0-beta.1): for test sites. A site already running a
    // pre-release is offered the later ones of that version (rc.1 -> rc.2 -> the release) anyway.
    'prereleases' => false,

    // the public halves of the keys official releases are signed with, from
    //   php bin/cms package:keygen --out=<file> --name="Kestello releases"
    // A core release is applied from the admin only when one of these signed it
    // (core:release --key). Keys in app.packages.trusted_keys sign plugins and themes,
    // never core. Rotate by adding the new key a release before signing with it, and
    // removing the old one a release after.
    'keys' => [],
];
