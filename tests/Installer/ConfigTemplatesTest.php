<?php

declare(strict_types=1);

namespace Kestello\Tests\Installer;

use Kestello\Cms\Console\ConfigPublishCommand;
use Kestello\Cms\Installer\ConfigTemplates;
use Kestello\Framework\Console\Output;
use Kestello\Framework\Testing\TestCase;

/** The commented config files new sites get: valid PHP, the code's own defaults, never overwriting. */
final class ConfigTemplatesTest extends TestCase
{
    public function testTemplatesHoldTheDefaultsTheCodeUses(): void
    {
        $dir = $this->tempDir();
        $this->assertCount(4, ConfigTemplates::writeMissing($dir));
        $load = static fn(string $group): array => require "{$dir}/{$group}.php";

        // Must match the defaults where each key is read (CoreTasks, ScheduleRunner, AuthModule, ExternalAuthPolicy).
        $this->assertSame(['web_runner' => 'auto', 'web_runner_seconds' => 20, 'http_seconds' => 25], $load('cron'));
        $this->assertSame(['worker' => 'cron', 'failed_retention_days' => 30], $load('queue'));
        $this->assertSame(['retention_days' => 365], $load('audit'));
        $this->assertSame([
            'password_min_length' => 12, 'revoke_on_password_change' => true, 'two_factor_roles' => ['admin'],
            'registration' => 'closed', 'registration_domains' => [], 'default_role' => 'user',
            'link_by_verified_email' => false, 'trust_provider_mfa' => false,
        ], $load('auth'));
        foreach (ConfigTemplates::all() as $group => $contents) {
            $this->assertFalse(str_contains($contents, '—') || str_contains($contents, '–'), "{$group}: plain punctuation");
        }
    }

    public function testASitesOwnFileIsNeverTouched(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/queue.php", "<?php return ['worker' => 'daemon'];\n");
        $written = ConfigTemplates::writeMissing($dir);
        $this->assertSame(['cron.php', 'auth.php', 'audit.php'], array_map('basename', $written));
        $this->assertSame(['worker' => 'daemon'], require "{$dir}/queue.php");

        $out = fopen('php://memory', 'w+');
        $command = new ConfigPublishCommand($dir);
        $command->setOutput(new Output($out, false));
        $this->assertSame(0, $command->handle([], []));
        rewind($out);
        $this->assertTrue(str_contains((string) stream_get_contents($out), 'Nothing to add'), 'a second run adds nothing');
    }
}
