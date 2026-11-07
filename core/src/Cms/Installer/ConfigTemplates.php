<?php

declare(strict_types=1);

namespace Kestello\Cms\Installer;

/**
 * The optional config files a site starts with: every key at its default,
 * each with a comment, so an owner sees what can be changed without
 * reading the docs. Written by the installer and by config:publish, and
 * only when the file does not exist: a site's own file is never touched.
 *
 * The values here must match the defaults in the code that reads them;
 * ConfigTemplatesTest checks that.
 */
final class ConfigTemplates
{
    /** @return array<string, string> group => file contents */
    public static function all(): array
    {
        return [
            'cron' => <<<'PHP'
                <?php

                declare(strict_types=1);

                // Scheduled tasks. Kestello needs one cron entry, every minute:
                //   * * * * * php /path/to/site/bin/cms schedule:run
                // Without one, the options below keep tasks running on hosts that have no cron.

                return [
                    // Run due tasks after a page has been sent to a visitor.
                    // 'auto': only while no real cron or pinger has run in the last 5 minutes.
                    // true: always. false: never.
                    'web_runner' => 'auto',

                    // Seconds one web runner pass may work (5 to 55).
                    'web_runner_seconds' => 20,

                    // Seconds one call from an external cron service may work (see bin/cms cron:token).
                    'http_seconds' => 25,
                ];

                PHP,
            'queue' => <<<'PHP'
                <?php

                declare(strict_types=1);

                // Background jobs: emails, imports, image work and plugin jobs.

                return [
                    // 'cron': the scheduler works the queue every minute. Nothing else to run.
                    // 'daemon': you run "php bin/cms queue:work" under systemd or supervisor.
                    'worker' => 'cron',

                    // Days failed jobs and finished batches are kept.
                    'failed_retention_days' => 30,
                ];

                PHP,
            'auth' => <<<'PHP'
                <?php

                declare(strict_types=1);

                // Accounts and signing in.

                return [
                    // Shortest password accepted (at least 8).
                    'password_min_length' => 12,

                    // When someone's password changes, end their other sessions and revoke their API tokens.
                    'revoke_on_password_change' => true,

                    // Roles told that two-step sign-in is expected for their account. Not enforced.
                    'two_factor_roles' => ['admin'],

                    // The rest applies only when a plugin adds a sign-in provider (Google, GitHub, SSO...).
                    // Who may create an account by signing in with a provider: 'closed', 'open' or 'domains'.
                    'registration' => 'closed',
                    // E-mail domains allowed when registration is 'domains', e.g. ['example.com'].
                    'registration_domains' => [],
                    // Role for accounts created that way.
                    'default_role' => 'user',
                    // Link a provider sign-in to an existing account with the same verified e-mail address.
                    'link_by_verified_email' => false,
                    // Accept the provider's own two-step check instead of asking for Kestello's.
                    'trust_provider_mfa' => false,
                ];

                PHP,
            'audit' => <<<'PHP'
                <?php

                declare(strict_types=1);

                // The audit log (System → Audit log).

                return [
                    // Days entries are kept. Older ones are deleted every night.
                    'retention_days' => 365,
                ];

                PHP,
        ];
    }

    /**
     * Write the templates whose files are missing.
     *
     * @return list<string> paths written
     */
    public static function writeMissing(string $configDir): array
    {
        if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
            return [];
        }
        $written = [];
        foreach (self::all() as $group => $contents) {
            $path = $configDir . '/' . $group . '.php';
            if (file_exists($path)) {
                continue;
            }
            $tmp = $path . '.tmp.' . getmypid();
            if (file_put_contents($tmp, $contents, LOCK_EX) !== false && rename($tmp, $path)) {
                $written[] = $path;
            } else {
                @unlink($tmp);
            }
        }

        return $written;
    }
}
