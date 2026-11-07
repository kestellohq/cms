<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

use Kestello\Cms\Cms;

/**
 * Core module: package and core asset serving.
 *
 *   /assets/plugins/{slug}/{path}   /assets/themes/{slug}/{path}   /assets/core/{path}
 */
final class ExtensionsModule extends Provider
{
    public function boot(Cms $cms): void
    {
        $cms->routes->get('/assets/plugins/{slug}/{path}', [AssetController::class, 'plugin'])
            ->where(['slug' => '[a-z0-9-]+', 'path' => '.+'])
            ->name('assets.plugin');
        $cms->routes->get('/assets/themes/{slug}/{path}', [AssetController::class, 'theme'])
            ->where(['slug' => '[a-z0-9-]+', 'path' => '.+'])
            ->name('assets.theme');
        $cms->routes->get('/assets/core/{path}', [AssetController::class, 'core'])
            ->where(['path' => '.+'])
            ->name('assets.core');

        $extensions = $cms->extensions();
        $cms->pages->share('recoveryMode', static fn(): bool => $extensions->isRecoveryMode());
    }
}
