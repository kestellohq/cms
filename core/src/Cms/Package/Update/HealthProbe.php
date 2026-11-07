<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

/**
 * Checks the site after an update's files are in place, from a fresh process
 * (the updating one still holds the old code): the package's pending
 * upgrade runs on that boot, then the home page and the status endpoint are
 * requested. Returns the probe report (UpdateProbe::report()).
 */
interface HealthProbe
{
    /** @return array<string, mixed> */
    public function run(string $owner, string $bypass): array;
}
