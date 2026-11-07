<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\Update\CoreRelease;
use Kestello\Cms\Package\Update\Files;
use Kestello\Cms\Package\Update\ReleaseFeed;
use Kestello\Cms\Package\Update\UpdateChecker;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Paths;

/**
 * core:verify <zip>: is this a core release signed by one of the release keys in
 * core/config/release.php, and does it match its .sha256 file? Sites trust exactly those keys,
 * so the release workflow runs this before publishing anything.
 */
final class CoreVerifyCommand extends Command
{
    protected string $name = 'core:verify';
    protected string $description = 'Check a core release zip is signed by a release key (core/config/release.php): core:verify <zip>';

    /** Works on a checkout that was never installed (the release workflow runs it). */
    public function __construct(private readonly Paths $paths) {}

    public function handle(array $arguments, array $options): int
    {
        $zip = $this->argument($arguments, 0);
        if ($zip === null || !is_file($zip)) {
            $this->output->error('  Usage: core:verify <zip>');
            return 1;
        }
        $config = is_file($this->paths->coreConfig() . '/release.php') ? require $this->paths->coreConfig() . '/release.php' : [];
        $keys = UpdateChecker::releaseKeys(is_array($config) ? ($config['keys'] ?? []) : []);
        if ($keys === []) {
            $this->output->error('  core/config/release.php lists no release keys, so no site would trust this release. Add the public half of the signing key there.');
            return 1;
        }

        $sums = $zip . '.sha256';
        if (is_file($sums) && ReleaseFeed::checksum((string) file_get_contents($sums)) !== hash_file('sha256', $zip)) {
            $this->output->error("  {$sums} does not match the zip. Build the release again.");
            return 1;
        }

        $tmp = sys_get_temp_dir() . '/kestello-verify-' . bin2hex(random_bytes(6));
        try {
            $staged = CoreRelease::stage($zip, $tmp, $keys);
        } catch (ContentException $e) {
            $this->output->error('  ' . implode("\n  ", $e->errors !== [] ? array_merge(...array_values($e->errors)) : [$e->getMessage()]));
            return 1;
        } finally {
            is_dir($tmp) && Files::removeTree($tmp);
        }

        $sig = $staged['signature'];
        $this->output->label('Release', "Kestello {$staged['version']}");
        $this->output->label('Signature', match ($sig['status']) {
            PackageSignature::SIGNED => "signed by {$sig['name']} ({$sig['key']})",
            PackageSignature::UNTRUSTED => "signed by key {$sig['key']}, which is not in core/config/release.php",
            PackageSignature::TAMPERED => 'DOES NOT MATCH: changed after signing',
            default => 'not signed',
        });

        return $sig['status'] === PackageSignature::SIGNED ? 0 : 1;
    }
}
