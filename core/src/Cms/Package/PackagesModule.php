<?php

declare(strict_types=1);

namespace Kestello\Cms\Package;

use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\Package\Update\CoreUpdater;
use Kestello\Cms\Package\Update\HealthProbe;
use Kestello\Cms\Package\Update\HttpProbe;
use Kestello\Cms\Package\Update\ProcessProbe;
use Kestello\Cms\Package\Update\UpdateChecker;
use Kestello\Cms\Package\Update\UpdateProbe;
use Kestello\Cms\Package\Update\Updater;
use Kestello\Cms\System\Maintenance;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Settings\Settings;

/**
 * Plugins screen, package installs, plugin/theme/core updates.
 *
 * Config (config/app.php):
 *   updates.health      auto (default: a PHP process when proc_open works, else HTTP) | process | http
 *   updates.php         the PHP CLI binary for the process check (default: found from the running PHP)
 *   updates.health_url  where the server reaches the site for the HTTP check (default: app.url)
 *
 * New Kestello versions come from GitHub releases (config/release.php: repository, check_every,
 * prereleases, keys); UpdateChecker finds and downloads them, CoreUpdater reviews and applies them.
 */
final class PackagesModule extends Provider
{
    /** Settings key holding the last update check (UpdateChecker::check()). */
    public const string LAST_CHECK = 'updates.core_check';

    public function register(Container $container): void
    {
        $container->singleton(PackageInstaller::class, static fn(): PackageInstaller => new PackageInstaller(self::cms()));
        $container->singleton(HealthProbe::class, static function (Container $c): HealthProbe {
            $cms = self::cms();
            $mode = (string) $cms->config->get('app.updates.health', 'auto');
            $process = new ProcessProbe($cms->paths->root(), $cms->config->get('app.updates.php'));
            if ($mode === 'process' || ($mode === 'auto' && $process->available())) {
                return $process;
            }

            return new HttpProbe($c->make(HttpClient::class), (string) ($cms->config->get('app.updates.health_url') ?: $cms->config->get('app.url', '')));
        });
        $container->singleton(CoreUpdater::class, static fn(Container $c): CoreUpdater => new CoreUpdater(
            self::cms(),
            $c->make(PackageInstaller::class),
            $c->make(Backups::class),
            $c->make(HealthProbe::class),
            releaseKeys: UpdateChecker::releaseKeys(self::cms()->config->get('release.keys', [])),
        ));
        $container->singleton(UpdateChecker::class, static fn(Container $c): UpdateChecker => new UpdateChecker(
            $c->make(HttpClient::class),
            (array) self::cms()->config->get('release', []),
            self::cms()->version(),
            self::cms()->paths->storage('tmp/updates'),
            static function (): ?array {
                $value = json_decode((string) Settings::getInstance()->get(self::LAST_CHECK, ''), true);
                return is_array($value) ? $value : null;
            },
            static fn(array $result) => Settings::getInstance()->set(self::LAST_CHECK, (string) json_encode($result, JSON_UNESCAPED_SLASHES), 'updates'),
        ));
        $container->singleton(Updater::class, static fn(Container $c): Updater => new Updater(self::cms(), $c->make(PackageInstaller::class), $c->make(Backups::class), $c->make(HealthProbe::class)));
    }

    public function boot(Cms $cms): void
    {
        $cms->auth->roles->capability('plugins.manage', 'Install, update, activate, deactivate and delete plugins', sensitive: true);
        $cms->auth->roles->capability('core.update', 'Update Kestello itself', sensitive: true);
        $cms->admin->page('system/updates', '@core/admin/system/Updates', 'Updates', 'core.update', ['label' => 'Updates', 'icon' => 'arrows-clockwise', 'group' => 'system', 'order' => 14]);
        $cms->admin->page('system/plugins', '@core/admin/system/Plugins', 'Plugins', 'plugins.manage', ['label' => 'Manage plugins', 'icon' => 'plug', 'group' => 'plugins', 'order' => 0]);

        // new Kestello versions: asked of GitHub at most every release.check_every hours; nothing is installed
        $cms->schedule->call(static function () use ($cms): string {
            $checker = $cms->container->make(UpdateChecker::class);
            if (!$checker->due()) {
                return 'Not due yet.';
            }
            $result = $checker->check();

            return $result['error'] ?? ($result['latest'] !== null ? "Kestello {$result['latest']['version']} is available." : 'Kestello is up to date.');
        }, 'updates.check')->hourlyAt(17)->withoutOverlapping(30)
            ->description('Asks GitHub whether a new Kestello version is out (config/release.php). It only tells you; Updates installs.');

        // the HTTP health check's view of a package; only during an update, only with its bypass token
        $cms->routes->get('/system/update/probe', static function (Request $request) use ($cms): Response {
            $state = (new Maintenance($cms->paths->storage()))->current();
            $token = $request->header('X-Kestello-Bypass');
            $owner = (string) ($request->query['owner'] ?? '');
            if ($state === null || $token === '' || !hash_equals($state['bypass'], hash('sha256', $token)) || preg_match(UpdateProbe::OWNER, $owner) !== 1) {
                return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }

            $failed = UpdateProbe::prepare($cms, $owner);

            return new Response((string) json_encode($failed !== null ? ['probe_error' => $failed] : UpdateProbe::report($cms, $owner, pages: false)), 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
        })->name('system.update.probe');
    }

    private static function cms(): Cms
    {
        return Cms::instance() ?? throw new \RuntimeException('Not booted.');
    }
}
