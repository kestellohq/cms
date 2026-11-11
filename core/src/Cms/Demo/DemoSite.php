<?php

declare(strict_types=1);

namespace Kestello\Cms\Demo;

use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Cms;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Hash;

/**
 * The demo's snapshot and its hourly reset.
 *
 * The first time a demo site boots, it gets its "demo" account and, when
 * it has no content yet, the sample studio (DemoContent): its content types,
 * languages and theme on that boot, its content on the next one, which
 * registers the types. Then a backup of the whole site is taken: the
 * snapshot. Every RESET_EVERY seconds the
 * scheduler restores it, so visitors' changes and uploads are gone and
 * everyone is signed out.
 *
 * storage/framework/demo.json holds the snapshot's name and when the site
 * was last reset.
 */
final class DemoSite
{
    public const string NOTE = 'Demo snapshot: the demo goes back to this every hour.';

    public function __construct(
        private readonly Cms $cms,
        private readonly Backups $backups,
    ) {}

    /** @return array{snapshot: ?string, reset_at: int} */
    public function state(): array
    {
        $data = is_file($this->file()) ? json_decode((string) @file_get_contents($this->file()), true) : null;

        return [
            'snapshot' => is_array($data) && is_string($data['snapshot'] ?? null) ? $data['snapshot'] : null,
            'reset_at' => is_array($data) ? (int) ($data['reset_at'] ?? 0) : 0,
        ];
    }

    /** When the next reset is due (unix seconds), or null before the first snapshot. */
    public function nextReset(): ?int
    {
        $state = $this->state();

        return $state['snapshot'] === null ? null : $state['reset_at'] + Demo::RESET_EVERY;
    }

    public function exists(string $snapshot): bool
    {
        return $this->backups->exists($snapshot);
    }

    public function due(?int $now = null): bool
    {
        $next = $this->nextReset();

        return $next !== null && ($now ?? time()) >= $next;
    }

    /**
     * Make sure the demo is ready: its account, its content and its
     * snapshot. Cheap when it is (one file read); otherwise only one request
     * does the work, the others carry on.
     *
     * @return bool whether a snapshot was taken now
     */
    public function ensure(): bool
    {
        $snapshot = $this->state()['snapshot'];
        if ($snapshot !== null && $this->backups->exists($snapshot)) {
            return false;
        }
        $lock = @fopen($this->cms->paths->storage('framework/demo.lock'), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return false;
        }
        try {
            $author = $this->cms->auth->principalFor($this->account(), 'cli');
            if (QueryBuilder::table('entries')->count() === 0) {
                if (!DemoContent::ready($this->cms)) {
                    DemoContent::prepare($this->cms, $author);
                    $this->theme(DemoContent::data()['theme']);

                    return false;   // the content types it built are registered on the next boot, which seeds
                }
                DemoContent::seed($this->cms, $author);
            }
            $this->snapshot();

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Switch to the demo's theme when it is installed (Exhibit ships with core); otherwise keep the active one. */
    private function theme(string $slug): void
    {
        $extensions = $this->cms->extensions();
        if (isset($extensions->themes()[$slug]) && $extensions->activeTheme()?->slug() !== $slug) {
            $extensions->activateTheme($slug);
        }
    }

    /**
     * Take the snapshot from the site as it is now (demo:snapshot): what
     * every reset goes back to. The previous snapshot is removed. The demo
     * account goes in as visitors sign in with it, whatever its owner did
     * to it while working on the demo.
     */
    public function snapshot(): string
    {
        $user = $this->account();
        QueryBuilder::table('users')->where('id', $user['id'])->update(['password' => Hash::password(Demo::PASSWORD), 'role' => 'admin', 'is_active' => 1]);
        $old = $this->state()['snapshot'];
        $name = $this->backups->create(['files' => true, 'kind' => 'manual', 'note' => self::NOTE])['name'];
        $this->write(['snapshot' => $name, 'reset_at' => time()]);
        if ($old !== null && $old !== $name && $this->backups->exists($old)) {
            $this->backups->delete($old);
        }

        return $name;
    }

    /** Put the site back to its snapshot: everything visitors changed or uploaded is gone, and everyone is signed out. */
    public function reset(): string
    {
        $snapshot = $this->state()['snapshot'];
        if ($snapshot === null || !$this->backups->exists($snapshot)) {
            throw new \RuntimeException('The demo has no snapshot to go back to. Run bin/cms demo:snapshot.');
        }
        $this->backups->restoreNamed($snapshot, ['safety' => false]);
        $this->write(['snapshot' => $snapshot, 'reset_at' => time()]);

        return $snapshot;
    }

    /**
     * The shared "demo" account, made when it is missing. It is an
     * administrator so every screen can be seen; DemoPolicy decides what it
     * may change.
     *
     * @return array<string, mixed>
     */
    private function account(): array
    {
        $row = QueryBuilder::table('users')->where('username', Demo::USERNAME)->first();
        if ($row === null) {
            $now = date('Y-m-d H:i:s');
            QueryBuilder::table('users')->insert([
                'username' => Demo::USERNAME,
                'email' => Demo::EMAIL,
                'password' => Hash::password(Demo::PASSWORD),
                'role' => 'admin',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = QueryBuilder::table('users')->where('username', Demo::USERNAME)->first();
        }

        return (array) $row;
    }

    /** @param array{snapshot: string, reset_at: int} $state */
    private function write(array $state): void
    {
        $tmp = $this->file() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($state, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $this->file())) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write storage/framework/demo.json.');
        }
    }

    private function file(): string
    {
        return $this->cms->paths->storage('framework/demo.json');
    }
}
