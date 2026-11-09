<?php

declare(strict_types=1);

namespace Kestello\Cms\Installer;

use Kestello\Cms\Demo\Demo;
use Kestello\Cms\View\Page;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\MigrationRunner;

/**
 * Multi-step web installer controller.
 *
 * Guides the user through: requirements check, database setup,
 * site settings, admin account creation, and finalization.
 * The finalize step is fully transactional — if anything fails,
 * all database changes are rolled back and config files are cleaned up.
 */
final class InstallerController
{
    private readonly Paths $paths;

    public function __construct(Paths $paths)
    {
        $this->paths = $paths;
    }

    /**
     * Check if the application has been installed.
     */
    public static function isInstalled(Paths $paths): bool
    {
        return file_exists($paths->config('installed.php'));
    }

    /**
     * Show the current installer step.
     */
    public function index(Request $request): Response
    {
        if (self::isInstalled($this->paths)) {
            return Response::redirect('/');
        }

        $step = (int) ($request->query['step'] ?? 1);
        $missing = self::firstIncompleteStep($this->getInstallerSession());
        if ($missing !== null && $step > $missing) {
            return Response::redirect("/install?step={$missing}");
        }

        return match ($step) {
            1 => $this->stepRequirements($request),
            2 => $this->stepDatabase($request),
            3 => $this->stepSite($request),
            4 => $this->stepAdmin($request),
            5 => $this->stepFinalize($request),
            default => Response::redirect('/install?step=1'),
        };
    }

    /**
     * Process form submissions for each step.
     */
    public function process(Request $request): Response
    {
        if (self::isInstalled($this->paths)) {
            return Response::redirect('/');
        }

        $step = (int) ($request->post['step'] ?? 1);

        return match ($step) {
            2 => $this->processDatabaseStep($request),
            3 => $this->processSiteStep($request),
            4 => $this->processAdminStep($request),
            5 => $this->processFinalize($request),
            default => Response::redirect('/install?step=1'),
        };
    }

    // -------------------------------------------------------------------------
    // Step 1: Requirements
    // -------------------------------------------------------------------------

    private function stepRequirements(Request $request): Response
    {
        $checks = self::requirementChecks($this->paths);

        return $this->renderStep('Requirements', [
            'checks' => $checks,
            'allPassed' => !in_array(false, array_column($checks, 'passed'), true),
            'step' => 1,
        ]);
    }

    /**
     * What the server needs, each with what it is for, what was found and,
     * when it fails, what to do about it in words a site owner can act on.
     *
     * @return list<array{group: string, name: string, purpose: string, current: string, passed: bool, fix: string}>
     */
    public static function requirementChecks(Paths $paths): array
    {
        $drivers = self::availableDrivers();
        $checks = [
            [
                'group' => 'PHP',
                'name' => 'PHP 8.4 or newer',
                'purpose' => 'Kestello is written for PHP 8.4.',
                'current' => PHP_VERSION,
                'passed' => version_compare(PHP_VERSION, '8.4.0', '>='),
                'fix' => 'Choose PHP 8.4 or newer for this site in your control panel (often under "PHP version" or "Select PHP"), or ask your host.',
            ],
            [
                'group' => 'PHP',
                'name' => 'A database driver',
                'purpose' => 'MySQL, MariaDB, PostgreSQL or SQLite.',
                'current' => implode(', ', array_map(static fn(string $d): string => self::DRIVERS[$d], $drivers)) ?: 'None',
                'passed' => $drivers !== [],
                'fix' => 'Turn on pdo_mysql (or pdo_pgsql, or pdo_sqlite) in your control panel\'s PHP extensions, or ask your host.',
            ],
        ];
        foreach (\Kestello\Cms\Health\CoreHealthChecks::REQUIRED as $extension => $for) {
            $checks[] = [
                'group' => 'PHP',
                'name' => "The {$extension} extension",
                'purpose' => 'Used for ' . $for . '.',
                'current' => extension_loaded($extension) ? 'On' : 'Off',
                'passed' => extension_loaded($extension),
                'fix' => "Turn on {$extension} in your control panel's PHP extensions, or ask your host.",
            ];
        }
        foreach (self::writableFolders($paths) as $label => $dir) {
            $exists = is_dir($dir);
            $checks[] = [
                'group' => 'Folders',
                'name' => $label,
                'purpose' => 'Kestello saves files here.',
                'current' => !$exists ? 'Missing' : (is_writable($dir) ? 'Writable' : 'Not writable'),
                'passed' => $exists && is_writable($dir),
                'fix' => $exists
                    ? 'In your file manager or FTP program, set this folder\'s permissions to 755 (775 if your host asks for it).'
                    : 'Create this folder in your file manager, inside the folder you uploaded Kestello to.',
            ];
        }

        return $checks;
    }

    /**
     * The first step (2 to 4) whose answers are not saved yet, or null when
     * everything needed to install is there. Later steps send people back to it.
     *
     * @param array<string, mixed> $saved
     */
    public static function firstIncompleteStep(array $saved): ?int
    {
        return match (true) {
            ($saved['db_driver'] ?? '') === '' => 2,
            ($saved['site_name'] ?? '') === '' => 3,
            ($saved['admin_password_hash'] ?? '') === '' => 4,
            default => null,
        };
    }

    /**
     * The folders Kestello writes to, by the name the installer shows.
     *
     * @return array<string, string>
     */
    public static function writableFolders(Paths $paths): array
    {
        return [
            'config' => $paths->config(),
            'storage' => $paths->storage(),
            'storage/cache' => $paths->cache(),
            'storage/compiled' => $paths->compiled(),
            'storage/logs' => $paths->logs(),
            'storage/sessions' => $paths->sessions(),
            'content/plugins' => $paths->plugins(),
            'content/uploads' => $paths->uploads(),
        ];
    }

    /**
     * Create the writable folders that are missing. Some hosts' file managers
     * drop empty folders (and ones holding only hidden files) when they unzip,
     * so a fresh upload can arrive without config/ or storage/.
     *
     * @return array<string, string> writableFolders()
     */
    public static function prepareFolders(Paths $paths): array
    {
        $folders = self::writableFolders($paths);
        foreach ($folders as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
        \Kestello\Cms\Health\PrivateFolders::protect($paths->root());

        return $folders;
    }

    // -------------------------------------------------------------------------
    // Step 2: Database
    // -------------------------------------------------------------------------

    /** Supported drivers and their labels. */
    private const array DRIVERS = ['mysql' => 'MySQL / MariaDB', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'];

    /** @return list<string> drivers whose PDO extension is loaded */
    private static function availableDrivers(): array
    {
        return array_values(array_filter(array_keys(self::DRIVERS), static fn(string $d): bool => extension_loaded('pdo_' . $d)));
    }

    private function stepDatabase(Request $request): Response
    {
        $saved = $this->getInstallerSession();
        $available = self::availableDrivers();
        $driver = (string) ($saved['db_driver'] ?? ($available[0] ?? 'mysql'));

        return $this->renderStep('Database', [
            'step' => 2,
            'drivers' => array_map(static fn(string $d): array => ['value' => $d, 'label' => self::DRIVERS[$d]], $available),
            'db' => [
                'driver' => $driver,
                'host' => (string) ($saved['db_host'] ?? 'localhost'),
                'port' => (string) ($saved['db_port'] ?? ''),
                'name' => (string) ($saved['db_name'] ?? 'kestello'),
                'user' => (string) ($saved['db_user'] ?? ''),
                // Never echo the password back into the page.
                'passwordSet' => ($saved['db_pass'] ?? '') !== '',
            ],
            'error' => self::error($request),
        ]);
    }

    private function processDatabaseStep(Request $request): Response
    {
        $driver = (string) $request->input('db_driver', 'mysql');
        if (!in_array($driver, self::availableDrivers(), true)) {
            return Response::redirect('/install?step=2&error=' . urlencode('That database driver is not available on this server.'));
        }

        $name = trim((string) $request->input('db_name', ''));
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) {
            return Response::redirect('/install?step=2&error=' . urlencode('Database name may only contain letters, numbers, and underscores.'));
        }

        $pass = (string) $request->input('db_pass', '');
        if ($pass === '') {
            $pass = (string) ($this->getInstallerSession()['db_pass'] ?? ''); // "leave blank to keep"
        }

        $values = [
            'db_driver' => $driver,
            'db_host' => trim((string) $request->input('db_host', '')),
            'db_port' => (string) (int) $request->input('db_port', $driver === 'pgsql' ? 5432 : 3306),
            'db_name' => $name,
            'db_user' => trim((string) $request->input('db_user', '')),
            'db_pass' => $pass,
        ];

        try {
            $config = $this->databaseConfig($values);
            $resolved = DatabaseManager::resolveConfig($config, $this->paths->root());
            Connection::createDatabase($resolved);
            (new Connection($resolved))->getPdo();
        } catch (\Throwable $e) {
            return Response::redirect('/install?step=2&error=' . urlencode('Database connection failed: ' . $e->getMessage()));
        }

        $this->saveInstallerSession($values);

        return Response::redirect('/install?step=3');
    }

    /**
     * The database config written to config/database.php.
     *
     * @param array<string, mixed> $saved
     * @return array<string, mixed>
     */
    private function databaseConfig(array $saved): array
    {
        $driver = (string) ($saved['db_driver'] ?? 'mysql');

        return match ($driver) {
            // Outside the web root; relative to the site root.
            'sqlite' => ['driver' => 'sqlite', 'database' => 'storage/database/' . $saved['db_name'] . '.sqlite'],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => $saved['db_host'],
                'port' => (int) ($saved['db_port'] ?: 5432),
                'database' => $saved['db_name'],
                'username' => $saved['db_user'],
                'password' => $saved['db_pass'],
                'schema' => 'public',
            ],
            default => [
                'driver' => 'mysql',
                'host' => $saved['db_host'],
                'port' => (int) ($saved['db_port'] ?: 3306),
                'database' => $saved['db_name'],
                'username' => $saved['db_user'],
                'password' => $saved['db_pass'],
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        };
    }

    // -------------------------------------------------------------------------
    // Step 3: Site Settings
    // -------------------------------------------------------------------------

    private function stepSite(Request $request): Response
    {
        $saved = $this->getInstallerSession();

        // Suggest the address this page was opened on.
        $https = ($request->server['HTTPS'] ?? '') !== '' && strtolower((string) $request->server['HTTPS']) !== 'off';
        $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $request->header('Host'));
        $guess = $host !== '' ? ($https ? 'https://' : 'http://') . $host : '';

        return $this->renderStep('Site', [
            'step' => 3,
            'error' => self::error($request),
            'timezones' => self::TIMEZONES,
            'site' => [
                'name' => (string) ($saved['site_name'] ?? ''),
                'description' => (string) ($saved['site_description'] ?? ''),
                'url' => (string) ($saved['site_url'] ?? $guess),
                'timezone' => (string) ($saved['timezone'] ?? 'UTC'),
                'demo' => (bool) ($saved['demo'] ?? false),
            ],
        ]);
    }

    private function processSiteStep(Request $request): Response
    {
        $saved = $this->getInstallerSession();

        $saved['site_name'] = trim((string) $request->input('site_name', ''));
        $saved['site_description'] = trim((string) $request->input('site_description', ''));
        $saved['site_url'] = rtrim(trim((string) $request->input('site_url', '')), '/');
        if ($saved['site_url'] !== '' && (filter_var($saved['site_url'], FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $saved['site_url']))) {
            return Response::redirect('/install?step=3&error=' . urlencode('The site address must be a full address starting with https:// or http://.'));
        }
        if ($saved['site_name'] === '') {
            return Response::redirect('/install?step=3&error=' . urlencode('Give the site a name.'));
        }
        $timezone = trim((string) $request->input('timezone', 'UTC'));
        $saved['timezone'] = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';

        $wasDemo = (bool) ($saved['demo'] ?? false);
        $saved['demo'] = $request->input('site_kind', 'site') === 'demo';
        if ($saved['demo']) {
            // a public demo's only account is the shared one (Demo); its owner works on the server
            $saved['admin_username'] = Demo::USERNAME;
            $saved['admin_email'] = Demo::EMAIL;
            $saved['admin_password_hash'] = \Kestello\Support\Hash::password(Demo::PASSWORD);
        } elseif ($wasDemo) {
            unset($saved['admin_username'], $saved['admin_email'], $saved['admin_password_hash']);
            unset($_SESSION['installer']['admin_username'], $_SESSION['installer']['admin_email'], $_SESSION['installer']['admin_password_hash']);   // saving merges
        }
        $this->saveInstallerSession($saved);

        return Response::redirect($saved['demo'] ? '/install?step=5' : '/install?step=4');
    }

    // -------------------------------------------------------------------------
    // Step 4: Admin Account
    // -------------------------------------------------------------------------

    private function stepAdmin(Request $request): Response
    {
        $saved = $this->getInstallerSession();
        if ($saved['demo'] ?? false) {
            return Response::redirect('/install?step=5');
        }

        return $this->renderStep('Admin', [
            'step' => 4,
            'error' => self::error($request),
            // Kept across a refused attempt, so only the password is typed again.
            'admin' => ['username' => (string) ($saved['admin_username'] ?? ''), 'email' => (string) ($saved['admin_email'] ?? '')],
        ]);
    }

    private function processAdminStep(Request $request): Response
    {
        $username = trim((string) $request->input('admin_username', ''));
        $email = trim((string) $request->input('admin_email', ''));
        $password = (string) $request->input('admin_password', '');
        $passwordConfirm = (string) $request->input('admin_password_confirm', '');

        $saved = $this->getInstallerSession();
        $saved['admin_username'] = $username;
        $saved['admin_email'] = $email;
        $this->saveInstallerSession($saved);
        unset($_SESSION['installer']['admin_password_hash']); // a refused attempt must not leave an older password behind

        if ($username === '' || $email === '' || $password === '') {
            return Response::redirect('/install?step=4&error=' . urlencode('All fields are required.'));
        }

        if (strlen($username) < 3 || strlen($username) > 50) {
            return Response::redirect('/install?step=4&error=' . urlencode('Username must be between 3 and 50 characters.'));
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
            return Response::redirect('/install?step=4&error=' . urlencode('Username may only contain letters, numbers, underscores, and hyphens.'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::redirect('/install?step=4&error=' . urlencode('Invalid email address.'));
        }

        // The same rules as everywhere else in Kestello.
        $policy = (new \Kestello\Cms\Auth\PasswordPolicy())->errors($password, ['username' => $username, 'email' => $email]);
        if ($policy !== []) {
            return Response::redirect('/install?step=4&error=' . urlencode($policy[0]));
        }

        if ($password !== $passwordConfirm) {
            return Response::redirect('/install?step=4&error=' . urlencode('Passwords do not match.'));
        }

        $saved['admin_email'] = \Kestello\Cms\Auth\Emails::normalize($email);
        // Hashed now: the plain password never sits in the session.
        $saved['admin_password_hash'] = \Kestello\Support\Hash::password($password);
        $this->saveInstallerSession($saved);
        unset($_SESSION['installer']['admin_password']);

        return Response::redirect('/install?step=5');
    }

    // -------------------------------------------------------------------------
    // Step 5: Finalize (transactional)
    // -------------------------------------------------------------------------

    private function stepFinalize(Request $request): Response
    {
        $saved = $this->getInstallerSession();

        return $this->renderStep('Finalize', [
            'step' => 5,
            'summary' => [
                'site_name' => (string) ($saved['site_name'] ?? ''),
                'db_driver' => self::DRIVERS[(string) ($saved['db_driver'] ?? 'mysql')] ?? 'MySQL / MariaDB',
                'db_host' => ($saved['db_driver'] ?? '') === 'sqlite' ? 'storage/database/' . ($saved['db_name'] ?? '') . '.sqlite' : (string) ($saved['db_host'] ?? ''),
                'db_name' => (string) ($saved['db_name'] ?? ''),
                'admin_username' => (string) ($saved['admin_username'] ?? ''),
                'admin_email' => (string) ($saved['admin_email'] ?? ''),
                'demo' => (bool) ($saved['demo'] ?? false),
            ],
            'error' => self::error($request),
        ]);
    }

    private function processFinalize(Request $request): Response
    {
        $saved = $this->getInstallerSession();
        $missing = self::firstIncompleteStep($saved);
        if ($missing !== null) {
            return Response::redirect("/install?step={$missing}");
        }
        $configFiles = [];

        try {
            // 1. Write config files (track them so we can clean up on failure)
            $configFiles = $this->writeConfig($saved);

            // 2. Connect to database
            $connection = new Connection(DatabaseManager::resolveConfig($this->databaseConfig($saved), $this->paths->root()));
            DatabaseManager::setConnection($connection);

            // 3. Run migrations + create admin inside a transaction
            $connection->transaction(function (Connection $conn) use ($saved): void {
                // Run migrations
                $runner = new MigrationRunner($this->paths->coreMigrations());
                $runner->migrate();

                // Create admin user with hashed password
                $now = date('Y-m-d H:i:s');
                $conn->query(
                    'INSERT INTO users (username, email, password, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $saved['admin_username'],
                        $saved['admin_email'],
                        $saved['admin_password_hash'],
                        'admin',
                        1,
                        $now,
                        $now,
                    ],
                );
            });

            // 4. Write installed marker (only after DB work succeeds)
            $configFiles[] = $this->writeInstalledMarker();

            // 5. Clean up installer session
            $this->clearInstallerSession();

            return Response::redirect('/install/complete');

        } catch (\Throwable $e) {
            // Clean up any config files written during this failed attempt
            $this->cleanupConfigFiles($configFiles);

            $error = urlencode('Installation failed: ' . $e->getMessage());
            return Response::redirect("/install?step=5&error={$error}");
        }
    }

    /**
     * Show installation complete page.
     */
    public function complete(Request $request): Response
    {
        $app = is_file($this->paths->config('app.php')) ? (array) require $this->paths->config('app.php') : [];

        return $this->renderStep('Complete', [
            'step' => 6,
            'site' => ['name' => (string) ($app['name'] ?? ''), 'url' => (string) ($app['url'] ?? '')],
            'cron' => 'php ' . $this->paths->root('bin/cms') . ' schedule:run',
        ]);
    }

    // -------------------------------------------------------------------------
    // Config file generation (uses var_export for safe serialization)
    // -------------------------------------------------------------------------

    /**
     * Write all config files. Returns list of file paths written.
     *
     * @return array<string> Paths to created config files.
     */
    private function writeConfig(array $data): array
    {
        $configDir = $this->paths->config();
        $written = [];

        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        // Database config
        $dbConfig = $this->databaseConfig($data);
        $path = $configDir . '/database.php';
        $this->writeConfigFile($path, $dbConfig);
        $written[] = $path;

        // App config
        $appConfig = [
            'name' => $data['site_name'],
            'description' => $data['site_description'],
            'url' => $data['site_url'],
            'timezone' => $data['timezone'],
            'debug' => false,
            'secret_key' => bin2hex(random_bytes(32)),
        ];
        if ($data['demo'] ?? false) {
            $appConfig['demo'] = true;
        }
        $path = $configDir . '/app.php';
        $this->writeConfigFile($path, $appConfig);
        $written[] = $path;

        // cron, queue, auth, audit: every option at its default, with comments
        return [...$written, ...ConfigTemplates::writeMissing($configDir)];
    }

    /**
     * Safely write a config array to a PHP file using var_export.
     */
    private function writeConfigFile(string $path, array $data): void
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . self::export($data) . ";\n";

        // Atomic write
        $tmpPath = $path . '.tmp.' . getmypid();
        file_put_contents($tmpPath, $content, LOCK_EX);
        rename($tmpPath, $path);
    }

    /**
     * A PHP literal for a config value, with short arrays. Built from
     * var_export() per scalar, so no value can change on the way.
     *
     * @internal public for tests
     */
    public static function export(mixed $value, int $depth = 0): string
    {
        if (!is_array($value)) {
            return var_export($value, true);
        }
        if ($value === []) {
            return '[]';
        }
        $pad = str_repeat('    ', $depth + 1);
        $list = array_is_list($value);
        $out = "[\n";
        foreach ($value as $key => $item) {
            $out .= $pad . ($list ? '' : var_export($key, true) . ' => ') . self::export($item, $depth + 1) . ",\n";
        }

        return $out . str_repeat('    ', $depth) . ']';
    }

    /**
     * Write the installed marker file. Returns the file path.
     */
    private function writeInstalledMarker(): string
    {
        $data = [
            'installed_at' => date('Y-m-d H:i:s'),
            'version' => trim((string) @file_get_contents($this->paths->core('VERSION'))) ?: 'dev',
        ];

        $path = $this->paths->config('installed.php');
        $this->writeConfigFile($path, $data);

        return $path;
    }

    /**
     * Remove config files created during a failed installation attempt.
     *
     * @param array<string> $files File paths to remove.
     */
    private function cleanupConfigFiles(array $files): void
    {
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Rendering (Vue pages under @core/installer/)
    // -------------------------------------------------------------------------

    public const array TIMEZONES = ['UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
        'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Belgrade', 'Asia/Tokyo', 'Asia/Shanghai', 'Australia/Sydney'];

    /**
     * @param array<string, mixed> $props
     */
    private function renderStep(string $view, array $props): Response
    {
        return Page::render('@core/installer/' . $view, ['title' => 'Install', ...$props]);
    }

    // -------------------------------------------------------------------------
    // Session helpers
    // -------------------------------------------------------------------------

    private static function error(Request $request): ?string
    {
        $error = $request->query['error'] ?? null;

        return is_string($error) && $error !== '' ? mb_substr($error, 0, 500) : null;
    }

    private function getInstallerSession(): array
    {
        $this->ensureSession();
        return $_SESSION['installer'] ?? [];
    }

    private function saveInstallerSession(array $data): void
    {
        $this->ensureSession();
        $_SESSION['installer'] = array_merge($this->getInstallerSession(), $data);
    }

    private function clearInstallerSession(): void
    {
        $this->ensureSession();
        unset($_SESSION['installer']);
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
