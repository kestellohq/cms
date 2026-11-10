<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Cms;
use Kestello\Cms\Import\ImportException;
use Kestello\Cms\Import\WordPress\WordPressImports;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\QueryBuilder;

/**
 * import:wordpress <export.xml> [--as=username] [--author=username] [--uploads=path]
 *                  [--no-media] [--no-redirects] [--dry-run] [--yes]
 *
 * The same import as System → Import, for exports too large to upload and
 * for sites whose old host is gone (--uploads reads files from a copy of
 * wp-content/uploads). Exit code 0 when everything came over, 2 when some
 * items failed (listed, and they can be tried again in System → Import).
 */
final class ImportWordPressCommand extends Command
{
    protected string $name = 'import:wordpress';
    protected string $description = 'Import a WordPress export: import:wordpress <export.xml> [--as=user] [--author=user] [--uploads=path] [--no-media] [--no-redirects] [--dry-run] [--yes]';

    private const array LABELS = ['entry' => 'Posts and pages', 'attachment' => 'Files', 'file' => 'Files linked from posts', 'term' => 'Categories and tags', 'menu' => 'Menus', 'redirect' => 'Redirects'];

    public function handle(array $arguments, array $options): int
    {
        $file = $this->argument($arguments, 0);
        if ($file === null || !is_file($file)) {
            $this->output->error('  Usage: import:wordpress <export.xml>  (make the file in WordPress under Tools → Export → All content)');
            return 1;
        }
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $imports = $cms->container->make(WordPressImports::class);

        $runner = $this->user($this->option($options, 'as'));
        if ($runner === null) {
            return 1;
        }
        $principal = $cms->auth->principalFor($runner, 'cli');
        $options['author'] = $this->option($options, 'author');
        $default = $options['author'] === null ? null : $this->user((string) $options['author'], adminOnly: false);
        if ($options['author'] !== null && $default === null) {
            return 1;
        }

        try {
            $import = $imports->analyze((string) realpath($file), $principal);
        } catch (ImportException $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }
        $this->summary($import);

        if ($this->hasFlag($options, 'dry-run')) {
            $imports->discard($import['id'], $principal);
            $this->output->muted('  Dry run: nothing was imported.');
            return 0;
        }
        if (!$this->hasFlag($options, 'yes') && !$this->output->confirm('Import it as ' . $runner['username'] . '?', true)) {
            $imports->discard($import['id'], $principal);
            $this->output->muted('  Nothing was imported.');
            return 1;
        }

        $authors = [];
        foreach ($import['summary']['authors'] as $a) {
            if ($a['match'] !== null) {
                $authors[$a['login']] = $a['match'];
            }
        }
        try {
            $imports->start($import['id'], [
                'authors' => $authors,
                'default_author' => $default === null ? null : (int) $default['id'],
                'media' => !$this->hasFlag($options, 'no-media'),
                'redirects' => !$this->hasFlag($options, 'no-redirects'),
                'uploads_dir' => $this->option($options, 'uploads'),
            ], $principal);
        } catch (ImportException $e) {
            $imports->discard($import['id'], $principal);
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }

        $last = '';
        $report = $imports->runToEnd($import['id'], function (array $state) use (&$last): void {
            $line = sprintf('  %s: %d of %d', ucfirst((string) ($state['phase'] ?? 'finishing')), $state['progress']['done'] ?? 0, $state['progress']['total'] ?? 0);
            if ($line !== $last) {
                $this->output->muted($line);
                $last = $line;
            }
        });

        return $this->report($report);
    }

    /**
     * The person the import runs as: --as, else the site's only administrator.
     *
     * @return array<string, mixed>|null
     */
    private function user(?string $username, bool $adminOnly = true): ?array
    {
        [$user, $error] = CommandUser::find($username, 'import sites', 'imports it', $adminOnly);
        if ($error !== null) {
            $this->output->error('  ' . $error);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $import
     */
    private function summary(array $import): void
    {
        $s = $import['summary'];
        $this->output->label('Site', trim($import['site']['title'] . '  ' . $import['site']['url']));
        $bytes = (int) ($s['file']['size'] ?? 0);
        $this->output->label('Export', 'WordPress export ' . ($s['site']['version'] ?? '?') . ', ' . ($bytes >= 1_048_576 ? round($bytes / 1_048_576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB'));
        $rows = [];
        foreach ($import['counts'] as $kind => $c) {
            if (isset(self::LABELS[$kind])) {
                $rows[] = [self::LABELS[$kind], (string) ($c['total'] - $c['skipped']), (string) $c['skipped']];
            }
        }
        $this->output->newLine();
        $this->output->table(['', 'To import', 'Skipped'], $rows);
        $authors = array_map(fn(array $a) => [$a['login'], $a['name'], $a['match'] === null ? 'no account here: credited to the importer' : (string) QueryBuilder::table('users')->where('id', $a['match'])->value('username')], $s['authors']);
        if ($authors !== []) {
            $this->output->newLine();
            $this->output->table(['Author', 'Name', 'Credited to'], $authors);
        }
        foreach ($import['skipped'] as $group) {
            $this->output->muted(sprintf('  Skipped %d: %s (%s)', $group['count'], $group['reason'], implode(', ', $group['examples'])));
        }
        if (($s['comments'] ?? 0) > 0) {
            $this->output->muted(sprintf('  %d %s not imported: Kestello has no visitor comments.', $s['comments'], $s['comments'] === 1 ? 'comment is' : 'comments are'));
        }
        $this->output->newLine();
    }

    /**
     * @param array<string, mixed> $report
     */
    private function report(array $report): int
    {
        $this->output->newLine();
        $rows = [];
        foreach ($report['counts'] as $kind => $c) {
            if (isset(self::LABELS[$kind])) {
                $rows[] = [self::LABELS[$kind], (string) $c['created'], (string) $c['updated'], (string) ($c['unchanged'] + $c['matched']), (string) $c['kept'], (string) $c['skipped'], (string) $c['failed']];
            }
        }
        $this->output->table(['', 'New', 'Updated', 'Same', 'Kept', 'Skipped', 'Failed'], $rows);
        foreach ($report['notices'] as $n) {
            $this->output->info("  {$n['label']}: {$n['message']}");
        }
        $failed = 0;
        foreach ($report['problems'] as $p) {
            if ($p['result'] === 'failed') {
                $failed++;
                $this->output->error("  {$p['label']}: {$p['message']}");
            } else {
                $this->output->warning("  {$p['label']}: {$p['message']}");
            }
        }
        foreach ($report['conversion'] as $note) {
            $this->output->muted("  {$note['count']} × {$note['text']}");
        }
        if ($report['status'] !== 'done') {
            $this->output->error('  The import stopped before the end.');
            return 1;
        }
        $this->output->newLine();
        if ($failed > 0) {
            $this->output->warning("  Done, with {$failed} item(s) that could not be imported. Try them again in System → Import.");
            return 2;
        }
        $this->output->success('  Done.');

        return 0;
    }
}
