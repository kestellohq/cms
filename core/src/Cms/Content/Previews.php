<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\Caster;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Clock;

/**
 * Links that show an entry as it is saved, whatever its status, at
 * /preview/<token>.
 *
 * - An editor link lasts an hour and only opens for the person who made
 *   it. With their newer autosave, it shows the unsaved changes too. The
 *   editor's Preview button makes one each time.
 * - A share link lasts 1 to 30 days and opens for anyone who has it, signed
 *   in or not. It shows the saved entry, never anyone's autosave. Share
 *   links are listed on the entry and can be revoked.
 *
 * Tokens are 256 random bits; only their SHA-256 is stored.
 */
final class Previews
{
    public const string EDITOR = 'editor';
    public const string SHARE = 'share';
    public const int EDITOR_MINUTES = 60;
    public const int MAX_DAYS = 30;
    public const int MAX_SHARE_LINKS = 20;

    public function __construct(private readonly Entries $entries) {}

    /**
     * @return array{id: int, url: string, kind: string, label: ?string, expires_at: string}
     */
    public function create(Entry $entry, ContentType $type, Principal $p, string $kind = self::EDITOR, int $days = 7, ?string $label = null): array
    {
        if (!$this->entries->can($p, $type, 'edit', $entry)) {
            throw ContentException::forbidden("You can't preview this " . strtolower($type->singular()) . '.');
        }
        if ($type->getPath() === null) {
            throw ContentException::invalid(['entry' => [$type->plural() . ' have no page on the site to preview.']]);
        }
        if (!in_array($kind, [self::EDITOR, self::SHARE], true)) {
            throw ContentException::invalid(['kind' => ['Must be editor or share.']]);
        }
        $label = $label === null ? null : trim($label);
        if ($kind === self::SHARE) {
            if ($days < 1 || $days > self::MAX_DAYS) {
                throw ContentException::invalid(['days' => ['Must be between 1 and ' . self::MAX_DAYS . ' days.']]);
            }
            if ($label !== null && mb_strlen($label) > 100) {
                throw ContentException::invalid(['label' => ['Must be at most 100 characters.']]);
            }
            if (count($this->shareLinks($entry)) >= self::MAX_SHARE_LINKS) {
                throw ContentException::invalid(['kind' => ['This ' . strtolower($type->singular()) . ' has ' . self::MAX_SHARE_LINKS . ' share links already. Revoke one first.']]);
            }
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = Clock::now(new \DateTimeZone('UTC'))->getTimestamp();
        $expires = $kind === self::EDITOR ? $now + self::EDITOR_MINUTES * 60 : $now + $days * 86400;
        $id = (int) QueryBuilder::table('preview_links')->insert([
            'entry_id' => $entry->getKey(), 'token_hash' => hash('sha256', $token), 'kind' => $kind,
            'label' => $kind === self::SHARE && $label !== '' ? $label : null, 'created_by' => $p->userId,
            'expires_at' => gmdate('Y-m-d H:i:s', $expires), 'views' => 0, 'created_at' => Model::freshTimestamp(),
        ]);

        return ['id' => $id, 'url' => '/preview/' . $token, 'kind' => $kind, 'label' => $kind === self::SHARE && $label !== '' ? $label : null,
            'expires_at' => Caster::present(Caster::toDate(gmdate('Y-m-d H:i:s', $expires)))];
    }

    /**
     * The link behind a token, when it exists, has not expired, and (for an
     * editor link) belongs to $viewerId.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(#[\SensitiveParameter] string $token, ?int $viewerId): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $row = QueryBuilder::table('preview_links')->where('token_hash', hash('sha256', $token))->first();
        if ($row === null || (string) $row['expires_at'] <= Model::freshTimestamp()) {
            return null;
        }
        if ($row['kind'] === self::EDITOR && ($viewerId === null || (int) $row['created_by'] !== $viewerId)) {
            return null;
        }
        QueryBuilder::table('preview_links')->where('id', $row['id'])->update(['views' => (int) $row['views'] + 1, 'last_used_at' => Model::freshTimestamp()]);

        return $row;
    }

    /**
     * The entry as the link shows it: the saved entry, with the creator's
     * newer autosave on top for an editor link. Never saved.
     */
    public function entryFor(array $link): ?Entry
    {
        $entry = Entry::query()->find((int) $link['entry_id']);
        if ($entry === null) {
            return null;                    // in the trash: no preview
        }
        if ($link['kind'] === self::EDITOR && $link['created_by'] !== null && ($autosave = $this->entries->autosaveFor($entry, (int) $link['created_by'])) !== null) {
            $snap = $autosave['snapshot'];
            foreach (['title', 'excerpt'] as $key) {
                if (array_key_exists($key, $snap)) {
                    $entry->setAttribute($key, $snap[$key]);
                }
            }
            if (isset($snap['data']) && is_array($snap['data'])) {
                $entry->setAttribute('data', array_replace((array) $entry->data, $snap['data']));
            }
        }

        return $entry;
    }

    /** @return list<array{id: int, label: ?string, expires_at: string, created_at: ?string, created_by: ?string, views: int, last_used_at: ?string}> */
    public function shareLinks(Entry $entry): array
    {
        $rows = QueryBuilder::table('preview_links')->leftJoin('users', 'users.id', '=', 'preview_links.created_by')
            ->select('preview_links.*', 'users.username')
            ->where('preview_links.entry_id', $entry->getKey())->where('preview_links.kind', self::SHARE)
            ->where('preview_links.expires_at', '>', Model::freshTimestamp())->orderByDesc('preview_links.id')->get();

        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'], 'label' => $r['label'], 'views' => (int) $r['views'], 'created_by' => $r['username'],
            'expires_at' => Caster::present(Caster::toDate((string) $r['expires_at'])),
            'created_at' => $r['created_at'] === null ? null : Caster::present(Caster::toDate((string) $r['created_at'])),
            'last_used_at' => $r['last_used_at'] === null ? null : Caster::present(Caster::toDate((string) $r['last_used_at'])),
        ], $rows);
    }

    public function revoke(Entry $entry, ContentType $type, Principal $p, int $id): bool
    {
        if (!$this->entries->can($p, $type, 'edit', $entry)) {
            throw ContentException::forbidden("You can't change this " . strtolower($type->singular()) . '.');
        }

        return QueryBuilder::table('preview_links')->where('id', $id)->where('entry_id', $entry->getKey())->delete() > 0;
    }

    /** Delete expired links. Returns how many. */
    public function prune(): int
    {
        return QueryBuilder::table('preview_links')->where('expires_at', '<=', Model::freshTimestamp())->delete();
    }
}
