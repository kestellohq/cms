<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

/**
 * @api
 */
enum PackageType: string
{
    case Plugin = 'plugin';
    case Theme = 'theme';

    public function manifestFile(): string
    {
        return $this->value . '.json';
    }
}
