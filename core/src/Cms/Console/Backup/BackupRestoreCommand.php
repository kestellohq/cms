<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Backup;

/**
 * backup:restore <name|file.zip> [--yes] [--with-code] [--no-safety]
 *
 * A file path restores an archive from elsewhere (site transfer). Plugin and
 * theme folders come back only from archives this site made, or with
 * --with-code: they are code that will run here.
 */
final class BackupRestoreCommand extends BackupCommand
{
    protected string $name = 'backup:restore';
    protected string $description = 'Replace this site with a backup: backup:restore <name|file.zip> [--yes] [--with-code] [--no-safety]';

    public function handle(array $arguments, array $options): int
    {
        $what = $this->argument($arguments, 0);
        if ($what === null) {
            $this->output->error('  Usage: backup:restore <name|file.zip> [--yes] [--with-code] [--no-safety]');
            return 1;
        }
        $backups = $this->backups();
        if (is_file($what)) {
            return $this->restoreFrom($backups, (string) realpath($what), $options);
        }
        try {
            // a backup on a remote disk is fetched for this and removed after
            return $backups->withLocalCopy($what, fn(string $path): int => $this->restoreFrom($backups, $path, $options));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    /** @param array<string, mixed> $options */
    private function restoreFrom(\Kestello\Cms\Backup\Backups $backups, string $path, array $options): int
    {
        try {
            $check = $backups->inspect($path);
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        $m = $check['manifest'];
        $this->output->label('Made', date('Y-m-d H:i', (int) $m['created_at']) . " on Kestello {$m['core']} ({$m['driver']})");
        $this->output->label('Site', trim(($m['site']['name'] ?? '') . ' ' . ($m['site']['url'] ?? '')));
        $this->output->label('Rows', (string) array_sum(array_column($m['tables'], 'rows')) . ' in ' . count($m['tables']) . ' tables');
        $this->output->label('Files', ($m['sections'] ?? []) === [] ? 'none (database only)' : implode(', ', array_keys($m['sections'])));
        $this->output->label('Made here', $check['signed'] ? 'yes' : 'no: plugin and theme folders are skipped unless --with-code');
        foreach ($check['warnings'] as $w) {
            $this->output->warning('  ' . $w);
        }
        $this->output->newLine();
        if (!$this->hasFlag($options, 'yes') && !$this->output->confirm('This replaces the whole database' . (($m['sections'] ?? []) !== [] ? ' and those folders' : '') . '. Continue?')) {
            $this->output->muted('  Nothing changed.');
            return 1;
        }

        try {
            $r = $backups->restore($path, ['code' => $this->hasFlag($options, 'with-code'), 'safety' => !$this->hasFlag($options, 'no-safety')]);
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
        $this->output->success("  Restored {$r['rows']} rows in {$r['tables']} tables.");
        if ($r['safety'] !== null) {
            $this->output->muted("  The site as it was is in {$r['safety']}.");
        }
        if ($r['skipped_code']) {
            $this->output->warning('  Plugin and theme folders were not restored (not made on this site; use --with-code to restore them).');
        }
        if ($r['missing_tables'] !== []) {
            $this->output->warning('  Rows not restored, their plugin is missing: ' . implode(', ', $r['missing_tables']));
        }
        foreach ($r['dropped_columns'] as $table => $columns) {
            $this->output->warning("  {$table}: columns this site no longer has were left out: " . implode(', ', $columns));
        }
        $this->output->muted('  Everyone was signed out. The search index is being rebuilt.');

        return 0;
    }
}
