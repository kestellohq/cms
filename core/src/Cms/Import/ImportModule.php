<?php

declare(strict_types=1);

namespace Kestello\Cms\Import;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\Import\WordPress\OldWordPressLinks;
use Kestello\Cms\Import\WordPress\RunImport;
use Kestello\Cms\Import\WordPress\WordPressImports;
use Kestello\Framework\Core\Container;

/** Core module: bringing a site over from WordPress (System → Import, `bin/cms import:wordpress`). */
final class ImportModule extends Provider
{
    public function register(Container $container): void
    {
        $container->singleton(WordPressImports::class, static fn(): WordPressImports => new WordPressImports(Cms::instance() ?? throw new \RuntimeException('Not booted.')));
    }

    public function boot(Cms $cms): void
    {
        $cms->auth->roles->capability('import.run', 'Import a site from WordPress', sensitive: true);
        $cms->admin->page('system/import', '@core/admin/system/Import', 'Import', 'import.run', ['label' => 'Import', 'icon' => 'arrow-square-in', 'group' => 'system', 'order' => 18]);
        $cms->queue->job(RunImport::class);
        // before the chosen home page, which answers "/" itself (ContentModule::finalize adds it after every module)
        $cms->routes->pushGlobalMiddleware(new OldWordPressLinks());
    }
}
