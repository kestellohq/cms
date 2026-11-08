<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Framework\Core\Container;

/** Core module: the media library, image sizes and variants, the admin screen, cleanup tasks. */
final class MediaModule extends Provider
{
    public function register(Container $container): void
    {
        $container->singleton(MediaLibrary::class, static fn() => new MediaLibrary(Cms::instance()));
        $container->singleton(MediaVariants::class, static fn() => new MediaVariants(Cms::instance()));
        $container->singleton(MediaEditor::class, static fn() => new MediaEditor(Cms::instance()));
        $container->singleton(MediaStorage::class, static fn() => new MediaStorage(Cms::instance()));
    }

    public function boot(Cms $cms): void
    {
        $cms->media->size('thumb', 320, 320, 'cover', widths: [320]);
        $cms->media->size('medium', 960);
        $cms->media->size('large', 1920);
        $engine = $cms->config->get('media.engine');
        $cms->media->useEngine(is_string($engine) && $engine !== '' && $engine !== 'auto' ? $engine : null);

        $cms->queue->job(GenerateVariants::class);
        $cms->queue->job(MoveMediaFiles::class);
        $cms->routes->get(MediaVariants::ROUTE . '/{id}/{name}', [VariantController::class, 'show'])
            ->where(['id' => '\\d+', 'name' => '[a-z0-9_.-]+'])
            ->name('media.variant');

        $cms->health->check('media.images', 'Image processing', static function () use ($cms): \Kestello\Cms\Health\HealthResult {
            $engine = $cms->media->activeEngine();
            if ($engine === null) {
                return \Kestello\Cms\Health\HealthResult::recommended('No image engine is installed, so images are served as uploaded: no smaller sizes, no WebP or AVIF, and photos keep their location data.', [], null);
            }
            $formats = array_values(array_filter(['webp', 'avif'], static fn($f) => $engine->writes($f)));
            $tools = Optimizer::tools();
            $details = [
                'Engine' => $engine->name(),
                'Modern formats' => $formats === [] ? 'none' : strtoupper(implode(', ', $formats)),
                'Lossless optimizers' => $tools === [] ? 'none installed' : implode(', ', array_unique($tools)) . (Optimizer::enabled() ? '' : ' (turned off)'),
                'PDF thumbnails' => PdfPoster::binary() !== null ? 'Ghostscript' : 'no (Ghostscript isn\'t installed)',
            ];
            if (!in_array('webp', $formats, true)) {
                return \Kestello\Cms\Health\HealthResult::recommended("Images are resized with {$engine->name()}, but it can't write WebP, so pages are heavier than they need to be.", $details);
            }

            return \Kestello\Cms\Health\HealthResult::good('Images are resized with ' . $engine->name() . ' and served as ' . strtoupper(implode(' and ', $formats)) . ' where browsers support it.', $details);
        }, category: 'performance');

        $roles = $cms->auth->roles;
        foreach (['editor', 'author', 'contributor'] as $role) {
            $roles->grant($role, 'media.upload');
        }
        $roles->grant('editor', 'media.manage');

        $cms->admin->page('media', '@core/admin/media/Index', 'Media', 'admin.access', ['label' => 'Media', 'icon' => 'images', 'group' => 'content', 'order' => 800]);
        $cms->admin->page('system/storage', '@core/admin/system/Storage', 'File storage', 'system.manage', ['label' => 'File storage', 'icon' => 'hard-drive', 'group' => 'system', 'order' => 80]);

        $cms->schedule->call(static fn() => $cms->container->make(MediaLibrary::class)->purgeExpired() . ' unfinished uploads removed.', 'media.purge-uploads')
            ->hourly()->description('Removes uploads nobody finished within a day.');
    }
}
