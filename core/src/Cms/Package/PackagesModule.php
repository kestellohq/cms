<?php

declare(strict_types=1);

namespace Kestello\Cms\Package;

use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\Package\Registry\Registry;
use Kestello\Cms\Package\Registry\RegistryClient;
use Kestello\Cms\Package\Registry\RegistryPackages;
use Kestello\Cms\Package\Registry\RegistryTrust;
use Kestello\Cms\Package\Registry\RootKeys;
use Kestello\Cms\Package\Update\CoreUpdater;
use Kestello\Cms\Package\Update\HealthProbe;
use Kestello\Cms\Package\Update\HttpProbe;
use Kestello\Cms\Package\Update\KeyPins;
use Kestello\Cms\Package\Update\PackageLicenses;
use Kestello\Cms\Package\Update\PackageUpdates;
use Kestello\Cms\Package\Update\ProcessProbe;
use Kestello\Cms\Package\Update\UpdateChecker;
use Kestello\Cms\Package\Update\UpdateProbe;
use Kestello\Cms\Package\Update\Updater;
use Kestello\Cms\Package\Update\UpdateRecovery;
use Kestello\Cms\System\Maintenance;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\AppKey;
use Kestello\Support\Clock;

/**
 * Plugins screen, package installs, plugin/theme/core updates.
 *
 * Config (config/app.php):
 *   updates.health      auto (default: a PHP process when proc_open works, else HTTP) | process | http
 *   updates.php         the PHP CLI binary for the process check (default: found from the running PHP)
 *   updates.check_every hours between checks of plugin and theme update sources (12; 0 = off)
 *   updates.prereleases offer plugin and theme pre-releases too (false)
 *   updates.health_url  where the server reaches the site for the HTTP check (default: app.url)
 *
 * New Kestello versions come from GitHub releases (config/release.php: repository, check_every,
 * prereleases, keys); UpdateChecker finds and downloads them, CoreUpdater reviews and applies them.
 */
final class PackagesModule extends Provider
{
    /** Settings key holding the last update check (UpdateChecker::check()). */
    public const string LAST_CHECK = 'updates.core_check';

    /** Settings key holding the last answers about plugin and theme updates (PackageUpdates). */
    public const string PACKAGE_CHECKS = 'updates.package_checks';

    /** Settings key holding where the site's copy of the registry stands (RegistryClient). */
    public const string REGISTRY_STATE = 'updates.registry';

    public function register(Container $container): void
    {
        $container->singleton(PackageInstaller::class, static fn(Container $c): PackageInstaller => new PackageInstaller(self::cms(), $c->make(RegistryTrust::class)));
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
        $container->singleton(KeyPins::class, static fn(): KeyPins => KeyPins::inSettings());
        $container->singleton(RegistryClient::class, static function (Container $c): RegistryClient {
            $cms = self::cms();

            return new RegistryClient(
                $c->make(HttpClient::class),
                (string) $cms->config->get('registry.url', ''),
                RootKeys::fromConfig($cms->config->get('registry.root_keys', [])),
                $cms->paths->storage('registry'),
                static function (): array {
                    $value = json_decode((string) Settings::getInstance()->get(self::REGISTRY_STATE, ''), true);

                    return is_array($value) ? $value : [];
                },
                static fn(array $state) => Settings::getInstance()->set(self::REGISTRY_STATE, (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updates'),
                static fn(): int => Clock::timestamp(),
            );
        });
        $container->singleton(Registry::class, static fn(Container $c): Registry => new Registry(
            (bool) self::cms()->config->get('registry.enabled', true) ? $c->make(RegistryClient::class) : null,
        ));
        $container->singleton(PackageLicenses::class, static fn(): PackageLicenses => new PackageLicenses(
            AppKey::secretStore(),
            static fn(string $owner): ?string => Settings::getInstance()->get(self::settingsKey('license', $owner)),
            static function (string $owner, ?string $json): void {
                $json === null ? Settings::getInstance()->delete(self::settingsKey('license', $owner)) : Settings::getInstance()->set(self::settingsKey('license', $owner), $json, 'updates');
            },
        ));
        $container->singleton(PackageUpdates::class, static function (Container $c): PackageUpdates {
            $cms = self::cms();

            return new PackageUpdates(
                $c->make(HttpClient::class),
                $c->make(PackageLicenses::class),
                static fn(): array => self::installedPackages($cms),
                $cms->version(),
                PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
                (string) $cms->config->get('app.url', ''),
                ['check_every' => $cms->config->get('app.updates.check_every', 12), 'prereleases' => $cms->config->get('app.updates.prereleases', false)],
                $cms->paths->storage('tmp/updates'),
                static function (): array {
                    $value = json_decode((string) Settings::getInstance()->get(self::PACKAGE_CHECKS, ''), true);

                    return is_array($value) ? $value : [];
                },
                static fn(array $results) => Settings::getInstance()->set(self::PACKAGE_CHECKS, (string) json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updates'),
                registry: self::registryPackages($c),
            );
        });
        $container->singleton(RegistryTrust::class, static fn(Container $c): RegistryTrust => new RegistryTrust(
            static fn(): ?RegistryClient => (bool) self::cms()->config->get('registry.enabled', true) ? $c->make(RegistryClient::class) : null,
        ));
        $container->singleton(Updater::class, static fn(Container $c): Updater => new Updater(self::cms(), $c->make(PackageInstaller::class), $c->make(Backups::class), $c->make(HealthProbe::class)));
        $container->singleton(UpdateRecovery::class, static fn(Container $c): UpdateRecovery => new UpdateRecovery(self::cms(), $c->make(Backups::class)));
    }

    public function boot(Cms $cms): void
    {
        $cms->auth->roles->capability('plugins.manage', 'Install, update, activate, deactivate and delete plugins', sensitive: true);
        $cms->auth->roles->capability('core.update', 'Update Kestello, and plugins and themes from their publishers', sensitive: true);
        $cms->admin->page('system/updates', '@core/admin/system/Updates', 'Updates', 'core.update', ['label' => 'Updates', 'icon' => 'arrows-clockwise', 'group' => 'site', 'order' => 20]);
        $cms->admin->page('system/plugins', '@core/admin/system/Plugins', 'Plugins', 'plugins.manage', ['label' => 'Manage plugins', 'icon' => 'plug', 'group' => 'plugins', 'order' => 0]);
        $cms->admin->page('system/plugins/add', '@core/admin/system/AddPlugins', 'Add plugins', 'plugins.manage', ['label' => 'Add new', 'icon' => 'plus', 'group' => 'plugins', 'order' => 1]);

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

        // new plugin and theme versions, from where each manifest says ("updates"); nothing is installed
        $cms->schedule->call(static function () use ($cms): string {
            $results = $cms->container->make(PackageUpdates::class)->check(dueOnly: true);
            $offers = array_filter($results, static fn(array $r): bool => $r['offer'] !== null);
            $errors = array_filter($results, static fn(array $r): bool => $r['error'] !== null);

            return count($offers) . ' update(s) available' . ($errors !== [] ? ', ' . count($errors) . ' source(s) could not be checked' : '') . '.';
        }, 'updates.packages')->hourlyAt(27)->withoutOverlapping(30)
            ->description('Asks the sources plugins and themes name ("updates" in their manifest) for new versions. It only tells you; Updates installs.');

        // the plugin and theme registry's list: fetched as often as update sources are checked; installs nothing
        $cms->schedule->call(static function () use ($cms): string {
            if (!(bool) $cms->config->get('registry.enabled', true)) {
                return 'The registry is turned off (registry.enabled).';
            }
            $client = $cms->container->make(RegistryClient::class);
            $hours = max(1, (int) $cms->config->get('app.updates.check_every', 12));
            $last = $client->status()['checked_at'];
            if ($last !== null && Clock::timestamp() - $last < $hours * 3600 - 300) {
                return 'Not due yet.';
            }
            $status = $client->refresh();

            return $status['error'] ?? "The registry lists {$status['packages']} package(s).";
        }, 'updates.registry')->hourlyAt(37)->withoutOverlapping(30)
            ->description('Fetches the plugin and theme registry\'s signed list (config/registry.php) and checks it. It only reads; nothing is installed.');

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

    /**
     * The installed plugins and themes, as PackageUpdates reads them.
     *
     * @return list<array{owner: string, type: string, slug: string, name: string, version: string, updates: array<string, mixed>|null}>
     */
    public static function installedPackages(Cms $cms): array
    {
        $pins = $cms->container->has(KeyPins::class) ? $cms->container->make(KeyPins::class) : KeyPins::inSettings();
        $out = [];
        foreach ([...array_values($cms->extensions()->plugins()), ...array_values($cms->extensions()->themes())] as $p) {
            // a package installed from the registry takes its updates from the registry (registry-format.md §5.2)
            $updates = $pins->organisation($p->owner()) !== null ? ['registry' => true] : $p->manifest->updates;
            $out[] = ['owner' => $p->owner(), 'type' => $p->manifest->type->value, 'slug' => $p->slug(), 'name' => $p->manifest->name, 'version' => $p->version(), 'updates' => $updates];
        }

        return $out;
    }

    /** Registry versions and downloads, or null when the registry is turned off. */
    public static function registryPackages(Container $c): ?RegistryPackages
    {
        $cms = self::cms();

        return (bool) $cms->config->get('registry.enabled', true) ? new RegistryPackages(
            $c->make(RegistryClient::class),
            $cms->version(),
            PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            $cms->paths->storage('tmp/updates'),
        ) : null;
    }

    /** "updates.key.plugin.events" for the owner "plugin:events". */
    public static function settingsKey(string $what, string $owner): string
    {
        return "updates.{$what}." . str_replace(':', '.', $owner);
    }

    private static function cms(): Cms
    {
        return Cms::instance() ?? throw new \RuntimeException('Not booted.');
    }
}
