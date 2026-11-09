<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use RuntimeException;

/**
 * A vendor's feed refused the license key (or the site): the message to show, and where to
 * renew when the vendor gave a link PackageUpdates accepts.
 *
 * @internal
 */
final class LicenseRefused extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $renewUrl = null)
    {
        parent::__construct($message);
    }
}
