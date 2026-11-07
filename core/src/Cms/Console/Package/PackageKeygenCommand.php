<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Package\PackageSignature;
use Kestello\Framework\Console\Command;

/** package:keygen --out=<file>: a publisher signing key. The secret stays with the publisher, never on a site. */
final class PackageKeygenCommand extends Command
{
    protected string $name = 'package:keygen';
    protected string $description = 'Make an Ed25519 key for signing packages: package:keygen --out=<secret-key-file> [--name=…]';

    public function handle(array $arguments, array $options): int
    {
        $out = $this->option($options, 'out');
        if ($out === null || $out === '') {
            $this->output->error('  Usage: package:keygen --out=<secret-key-file> [--name="Acme Plugins"]');
            return 1;
        }
        if (file_exists($out)) {
            $this->output->error("  {$out} exists; choose another file.");
            return 1;
        }
        $keys = PackageSignature::generateKeys();
        $name = (string) $this->option($options, 'name', 'Package signing key');
        $written = @file_put_contents($out, json_encode(['id' => $keys['id'], 'name' => $name, 'secret_key' => $keys['secret_key']], JSON_PRETTY_PRINT) . "\n");
        if ($written === false) {
            $this->output->error("  Could not write {$out}.");
            return 1;
        }
        @chmod($out, 0600);
        $this->output->success("  Secret key written to {$out} (key {$keys['id']}). Keep it off your sites and out of git.");
        $this->output->newLine();
        $this->output->line('  Sites that should trust packages signed with it add this to config/app.php:');
        $this->output->line("    'packages' => ['trusted_keys' => [['name' => " . var_export($name, true) . ", 'public_key' => '{$keys['public_key']}']]],");

        return 0;
    }
}
