<?php

declare(strict_types=1);

namespace Kestello\Cms\Storage;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;

/** Core module: routes that serve local disks. */
final class StorageModule extends Provider
{
    public function boot(Cms $cms): void
    {
        $cms->routes->get(SignedFileUrl::ROUTE, [StorageController::class, 'signed'])->name('storage.signed');
        foreach ($cms->storage->servedLocally() as $disk => $url) {
            if ($url === '' || in_array(explode('/', ltrim($url, '/'))[0], ['api', 'admin', 'system', 'assets', 'auth', 'login'], true)) {
                continue; // never shadow core routes
            }
            $cms->routes->get($url . '/{path}', static fn($request, string $path) => (new StorageController())->public($request, $disk, $path))
                ->where(['path' => '.+'])
                ->name('storage.' . $disk);
        }
    }
}
