<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Package\PackageInstaller;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Framework\Console\Command;

/** package:verify <zip>: what it is, who signed it, what plugin:check says. Installs nothing. */
final class PackageVerifyCommand extends Command
{
    protected string $name = 'package:verify';
    protected string $description = 'Check a package zip (signature, manifest, plugin:check) without installing it';

    public function handle(array $arguments, array $options): int
    {
        $zip = $this->argument($arguments, 0);
        if ($zip === null || !is_file($zip)) {
            $this->output->error('  Usage: package:verify <zip>');
            return 1;
        }
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        try {
            $info = (new PackageInstaller($cms))->inspect($zip);
        } catch (ContentException $e) {
            $this->output->error('  ' . implode("\n  ", $e->errors !== [] ? array_merge(...array_values($e->errors)) : [$e->getMessage()]));
            return 1;
        }
        $this->output->label('Package', "{$info['name']} {$info['version']} ({$info['type']} {$info['slug']})");
        $sig = $info['signature'];
        $this->output->label('Signature', match ($sig['status']) {
            PackageSignature::SIGNED => "signed by {$sig['name']} ({$sig['key']})",
            PackageSignature::UNTRUSTED => "signed by key {$sig['key']}, which this site does not trust",
            PackageSignature::TAMPERED => 'DOES NOT MATCH: changed after signing',
            default => 'not signed',
        });
        $this->output->label('Installed', $info['installed'] === null ? 'no' : "version {$info['installed']['version']}" . ($info['installed']['active'] ? ', active' : ''));
        foreach ($info['findings'] as $f) {
            ($f['level'] === 'error' ? $this->output->error(...) : $this->output->warning(...))("  {$f['file']}:{$f['line']} {$f['message']}");
        }

        return $sig['status'] === PackageSignature::TAMPERED || array_filter($info['findings'], static fn(array $f): bool => $f['level'] === 'error') !== [] ? 1 : 0;
    }
}
