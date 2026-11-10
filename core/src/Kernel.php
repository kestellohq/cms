<?php

declare(strict_types=1);

namespace Kestello;

use Kestello\Cms\Admin\AdminModule;
use Kestello\Cms\Api\ApiGateway;
use Kestello\Cms\Api\ApiModule;
use Kestello\Cms\Api\ApiRegistry;
use Kestello\Cms\Auth\Auth;
use Kestello\Cms\Auth\AuthModule;
use Kestello\Cms\Auth\AuthRegistry;
use Kestello\Cms\Auth\Guards\SessionGuard;
use Kestello\Cms\Auth\Guards\TokenGuard;
use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Cms\Cms;
use Kestello\Cms\Console\Extensions\PackageActivateCommand;
use Kestello\Cms\Console\Extensions\PackageCheckCommand;
use Kestello\Cms\Console\Extensions\PackageInstallCommand;
use Kestello\Cms\Console\Extensions\PackageListCommand;
use Kestello\Cms\Console\Extensions\PluginConfigCommand;
use Kestello\Cms\Console\Extensions\PluginDeactivateCommand;
use Kestello\Cms\Console\Extensions\PluginUninstallCommand;
use Kestello\Cms\Console\Extensions\RecoveryLinkCommand;
use Kestello\Cms\Console\Make;
use Kestello\Cms\Console\Platform;
use Kestello\Cms\Console\TokenCreateCommand;
use Kestello\Cms\Events\KernelBooted;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\CommandRegistry;
use Kestello\Cms\Extension\DatabaseStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\ExtensionsModule;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Extension\Recovery;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Frontend\FrontendModule;
use Kestello\Cms\Installer\InstallerController;
use Kestello\Cms\Installer\InstallerRoutes;
use Kestello\Cms\Package\Update\UpdateJournal;
use Kestello\Cms\Package\Update\UpdateRecovery;
use Kestello\Cms\Platform\ArrayPlatformStore;
use Kestello\Cms\Platform\CoreDrivers;
use Kestello\Cms\Platform\DriverRegistry;
use Kestello\Cms\Platform\PlatformConfig;
use Kestello\Cms\Platform\PlatformManager;
use Kestello\Cms\Platform\SettingsPlatformStore;
use Kestello\Cms\View\Pages;
use Kestello\Contracts\Cache\Store as CacheStore;
use Kestello\Contracts\Security\SecretStore;
use Kestello\Contracts\Session\SessionStore;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Cache\FailoverStore;
use Kestello\Framework\Cache\FileStore;
use Kestello\Framework\Console\Application as Console;
use Kestello\Framework\Console\Commands;
use Kestello\Framework\Core\Application;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Router;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\Schema;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Filters;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\CorsMiddleware;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Framework\Http\TrustedProxies;
use Kestello\Framework\Security\Csrf;
use Kestello\Framework\Security\CsrfMiddleware;
use Kestello\Framework\Session\AbstractSessionStore;
use Kestello\Framework\Session\FailoverSessionStore;
use Kestello\Framework\Session\FileSessionStore;
use Kestello\Framework\Session\Session;
use Kestello\Framework\Session\SessionMetadata;
use Kestello\Framework\Testing\TestCommand;
use Throwable;

/**
 * Boots Kestello for a site.
 *
 * Entry scripts stay a few lines long and never change between core
 * versions; everything about *how* the application starts lives here,
 * so a core update can change the boot process without touching the site.
 *
 *     Kernel::create($siteRoot)->handleHttp();
 *     exit(Kernel::create($siteRoot)->handleConsole($argv));
 */
final class Kernel
{
    private readonly Paths $paths;

    private function __construct(string $root, string $core)
    {
        $this->paths = new Paths($root, $core);
    }

    public static function create(string $root): self
    {
        return new self($root, dirname(__DIR__));
    }

    /**
     * The running core version (from core/VERSION).
     */
    public static function version(): string
    {
        static $version = null;

        return $version ??= trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')) ?: 'dev';
    }

    public function paths(): Paths
    {
        return $this->paths;
    }

    // ── HTTP ────────────────────────────────────────────────────────

    private function maintenancePage(\Kestello\Cms\System\Maintenance $maintenance): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        header('Retry-After: 60');
        header('Cache-Control: no-store');
        echo $maintenance->page();
    }

    public function handleHttp(): void
    {
        $app = Application::getInstance($this->paths);
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $installed = InstallerController::isInstalled($this->paths);

        if (!$installed || str_starts_with($uri, '/install')) {
            $this->handleInstaller($app, $uri, $installed);
            return;
        }

        // An update PHP stopped halfway: put it back before anything else runs (for core this
        // request already runs the old release; plugins stay unloaded until it is done).
        $journal = new UpdateJournal($this->paths->storage());
        if ($journal->pending() !== null) {
            $this->recoverUpdate($app, $uri);
            return;
        }

        // before the database: during a restore it is being rebuilt
        $maintenance = new \Kestello\Cms\System\Maintenance($this->paths->storage());
        self::holdDuringUpdate($journal, $maintenance);
        $bypass = (string) ($_SERVER['HTTP_X_KESTELLO_BYPASS'] ?? '');
        if ($maintenance->blocks($uri, isset($_COOKIE[Session::NAME]), $bypass)) {
            $this->maintenancePage($maintenance);
            return;
        }

        $app->bootstrap();
        $this->connectDatabase($app);

        TrustedProxies::set((array) $app->config()->get('app.trusted_proxies', []));
        $session = new Session(['secure' => $this->isSecureRequest($app), 'lifetime' => $this->sessionLifetime($app)]);
        $app->container()->singleton(Session::class, fn(): Session => $session);
        Auth::setInstance(new Auth($session, bindToPassword: (bool) $app->config()->get('auth.revoke_on_password_change', true)));

        $recovery = $this->recoveryMode($app);
        if ($recovery === null) {
            return; // redirected to strip the token from the URL
        }

        $cms = $this->bootExtensions($app, $recovery);

        // The session starts after providers boot, so plugin session drivers
        // work. Providers must not read the session in boot() (use routes,
        // middleware or events instead).
        $session->setStore($this->sessionStore($cms, $app));
        if ($this->needsSession($uri, $session)) {
            $session->start();
        } elseif (!str_starts_with($uri, '/assets/') && !str_starts_with($uri, '/api/')) {
            $session->startOnWrite();     // guests browsing: no cookie until something is stored
        }
        $cms->container->instance(SessionStore::class, $session->store());

        // A session cookie got the request past the cheap check above; only a signed-in person may see the site.
        if ($maintenance->blocks($uri, false, $bypass) && Auth::getInstance()?->check() !== true) {
            $this->maintenancePage($maintenance);
            return;
        }

        // /system/cron authenticates with its own token (external cron services have no session).
        $app->router()->pushGlobalMiddleware(new CsrfMiddleware(new Csrf($session), ['/system/cron'], \Kestello\Cms\Api\ApiRegistry::PREFIX . '/'));
        $cms->events->dispatch(new KernelBooted(console: false, recoveryMode: $recovery));

        $app->run();
        \Kestello\Cms\System\WebRunner::afterResponse($cms);
    }

    /**
     * Put back (or finish) an update that stopped halfway, then send the
     * visitor to the page they asked for. Anything else gets the
     * maintenance page and tries again.
     */
    private function recoverUpdate(Application $app, string $uri): void
    {
        $result = null;
        try {
            $app->bootstrap();
            $this->connectDatabase($app);
            $cms = $this->bootExtensions($app, recovery: true);
            $result = $cms->container->make(UpdateRecovery::class)->recover();
            if (!$result['ok']) {
                error_log('[kestello] putting back an update that stopped: ' . implode(' ', $result['problems']));
            }
        } catch (Throwable $e) {
            error_log('[kestello] putting back an update that stopped: ' . $e->getMessage());
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (($result['ok'] ?? false) && ($method === 'GET' || $method === 'HEAD')) {
            header('Cache-Control: no-store');
            header('Location: ' . self::sameSiteLocation($uri, (string) ($_SERVER['QUERY_STRING'] ?? '')), true, 302);
            return;
        }
        $this->maintenancePage(new \Kestello\Cms\System\Maintenance($this->paths->storage()));
    }

    /**
     * While an update is running, or stopped and was not put back, visitors
     * never see the site halfway, however long it takes: the maintenance the
     * update turned on outlasts its 30 minutes. Signed-in people still get in.
     */
    public static function holdDuringUpdate(UpdateJournal $journal, \Kestello\Cms\System\Maintenance $maintenance): void
    {
        if ($journal->current() !== null) {
            $maintenance->hold('an update has not finished');
        }
    }

    /** A path on this site to send the visitor back to; never one a browser would read as another host ("//x", "/\\x"). */
    public static function sameSiteLocation(string $path, string $query = ''): string
    {
        if (preg_match('#^/(?![/\\\\])[^\x00-\x20]*$#', $path) !== 1) {
            return '/';
        }
        $query = preg_replace('/[\x00-\x20]/', '', $query) ?? '';

        return $path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Package assets never get a session (public, cacheable). API requests
     * only resume an existing session cookie: bearer-token requests ignore
     * cookies entirely, and anonymous API calls must not mint sessions.
     */
    private function needsSession(string $uri, Session $session): bool
    {
        if (str_starts_with($uri, '/assets/')) {
            return false;
        }

        if (str_starts_with($uri, '/api/')) {
            $bearer = stripos(trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '')), 'Bearer ') === 0;

            return !$bearer && isset($_COOKIE[$session->name()]);
        }

        // A visitor without a session cookie reading pages has nothing to resume: start on write.
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (($method === 'GET' || $method === 'HEAD') && !isset($_COOKIE[$session->name()])) {
            return false;
        }

        return true;
    }

    private function handleInstaller(Application $app, string $uri, bool $installed): void
    {
        $redirect = self::installerRedirect($uri, $installed);
        if ($redirect !== null) {
            header('Location: ' . $redirect);
            return;
        }

        if (!$installed) {
            InstallerController::prepareFolders($this->paths); // before anything writes a log or a session
        }

        $app->bootstrap();
        if (str_starts_with($uri, '/assets/core/')) {
            InstallerRoutes::assets($app->router(), $this->paths);
        } else {
            InstallerRoutes::register($app->router(), $this->paths, $this->isSecureRequest($app));
        }
        $app->run();
    }

    /**
     * Where a request goes while the installer is in charge, or null to serve it.
     * Before installation only the installer and the core assets it loads (its
     * scripts and styles) are served; after it, only the closing page.
     */
    public static function installerRedirect(string $uri, bool $installed): ?string
    {
        if ($installed) {
            return $uri === '/install/complete' ? null : '/';
        }

        $served = $uri === '/install' || str_starts_with($uri, '/install/') || str_starts_with($uri, '/assets/core/');

        return $served ? null : '/install';
    }

    /**
     * Recovery mode: a valid signed token (from `bin/cms recovery:link`) in the
     * URL sets an HttpOnly cookie, then redirects without the token; while the
     * cookie is valid, plugins are skipped and the default theme is used.
     *
     * @return bool|null true/false = mode for this request; null = a redirect was sent
     */
    private function recoveryMode(Application $app): ?bool
    {
        $token = $_GET[Recovery::QUERY] ?? null;
        $cookie = $_COOKIE[Recovery::COOKIE] ?? null;

        if (!is_string($token) && !is_string($cookie)) {
            return false;
        }

        try {
            $recovery = new Recovery((string) $app->config()->get('app.secret_key', ''));
        } catch (Throwable) {
            return false;
        }

        if (is_string($token) && $recovery->verify($token)) {
            setcookie(Recovery::COOKIE, $token, [
                'expires' => (int) $recovery->expiresAt($token),
                'path' => '/',
                'secure' => $this->isSecureRequest($app),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $query = $_GET;
            unset($query[Recovery::QUERY]);
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            header('Location: ' . $path . ($query !== [] ? '?' . http_build_query($query) : ''), true, 302);
            return null;
        }

        return is_string($cookie) && $recovery->verify($cookie);
    }

    /**
     * Cookie "Secure" flag: on for HTTPS, overridable with app.secure_cookies
     * (needed behind TLS-terminating proxies that don't forward HTTPS).
     */
    private function isSecureRequest(Application $app): bool
    {
        $configured = $app->config()->get('app.secure_cookies');
        if (is_bool($configured)) {
            return $configured;
        }

        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        // Behind a proxy that ends TLS: believe its X-Forwarded-Proto only when it is one of app.trusted_proxies.
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));

        return $proto === 'https' && \Kestello\Framework\Http\TrustedProxies::isTrusted((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    // ── Console ─────────────────────────────────────────────────────

    /**
     * @param list<string> $argv
     */
    public function handleConsole(array $argv): int
    {
        $app = Application::getInstance($this->paths);
        $app->bootstrap();
        $cms = null;

        $pending = null;
        if (InstallerController::isInstalled($this->paths)) {
            // an update that stopped halfway, or update:recover: plugins stay unloaded
            $pending = (new UpdateJournal($this->paths->storage()))->pending();
            $safe = in_array('--safe', $argv, true) || $pending !== null || ($argv[1] ?? '') === 'update:recover';
            try {
                $this->connectDatabase($app);
                $cms = $this->bootExtensions($app, recovery: $safe);
                $cms->events->dispatch(new KernelBooted(console: true, recoveryMode: $safe));
            } catch (Throwable $e) {
                fwrite(STDERR, "\033[33mWarning: extensions not booted: {$e->getMessage()}\033[0m" . PHP_EOL);
            }
        }
        if ($pending !== null && ($argv[1] ?? '') !== 'update:recover') {
            if ($cms === null) {
                return 1;
            }
            fwrite(STDERR, "An update of {$pending['owner']} stopped halfway. Putting it back first; run the command again afterwards." . PHP_EOL);

            return \Kestello\Cms\Console\Package\UpdateRecoverCommand::report($cms->container->make(UpdateRecovery::class)->recover(), static fn(string $line) => fwrite(STDERR, $line . PHP_EOL));
        }

        $root = $this->paths->root();
        $migrations = $this->paths->coreMigrations();
        $console = new Console($root);

        $console->register(new Commands\MigrateCommand($migrations));
        $console->register(new Commands\MigrateRollbackCommand($migrations));
        $console->register(new Commands\MigrateResetCommand($migrations));
        $console->register(new Commands\MigrateStatusCommand($migrations));
        $console->register(new Commands\MigrateFreshCommand($migrations));
        $console->register(new Commands\CacheClearCommand(static fn(): CacheStore => $app->container()->make(CacheStore::class), $this->paths->compiled()));
        $console->register(new Commands\ServeCommand($root));
        $console->register(new Commands\KeyGenerateCommand($root));
        $console->register(new Commands\RoutesCommand(fn(): Router => $app->router()));
        $console->register(new TestCommand($root));
        $console->register(new TokenCreateCommand(static fn(): array => array_keys($cms?->api->scopes() ?? ApiRegistry::CORE_SCOPES)));
        $console->register(new RecoveryLinkCommand($app->config()));
        $console->register(new \Kestello\Cms\Console\ResetTwoFactorCommand());
        $console->register(new \Kestello\Cms\Console\SetPasswordCommand());
        $console->register(new \Kestello\Cms\Console\CreateUserCommand());
        $console->register(new \Kestello\Cms\Console\ConfigPublishCommand($this->paths->config()));
        $console->register(new \Kestello\Cms\Console\AuditPruneCommand());
        $console->register(new \Kestello\Cms\Console\Queue\QueueWorkCommand());
        $console->register(new \Kestello\Cms\Console\Queue\QueueRestartCommand());
        $console->register(new \Kestello\Cms\Console\Queue\QueueMonitorCommand());
        $console->register(new \Kestello\Cms\Console\Queue\QueueFailedCommand());
        $console->register(new \Kestello\Cms\Console\Queue\QueueRetryCommand());
        $console->register(new \Kestello\Cms\Console\Queue\QueueForgetCommand());
        $console->register(new \Kestello\Cms\Console\Queue\QueueClearCommand());
        $console->register(new \Kestello\Cms\Console\Schedule\ScheduleRunCommand());
        $console->register(new \Kestello\Cms\Console\Schedule\ScheduleWorkCommand());
        $console->register(new \Kestello\Cms\Console\Schedule\ScheduleListCommand());
        $console->register(new \Kestello\Cms\Console\Schedule\ScheduleTestCommand());
        $console->register(new \Kestello\Cms\Console\Schedule\CronTokenCommand());
        $console->register(new \Kestello\Cms\Console\Demo\DemoCommand('snapshot'));
        $console->register(new \Kestello\Cms\Console\Demo\DemoCommand('reset'));
        $console->register(new \Kestello\Cms\Console\Storage\StorageDisksCommand());
        $console->register(new \Kestello\Cms\Console\Storage\StorageTestCommand());
        $console->register(new \Kestello\Cms\Console\Media\MediaVariantsCommand());
        $console->register(new \Kestello\Cms\Console\Media\MediaMoveCommand());
        $console->register(new \Kestello\Cms\Console\SearchReindexCommand());
        $console->register(new \Kestello\Cms\Console\ContentRelationsCommand());
        $console->register(new \Kestello\Cms\Console\PageCacheClearCommand());
        $console->register(new \Kestello\Cms\Console\HooksListCommand());
        $console->register(new \Kestello\Cms\Console\ApiClientCommand());
        $console->register(new \Kestello\Cms\Console\System\HealthCommand());
        $console->register(new \Kestello\Cms\Console\Schedule\ScheduleToggleCommand(true));
        $console->register(new \Kestello\Cms\Console\Schedule\ScheduleToggleCommand(false));
        $console->register(new PackageCheckCommand($this->paths));
        // development and release tools: they work on a checkout that was never installed
        $console->register(new \Kestello\Cms\Console\Package\PackageKeygenCommand());
        $console->register(new \Kestello\Cms\Console\Package\PackageBuildCommand());
        $console->register(new \Kestello\Cms\Console\Package\PackageSignCommand());
        $console->register(new \Kestello\Cms\Console\Package\CoreReleaseCommand($this->paths));
        $console->register(new \Kestello\Cms\Console\Package\CoreInstallZipCommand($this->paths));
        $console->register(new \Kestello\Cms\Console\Package\CoreVerifyCommand($this->paths));
        $console->register(new \Kestello\Cms\Console\I18n\I18nExtractCommand($this->paths));
        $console->register(new \Kestello\Cms\Console\Ide\IdeHelpersCommand($this->paths));
        $console->register(new Make\MakePluginCommand($this->paths));
        $console->register(new Make\MakeThemeCommand($this->paths));
        foreach ([Make\MakeControllerCommand::class, Make\MakeModelCommand::class, Make\MakeMigrationCommand::class,
            Make\MakeMiddlewareCommand::class, Make\MakeCommandCommand::class] as $generator) {
            $console->register(new $generator($this->paths));
        }

        if ($cms !== null) {
            $extensions = $cms->extensions();
            foreach (PackageType::cases() as $type) {
                $console->register(new PackageListCommand($extensions, $type));
                $console->register(new PackageInstallCommand($extensions, $type));
                $console->register(new PackageActivateCommand($extensions, $type));
            }
            $platform = static fn(): PlatformManager => $cms->container->make(PlatformManager::class);
            foreach ([Platform\PlatformShowCommand::class, Platform\PlatformTestCommand::class, Platform\PlatformSetCommand::class,
                Platform\PlatformResetCommand::class, Platform\PlatformCompileCommand::class] as $command) {
                $console->register(new $command($platform));
            }
            $console->register(new PluginDeactivateCommand($extensions));
            $console->register(new \Kestello\Cms\Console\Extensions\ThemeExportCommand());
            $console->register(new \Kestello\Cms\Console\Extensions\ThemeImportCommand());
            $console->register(new \Kestello\Cms\Console\ImportWordPressCommand());
            $console->register(new \Kestello\Cms\Console\SeedCommand());
            foreach ([\Kestello\Cms\Console\Backup\BackupCreateCommand::class, \Kestello\Cms\Console\Backup\BackupListCommand::class,
                \Kestello\Cms\Console\Backup\BackupRestoreCommand::class, \Kestello\Cms\Console\Backup\BackupDeleteCommand::class,
                \Kestello\Cms\Console\Package\PackageVerifyCommand::class,
                \Kestello\Cms\Console\Package\PackageUpdateCommand::class, \Kestello\Cms\Console\Package\PackageCheckUpdatesCommand::class, \Kestello\Cms\Console\Package\PackageLicenseCommand::class, \Kestello\Cms\Console\Package\UpdateProbeCommand::class,
                \Kestello\Cms\Console\Package\CoreUpdateCommand::class, \Kestello\Cms\Console\Package\CoreCheckCommand::class, \Kestello\Cms\Console\Package\RegistryStatusCommand::class, \Kestello\Cms\Console\Package\RegistryInstallCommand::class,
                \Kestello\Cms\Console\Package\UpdateRecoverCommand::class] as $command) {
                $console->register(new $command());
            }
            $console->register(new PluginUninstallCommand($extensions));
            $console->register(new PluginConfigCommand($extensions));

            foreach ($cms->console->all() as ['command' => $command]) {
                $console->register($command);
            }
        }

        return $console->run(array_values(array_filter($argv, static fn(string $a): bool => $a !== '--safe')));
    }

    // ── Shared ──────────────────────────────────────────────────────

    /**
     * Build the extension API and boot core modules, plugins and the theme.
     */
    private function bootExtensions(Application $app, bool $recovery): Cms
    {
        $container = $app->container();
        $debug = (bool) $app->config()->get('app.debug', false);

        $filters = new Filters(strict: $debug);
        $auth = new AuthRegistry();
        $drivers = new DriverRegistry($container);
        CoreDrivers::register($drivers, $this->paths, $this->sessionLifetime($app));
        CoreDrivers::registerSearch($drivers);

        $limiter = new RateLimiter($this->limiterStore($drivers));
        $container->instance(RateLimiter::class, $limiter);

        $api = new ApiRegistry(
            router: $app->router(),
            auth: $auth,
            container: $container,
            filters: $filters,
            limiter: $limiter,
            csrf: static function (Request $request) use ($container): bool {
                if (!$container->has(Session::class)) {
                    return false;
                }
                $token = $request->header('X-CSRF-TOKEN');

                return $token !== '' && $container->make(Session::class)->validateToken($token);
            },
            debug: $debug,
            demo: (bool) $app->config()->get('app.demo', false),
        );

        $cms = new Cms(
            container: $container,
            paths: $this->paths,
            config: $app->config(),
            routes: $app->router(),
            events: new EventDispatcher($container),
            filters: $filters,
            pages: new Pages(),
            console: new CommandRegistry(),
            auth: $auth,
            api: $api,
            drivers: $drivers,
        );

        $auth->guard(new SessionGuard($auth, static fn(): ?Auth => Auth::getInstance()));
        $auth->guard(new TokenGuard($auth, new PersonalAccessTokens()));
        $app->router()->pushGlobalMiddleware(new ApiGateway(new CorsMiddleware(
            origins: (array) $app->config()->get('api.cors.origins', []),
            credentials: (bool) $app->config()->get('api.cors.credentials', false),
        )));

        Cms::setInstance($cms);
        $container->instance(Cms::class, $cms);
        $container->instance(EventDispatcher::class, $cms->events);
        $container->instance(Filters::class, $cms->filters);
        $container->instance(AuthRegistry::class, $auth);
        $container->instance(ApiRegistry::class, $api);
        $cms->audit->setContextProvider(static function () use ($auth): array {
            $actor = $auth->current()?->userId;
            if ($actor === null && session_status() === PHP_SESSION_ACTIVE && is_numeric($_SESSION['_auth_user_id'] ?? null)) {
                $actor = (int) $_SESSION['_auth_user_id'];
            }

            return [
                'actor_id' => $actor,
                'ip' => PHP_SAPI === 'cli' ? null : TrustedProxies::clientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')),
                'user_agent' => PHP_SAPI === 'cli' ? 'cli' : (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'request_id' => null,
            ];
        });
        $this->bootPlatform($app, $cms);
        $cms->queue->setBackend(static fn() => $cms->drivers->resolve('queue'));
        $cms->queue->setContainer($container, $cms->events);
        $cms->queue->wireListeners($cms->events);
        $cms->models->setDispatcher($cms->events);
        if ($debug) {
            $seen = [];
            $cms->models->handleLazyLoadingWith(static function (\Kestello\Framework\Database\Model $model, string $relation) use (&$seen): void {
                $key = $model::class . '::' . $relation;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    error_log("[orm] N+1: [{$relation}] lazy loaded on each " . $model::class . " of a list; eager load it with ->with('{$relation}').");
                }
            });
        }
        $container->instance(\Kestello\Cms\Queue\JobQueue::class, $cms->queue);
        $cms->notifications->wire(static fn() => $cms->queue, $cms->events);

        $extensions = new ExtensionManager(
            cms: $cms,
            state: $this->stateStore(),
            suspensions: new SuspensionList($this->paths->storage('framework/suspended.json')),
            coreVersion: self::version(),
        );
        $container->instance(ExtensionManager::class, $extensions);

        // Plugins and themes may only add sign-in methods and reach hosts they declared.
        $permissions = static fn(string $owner): ?array => $extensions->permissionsFor($owner);
        $cms->auth->setPermissionResolver($permissions);
        if (!$container->has(HttpClient::class)) {
            $container->singleton(HttpClient::class, static fn(): HttpClient => new HttpClient(
                allowlist: static function (string $owner) use ($permissions): ?array {
                    $declared = $permissions($owner);
                    return $declared === null ? [] : array_values(array_filter((array) ($declared['network'] ?? []), 'is_string'));
                },
            ));
        }

        $baseUrl = (string) $cms->config->get('app.url', '');
        $cms->storage->configure(
            root: $this->paths->storage('app'),
            disks: (array) $cms->config->get('storage.disks', []),
            http: static fn(): HttpClient => $container->make(HttpClient::class),
            signer: static fn(string $disk, string $path, int $seconds, array $options): string => \Kestello\Cms\Storage\SignedFileUrl::make($baseUrl, $disk, $path, $seconds, $options),
            cache: static function () use ($cms): ?\Kestello\Contracts\Cache\Store {
                try {
                    $store = $cms->drivers->resolve('cache');
                } catch (\Throwable) {
                    return null;
                }

                return $store instanceof \Kestello\Contracts\Cache\Store ? $store : null;
            },
        );
        $container->instance(\Kestello\Framework\Storage\StorageManager::class, $cms->storage);

        foreach ([new AuthModule(), new AdminModule(), new ApiModule(), new ExtensionsModule(), new \Kestello\Cms\System\SystemModule(), new \Kestello\Cms\Storage\StorageModule(), new \Kestello\Cms\Notifications\NotificationsModule(), new \Kestello\Cms\Health\HealthModule(), new \Kestello\Cms\Cache\PageCacheModule(), new \Kestello\Cms\Content\ContentModule(), new \Kestello\Cms\Media\MediaModule(), new \Kestello\Cms\Blocks\BlocksModule(), new \Kestello\Cms\Search\SearchModule(), new \Kestello\Cms\Redirects\RedirectsModule(), new \Kestello\Cms\Forms\FormsModule(), new \Kestello\Cms\Webhooks\WebhooksModule(), new \Kestello\Cms\Design\DesignModule(), new \Kestello\Cms\Backup\BackupModule(), new \Kestello\Cms\Import\ImportModule(), new \Kestello\Cms\Package\PackagesModule(), new \Kestello\Cms\Site\SiteModule(), new \Kestello\Cms\Fleet\FleetModule(), new FrontendModule(), new \Kestello\Cms\Demo\DemoModule()] as $module) {
            $extensions->addModule($module);
        }

        $extensions->boot($recovery);
        try {
            \Kestello\Cms\Content\ContentModule::finalize($cms);
        } catch (Throwable $e) {
            error_log('[kestello] content types: ' . $e->getMessage());
        }
        try {
            $cms->auth->roles->applyStored(\Kestello\Cms\Auth\RoleStore::load());
        } catch (Throwable $e) {
            error_log('[kestello] stored roles: ' . $e->getMessage()); // before migrations ran: code roles only
        }
        $this->bootTranslations($cms, $extensions);

        return $cms;
    }

    /**
     * Locale from Settings → General (else app.locale), the fallback from app.fallback_locale,
     * and language packs:
     * core/resources/lang (domain "core") and <package>/resources/lang (its slug).
     */
    private function bootTranslations(Cms $cms, ExtensionManager $extensions): void
    {
        try {
            \Kestello\Support\Translator::setLocale(\Kestello\Cms\I18n\SiteLanguage::resolve($this->languageSetting(), (string) $cms->config->get('app.locale', 'en')));
            \Kestello\Support\Translator::setFallback((string) $cms->config->get('app.fallback_locale', 'en'));
        } catch (\InvalidArgumentException $e) {
            error_log('[kestello] ' . $e->getMessage());
        }
        \Kestello\Support\Translator::addPath('core', $this->paths->core('resources/lang'));
        foreach ([...array_values($extensions->activePlugins()), ...array_reverse($extensions->themeChain())] as $package) {
            \Kestello\Support\Translator::addPath($package->slug(), $package->path('resources/lang'));
        }
    }

    /** The language chosen in Settings → General, or null (not installed yet, or the database is away). */
    private function languageSetting(): ?string
    {
        if (!InstallerController::isInstalled($this->paths)) {
            return null;
        }
        try {
            $value = \Kestello\Framework\Settings\Settings::getInstance()->get(\Kestello\Cms\I18n\SiteLanguage::SETTING);

            return is_string($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Layered platform config: effective driver settings for $cms->drivers,
     * and the PlatformManager used by the admin API and platform:* commands.
     */
    private function bootPlatform(Application $app, Cms $cms): void
    {
        $container = $cms->container;
        $secrets = null;
        $key = (string) $app->config()->get('app.secret_key', '');
        if ($key !== '') {
            try {
                $previous = (array) $app->config()->get('app.previous_keys', []);
                \Kestello\Support\AppKey::set($key, $previous);
                $secrets = \Kestello\Support\AppKey::secretStore();
                $container->instance(SecretStore::class, $secrets);
            } catch (Throwable $e) {
                error_log('[kestello] secrets disabled: ' . $e->getMessage());
            }
        }

        $store = DatabaseManager::hasConnection()
            ? new SettingsPlatformStore(DatabaseManager::getConnection())
            : new ArrayPlatformStore();

        $config = new PlatformConfig(
            registry: $cms->drivers,
            store: $store,
            secrets: $secrets,
            coreConfigFile: $this->paths->coreConfig() . '/platform.php',
            siteConfigFile: $this->paths->config('platform.php'),
            compiledFile: $this->paths->storage('framework/platform.php'),
            env: getenv(),
        );
        $cms->drivers->setConfigResolver($config->resolve(...));

        $container->instance(DriverRegistry::class, $cms->drivers);
        $container->instance(PlatformConfig::class, $config);
        $drivers = $cms->drivers;
        $breaker = $this->paths->storage('framework/circuit/cache');
        $container->singleton(CacheStore::class, static fn(): CacheStore => new FailoverStore(
            static fn(): CacheStore => $drivers->resolve('cache'),
            new ArrayStore(),
            $breaker,
        ));
        $container->instance(PlatformManager::class, new PlatformManager($cms->drivers, $config, $cms->events->dispatch(...)));
    }

    /**
     * Rate limits use the cache store, but never a per-request one (limits
     * would silently stop working): array store or an outage → files.
     */
    private function limiterStore(DriverRegistry $drivers): CacheStore
    {
        $files = new FileStore($this->paths->storage('framework/ratelimit'));

        return new FailoverStore(
            static function () use ($drivers, $files): CacheStore {
                $store = $drivers->resolve('cache');
                return $store instanceof ArrayStore ? $files : $store;
            },
            $files,
            $this->paths->storage('framework/circuit/ratelimit'),
        );
    }

    /**
     * The configured session store, wrapped so a failing store (missing
     * table, Redis down) degrades to files instead of locking everyone out.
     */
    private function sessionStore(Cms $cms, Application $app): SessionStore
    {
        $fallback = new FileSessionStore($this->paths->sessions(), $this->sessionLifetime($app));

        try {
            $primary = $cms->drivers->resolve('session');
        } catch (Throwable $e) {
            error_log('[kestello] session store unavailable, using files: ' . $e->getMessage());
            $primary = $fallback;
        }

        $metadata = static fn(): SessionMetadata => new SessionMetadata(
            userId: isset($_SESSION['_auth_user_id']) ? (int) $_SESSION['_auth_user_id'] : null,
            ip: TrustedProxies::clientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')),
            userAgent: (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        );
        foreach ([$primary, $fallback] as $store) {
            if ($store instanceof AbstractSessionStore) {
                $store->setMetadataProvider($metadata);
            }
        }

        return $primary instanceof FileSessionStore
            ? $primary
            : new FailoverSessionStore($primary, $fallback, breakerFile: $this->paths->storage('framework/circuit/session'));
    }

    private function sessionLifetime(Application $app): int
    {
        return max(300, (int) $app->config()->get('app.session_lifetime', 7200));
    }

    /**
     * Package state lives in the `packages` table; before core migrations
     * have created it, nothing is active (and a warning is logged).
     */
    private function stateStore(): \Kestello\Cms\Extension\StateStore
    {
        try {
            if ((new Schema(DatabaseManager::getConnection()))->hasTable('packages')) {
                return new DatabaseStateStore();
            }
            error_log('[kestello] packages table missing: run "php bin/cms migrate". Plugins are disabled until then.');
        } catch (Throwable $e) {
            error_log('[kestello] database unavailable, plugins disabled: ' . $e->getMessage());
        }

        return new ArrayStateStore();
    }

    private function connectDatabase(Application $app): void
    {
        $config = $app->config()->get('database');

        if (is_array($config) && $config !== []) {
            DatabaseManager::setConnection(new Connection(DatabaseManager::resolveConfig($config, $this->paths->root())));
        }
    }
}
