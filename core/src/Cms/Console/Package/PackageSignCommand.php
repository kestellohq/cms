<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Package\PackageSignature;
use Kestello\Framework\Console\Command;

/** package:sign <zip> --key=<secret-key-file> */
final class PackageSignCommand extends Command
{
    protected string $name = 'package:sign';
    protected string $description = 'Sign a package zip: package:sign <zip> --key=<secret-key-file>';

    public function handle(array $arguments, array $options): int
    {
        $zip = $this->argument($arguments, 0);
        $key = $this->option($options, 'key');
        if ($zip === null || !is_file($zip) || $key === null) {
            $this->output->error('  Usage: package:sign <zip> --key=<secret-key-file>');
            return 1;
        }
        try {
            PackageSignature::sign($zip, SignKey::read($key));
            if (is_file($zip . '.sha256')) {
                file_put_contents($zip . '.sha256', hash_file('sha256', $zip) . '  ' . basename($zip) . "\n");
            }
        } catch (\Throwable $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }
        $this->output->success("  Signed {$zip}");

        return 0;
    }
}
