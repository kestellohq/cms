<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Auth\Emails;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\EntryRelations;
use Kestello\Cms\Import\ImportException;
use Kestello\Cms\Media\MediaLibrary;
use Kestello\Cms\System\CoreTasks;
use Kestello\Contracts\Auth\Principal;
use Kestello\Contracts\Cache\Store;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Queue\SyncQueue;
use Kestello\Support\Date;
use Throwable;

/**
 * WordPress imports, from the uploaded export to the finished report.
 *
 *   analyze()  read the export into staging and say what will happen; writes nothing else
 *   start()    begin with the chosen options (authors, files, redirects)
 *   step()     do a slice of the work; called by the admin screen, a queue worker or the CLI
 *   retry()    try the failed items again
 *   cancel()   stop; what was imported stays
 *   discard()  forget an import (not what it imported)
 *
 * The run goes phase by phase: terms, media, entries, links, menus,
 * redirects, finish. Every item is handled on its own: one that fails is
 * reported with its reason and the rest go on.
 *
 * @api
 */
final class WordPressImports
{
    public const array PHASES = ['terms', 'media', 'entries', 'links', 'menus', 'redirects', 'finish'];

    /** @var array<string, list<string>> */
    private const array KINDS = ['terms' => ['term'], 'media' => ['attachment', 'file'], 'entries' => ['entry'], 'menus' => ['menu'], 'redirects' => ['redirect']];

    private const int BATCH = 25;

    /** Tries an item gets when working on it takes the process down. */
    private const int MAX_ATTEMPTS = 3;

    public function __construct(private readonly Cms $cms) {}

    // ── reading ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = QueryBuilder::table('imports')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        return Report::for($row) + ['worker' => $this->hasWorker()];
    }

    /**
     * @return list<array<string, mixed>> the latest imports, newest first
     */
    public function latest(int $limit = 10): array
    {
        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'], 'status' => (string) $r['status'], 'site' => ['title' => (string) $r['site_title'], 'url' => (string) $r['site_url']],
            'created_at' => Date::isoUtc($r['created_at']), 'started_at' => Date::isoUtc($r['started_at']), 'finished_at' => Date::isoUtc($r['finished_at']),
        ], QueryBuilder::table('imports')->orderByDesc('id')->limit($limit)->get());
    }

    // ── analysis ─────────────────────────────────────────────────────

    /**
     * Stage an export. $file is copied (or moved, for an upload's temporary file).
     *
     * @return array<string, mixed> the import (see find())
     * @throws ImportException
     */
    public function analyze(string $file, Principal $p, bool $move = false): array
    {
        $this->authorize($p);
        if (!is_file($file) || (int) filesize($file) === 0) {
            throw new ImportException('The export file is empty.');
        }
        $now = Model::freshTimestamp();
        $id = (int) QueryBuilder::table('imports')->insert([
            'user_id' => $p->userId, 'source' => 'wordpress', 'site_url' => '', 'site_title' => '', 'site_key' => str_repeat('0', 40),
            'status' => 'analyzing', 'options' => '{}', 'summary' => '{}', 'notes' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $dir = $this->dir($id);
        $path = $dir . '/export.xml';
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new ImportException('Could not create a folder for the import.');
            }
            if (!($move ? @rename($file, $path) || (@copy($file, $path) && @unlink($file)) : @copy($file, $path))) {
                throw new ImportException('Could not store the export file.');
            }
            $summary = (new Stager($this->cms->content))->stage($id, $path);
            $summary['authors'] = array_map(fn(array $a) => $a + ['match' => $this->matchUser($a)], $summary['authors']);
            $summary['file'] = ['size' => (int) filesize($path)];
            QueryBuilder::table('imports')->where('id', $id)->update([
                'site_url' => mb_substr((string) $summary['site']['url'], 0, 255), 'site_title' => mb_substr((string) $summary['site']['title'], 0, 255),
                'site_key' => OldSite::key((string) $summary['site']['url']), 'file' => 'imports/' . $id . '/export.xml', 'status' => 'ready',
                'summary' => self::encode($summary), 'updated_at' => Model::freshTimestamp(),
            ]);
        } catch (Throwable $e) {
            $this->remove($id);
            throw $e instanceof ImportException ? $e : new ImportException('The export could not be read: ' . $e->getMessage(), 0, $e);
        }
        $this->cms->audit->record('import.analyzed', ['import', $id], ['site' => $summary['site']['url']], $p->userId);

        return $this->find($id) ?? [];
    }

    /**
     * The user here who most likely is this WordPress author: same address, else same login.
     *
     * @param array<string, mixed> $author
     */
    private function matchUser(array $author): ?int
    {
        $email = trim((string) $author['email']);
        if ($email !== '') {
            $id = QueryBuilder::table('users')->where('email', Emails::normalize($email))->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }
        $id = QueryBuilder::table('users')->where('username', (string) $author['login'])->value('id');

        return $id === null ? null : (int) $id;
    }

    // ── running ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $options see Run
     * @return array<string, mixed>
     * @throws ImportException
     */
    public function start(int $id, array $options, Principal $p): array
    {
        $this->authorize($p);
        $row = $this->row($id);
        if ($row['status'] !== 'ready') {
            throw new ImportException($row['status'] === 'running' ? 'This import is already running.' : 'This import has already run. Upload the export again to import it once more.');
        }
        $clean = $this->options($options);
        $this->becomeRunning($id, ['ready'], [
            'user_id' => $p->userId, 'phase' => self::PHASES[0], 'options' => self::encode($clean),
            'notes' => '{}', 'error' => null, 'started_at' => Model::freshTimestamp(), 'updated_at' => Model::freshTimestamp(),
        ]);
        $this->cms->audit->record('import.started', ['import', $id], ['site' => $row['site_url']], $p->userId);
        $this->queueNext($id);

        return $this->find($id) ?? [];
    }

    /**
     * Do up to $seconds of work. Safe to call from several places at once:
     * only one of them works, the others get the state.
     *
     * @return array<string, mixed>
     */
    public function step(int $id, float $seconds = 10.0): array
    {
        $lock = $this->cache()->lock('import-' . $id, (int) ceil($seconds) + 300);
        if (!$lock->acquire()) {
            // another step is working: the caller waits a little and asks again
            return ['busy' => true] + ($this->find($id) ?? []);
        }
        try {
            $this->work($id, $seconds);
        } catch (Throwable $e) {
            // not one item's problem (those fail on their own): stop, so nothing waits on it forever
            error_log('[import] ' . $id . ': ' . $e);
            $this->stopWithError($id, $e);
        } finally {
            $lock->release();
        }

        return ['busy' => false] + ($this->find($id) ?? []);
    }

    /**
     * Run to the end (the CLI). $progress is told after every slice.
     *
     * @param (callable(array<string, mixed>): void)|null $progress
     * @return array<string, mixed>
     */
    public function runToEnd(int $id, ?callable $progress = null): array
    {
        do {
            $state = $this->step($id, 5.0);
            if ($progress !== null) {
                $progress($state);
            }
            if (($state['busy'] ?? false) && ($state['status'] ?? '') === 'running') {
                usleep(1_000_000);  // a queue worker has this slice
            }
        } while (($state['status'] ?? '') === 'running');

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    public function retry(int $id, Principal $p): array
    {
        $this->authorize($p);
        $row = $this->row($id);
        if (!in_array($row['status'], ['done', 'cancelled'], true)) {
            throw new ImportException('Only a finished or stopped import can be tried again.');
        }
        $this->becomeRunning($id, ['done', 'cancelled'], ['phase' => self::PHASES[0], 'error' => null, 'finished_at' => null, 'updated_at' => Model::freshTimestamp()], static function () use ($id): void {
            QueryBuilder::table('import_items')->where('import_id', $id)->where('result', 'failed')->update(['state' => 'pending', 'result' => null, 'message' => null, 'attempts' => 0]);
            // posts may link to what failed and has come over now: convert those that came over again
            QueryBuilder::table('import_items')->where('import_id', $id)->where('kind', 'entry')->whereIn('result', ['created', 'updated', 'unchanged'])->update(['needs_links' => true]);
            // redirects and menus follow what now exists: look at them again
            QueryBuilder::table('import_items')->where('import_id', $id)->whereIn('kind', ['redirect', 'menu'])->where('result', '!=', 'failed')
                ->where(static fn(QueryBuilder $q) => $q->where('message', '!=', 'The old address is the home page.')->orWhereNull('message'))->update(['state' => 'pending', 'result' => null, 'message' => null, 'attempts' => 0]);
        });
        $this->queueNext($id);

        return $this->find($id) ?? [];
    }

    /**
     * Stop. What was imported stays; Try again carries on.
     *
     * @return array<string, mixed>
     */
    public function cancel(int $id, Principal $p): array
    {
        $this->authorize($p);
        $this->row($id);
        $stopped = QueryBuilder::table('imports')->where('id', $id)->where('status', 'running')->update(['status' => 'cancelled', 'finished_at' => Model::freshTimestamp(), 'updated_at' => Model::freshTimestamp()]);
        if ($stopped > 0) {
            QueryBuilder::table('import_items')->where('import_id', $id)->where('state', 'pending')->update(['state' => 'done', 'result' => 'failed', 'message' => 'The import was stopped before this.']);
            $this->cms->audit->record('import.cancelled', ['import', $id], [], $p->userId);
        }

        return $this->find($id) ?? [];
    }

    /** Forget an import and its file. What it imported stays, and so does what a later import of the same site needs. */
    public function discard(int $id, Principal $p): void
    {
        $this->authorize($p);
        if ($this->row($id)['status'] === 'running') {
            throw new ImportException('Stop the import first.');
        }
        $this->remove($id);
    }

    // ── the work ─────────────────────────────────────────────────────

    private function work(int $id, float $seconds): void
    {
        $row = QueryBuilder::table('imports')->where('id', $id)->first();
        if ($row === null || $row['status'] !== 'running') {
            return;
        }
        $user = $row['user_id'] === null ? null : QueryBuilder::table('users')->where('id', $row['user_id'])->first();
        if ($user === null || !(bool) $user['is_active']) {
            $this->finish($id, 'cancelled', null);
            return;
        }
        $principal = $this->cms->auth->principalFor($user, 'cli');
        if (!$principal->can('import.run')) {
            $this->finish($id, 'cancelled', null);
            return;
        }
        $site = new OldSite((string) $row['site_url']);
        $map = new SourceMap((string) $row['site_key']);
        $links = new ImportLinks($id, $site, $map, $this->cms->content);
        $options = json_decode((string) $row['options'], true);
        $notes = new Notes();
        $run = new Run($this->cms, $id, $site, $map, $links, $principal, is_array($options) ? $options : [], $notes);

        $media = $this->cms->container->make(MediaLibrary::class);
        $downloader = new Downloader($this->cms->container->make(HttpClient::class), $this->dir($id) . '/tmp', $media->maxBytes(), is_string($run->option('uploads_dir')) ? $run->option('uploads_dir') : null);
        $entries = new EntryStep($run, new ContentConverter($links));
        $steps = [
            'term' => new TermStep($run),
            'attachment' => $mediaStep = new MediaStep($run, $downloader),
            'file' => $mediaStep,
            'entry' => $entries,
            'menu' => new MenuStep($run),
            'redirect' => new RedirectStep($run),
        ];

        $deadline = microtime(true) + $seconds;
        $phase = in_array($row['phase'], self::PHASES, true) ? (string) $row['phase'] : self::PHASES[0];
        try {
            do {    // at least one batch, so every slice moves on
                if ($phase === 'finish') {
                    $this->complete($id, $principal);
                    return;
                }
                $worked = $phase === 'links' ? $this->relink($id, $entries, $deadline) : $this->batch($id, self::KINDS[$phase], $steps, $deadline);
                if (!$worked) {
                    $phase = self::PHASES[array_search($phase, self::PHASES, true) + 1];
                    QueryBuilder::table('imports')->where('id', $id)->update(['phase' => $phase, 'updated_at' => Model::freshTimestamp()]);
                }
                if (QueryBuilder::table('imports')->where('id', $id)->value('status') !== 'running') {
                    return;     // stopped meanwhile
                }
            } while (microtime(true) < $deadline);
        } finally {
            $this->addNotes($id, $notes->all());
        }
    }

    /**
     * One batch of pending items of a phase. False when there were none.
     *
     * @param list<string> $kinds
     * @param array<string, TermStep|MediaStep|EntryStep|MenuStep|RedirectStep> $steps
     */
    private function batch(int $id, array $kinds, array $steps, float $deadline): bool
    {
        $items = QueryBuilder::table('import_items')->where('import_id', $id)->whereIn('kind', $kinds)->where('state', 'pending')
            ->orderBy('depth')->orderBy('id')->limit(self::BATCH)->get();
        if ($items === []) {
            return false;
        }
        foreach ($items as $item) {
            // counted before the work: an item that takes the whole process down (memory, a time limit) is given up after a few tries
            $claimed = QueryBuilder::table('import_items')->where('id', $item['id'])->where('state', 'pending')->update(['attempts' => (int) $item['attempts'] + 1]);
            if ($claimed === 0) {
                continue;   // stopped meanwhile
            }
            if ((int) $item['attempts'] >= self::MAX_ATTEMPTS) {
                $outcome = Outcome::failed('Importing this stopped the server each time it was tried (it may be too large for this server).');
            } else {
                $payload = json_decode((string) $item['payload'], true);
                try {
                    $outcome = $steps[(string) $item['kind']]->process($item, is_array($payload) ? $payload : []);
                } catch (Throwable $e) {
                    $outcome = Outcome::failed(Run::describe($e));
                }
            }
            QueryBuilder::table('import_items')->where('id', $item['id'])->update([
                'state' => 'done', 'result' => $outcome->result, 'message' => $outcome->message === null ? null : mb_substr($outcome->message, 0, 500),
                'target_id' => $outcome->targetId, 'needs_links' => $outcome->needsLinks,
            ]);
            if (microtime(true) >= $deadline) {
                break;
            }
        }

        return true;
    }

    /** Entries that linked to items imported after them, converted again. False when none are left. */
    private function relink(int $id, EntryStep $entries, float $deadline): bool
    {
        $items = QueryBuilder::table('import_items')->where('import_id', $id)->where('kind', 'entry')->where('needs_links', true)->orderBy('id')->limit(self::BATCH)->get();
        if ($items === []) {
            return false;
        }
        foreach ($items as $item) {
            $payload = json_decode((string) $item['payload'], true);
            try {
                $entries->relink($item, is_array($payload) ? $payload : []);
            } catch (Throwable $e) {
                error_log('[import] links of item ' . $item['id'] . ': ' . $e->getMessage());    // the entry is there; only some links still point at the old site
            }
            QueryBuilder::table('import_items')->where('id', $item['id'])->update(['needs_links' => false]);
            if (microtime(true) >= $deadline) {
                break;
            }
        }

        return true;
    }

    private function complete(int $id, Principal $p): void
    {
        try {
            $this->cms->container->make(EntryRelations::class)->rebuild();
        } catch (Throwable $e) {
            error_log('[import] relations: ' . $e->getMessage());
        }
        $this->finish($id, 'done', $p);
    }

    private function finish(int $id, string $status, ?Principal $p): void
    {
        $row = $this->row($id);
        // the staged items hold everything a retry needs: the export itself can go
        QueryBuilder::table('imports')->where('id', $id)->update(['status' => $status, 'phase' => null, 'file' => null, 'finished_at' => Model::freshTimestamp(), 'updated_at' => Model::freshTimestamp()]);
        self::removeDir($this->dir($id));
        $report = Report::for($this->row($id));
        $this->cms->audit->record('import.' . ($status === 'done' ? 'finished' : 'cancelled'), ['import', $id], [
            'site' => $row['site_url'],
            'counts' => array_map(static fn(array $c) => array_filter($c, static fn(int $n) => $n > 0), $report['counts']),
        ], $p?->userId);
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    private function options(array $in): array
    {
        $errors = [];
        $authors = [];
        foreach ((array) ($in['authors'] ?? []) as $login => $userId) {
            if ($userId === null || $userId === '' || $userId === 0) {
                continue;
            }
            if (!is_numeric($userId) || !QueryBuilder::table('users')->where('id', (int) $userId)->exists()) {
                $errors[] = sprintf('No person here for the WordPress author "%s".', $login);
                continue;
            }
            $authors[(string) $login] = (int) $userId;
        }
        $default = $in['default_author'] ?? null;
        if ($default !== null && $default !== '' && (!is_numeric($default) || !QueryBuilder::table('users')->where('id', (int) $default)->exists())) {
            $errors[] = 'The person to credit unmatched posts to has no account here.';
        }
        $uploads = $in['uploads_dir'] ?? null;
        if ($uploads !== null && (!is_string($uploads) || !is_dir($uploads) || !is_readable($uploads))) {
            $errors[] = 'The uploads folder could not be read.';
        }
        if ($errors !== []) {
            throw new ImportException(implode(' ', $errors));
        }

        return [
            'authors' => $authors,
            'default_author' => $default === null || $default === '' ? null : (int) $default,
            'media' => filter_var($in['media'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'redirects' => filter_var($in['redirects'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'uploads_dir' => is_string($uploads) ? (realpath($uploads) ?: $uploads) : null,
        ];
    }

    /**
     * @param array<string, int> $notes
     */
    private function addNotes(int $id, array $notes): void
    {
        if ($notes === []) {
            return;
        }
        $current = json_decode((string) QueryBuilder::table('imports')->where('id', $id)->value('notes'), true);
        $current = is_array($current) ? $current : [];
        foreach ($notes as $key => $count) {
            $current[$key] = (int) ($current[$key] ?? 0) + $count;
        }
        ksort($current);
        QueryBuilder::table('imports')->where('id', $id)->update(['notes' => self::encode($current)]);
    }

    private function authorize(Principal $p): void
    {
        if (!$p->can('import.run') || $p->userId === null) {
            throw new ImportException('Only administrators can import sites.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        return QueryBuilder::table('imports')->where('id', $id)->first() ?? throw new ImportException('No such import.');
    }

    private function dir(int $id): string
    {
        return $this->cms->storage->root() . '/imports/' . $id;
    }

    private function remove(int $id): void
    {
        QueryBuilder::table('import_items')->where('import_id', $id)->delete();
        QueryBuilder::table('imports')->where('id', $id)->delete();
        self::removeDir($this->dir($id));
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Mark an import running, when no other one is: one at a time, since two
     * imports of the same site would both create what neither has mapped yet.
     *
     * @param list<string> $from the statuses it may be in now
     * @param array<string, mixed> $values
     * @param (\Closure(): void)|null $before done once the way is clear, before it counts as running
     */
    private function becomeRunning(int $id, array $from, array $values, ?\Closure $before = null): void
    {
        $lock = $this->cache()->lock('imports-running', 30);
        if (!$lock->acquire()) {
            throw new ImportException('Another import is starting. Try again in a moment.');
        }
        try {
            if (QueryBuilder::table('imports')->where('status', 'running')->exists()) {
                throw new ImportException('Another import is running. Wait for it to finish, or stop it.');
            }
            if ($before !== null) {
                $before();
            }
            $changed = QueryBuilder::table('imports')->where('id', $id)->whereIn('status', $from)->update(['status' => 'running'] + $values);
            if ($changed === 0) {
                throw new ImportException('This import changed meanwhile. Reload the page.');
            }
        } finally {
            $lock->release();
        }
    }

    /** Stop an import that failed as a whole, keeping why, so it can be tried again. */
    private function stopWithError(int $id, Throwable $e): void
    {
        QueryBuilder::table('imports')->where('id', $id)->where('status', 'running')->update([
            'status' => 'cancelled', 'error' => mb_substr(Run::describe($e), 0, 500), 'finished_at' => Model::freshTimestamp(), 'updated_at' => Model::freshTimestamp(),
        ]);
    }

    private function hasWorker(): bool
    {
        return !$this->cms->queue->backend() instanceof SyncQueue;
    }

    /** Queue the next slice for a worker; the admin screen and the CLI step by themselves. */
    public function queueNext(int $id, int $delay = 0): void
    {
        if ($this->hasWorker()) {
            $this->cms->queue->push(new RunImport($id), $delay, 'default');
        }
    }

    /** The site's cache, for the locks that keep two steps (or two starts) from working at once. */
    private function cache(): Store
    {
        return CoreTasks::cache($this->cms);
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
