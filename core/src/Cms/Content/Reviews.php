<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Cms\Audit\AuditLog;
use Kestello\Cms\Auth\Roles;
use Kestello\Cms\Events\EntryAssigned;
use Kestello\Cms\Events\EntryCommented;
use Kestello\Cms\Events\EntryReviewed;
use Kestello\Cms\Events\EntrySubmitted;
use Kestello\Cms\Notifications\Notifier;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Events\EventDispatcher;

/**
 * The review workflow around an entry: editorial comments, its timeline of
 * review steps, sending it back with a note, and assigning it to someone.
 *
 * Submitting and approving are ordinary saves (status `pending`, then
 * `published` or `scheduled`); Entries dispatches EntrySubmitted and
 * EntryReviewed for them, and listen() turns every review event into a
 * timeline row, an audit record and notifications.
 *
 *   $reviews->comment($entry, 'Can we use the newer photo?', $principal);
 *   $reviews->requestChanges($entry, 'The second paragraph needs a source.', $principal);
 *   $reviews->assign($entry, $editorId, $principal);
 *   $reviews->waitingFor($principal);   // sent back to you, assigned to you, in review for you
 *
 * A comment that names someone as @username tells them, when they may open the entry.
 *
 * @api
 */
final class Reviews
{
    public const int MAX_BODY = 5000;

    /** Timeline rows: what people wrote, and the review steps around it. */
    public const array KINDS = ['comment', 'submitted', 'changes_requested', 'approved'];

    /** At most this many people hear about one submission. */
    private const int MAX_REVIEWERS = 50;

    /** At most this many people are told about one comment by name. */
    public const int MAX_MENTIONS = 10;

    /** Why an entry waits on someone, most urgent first. */
    public const array WAITING = ['changes_requested', 'assigned', 'review'];

    public function __construct(
        private readonly ContentRegistry $content,
        private readonly Entries $entries,
        private readonly Roles $roles,
        private readonly ?EventDispatcher $events = null,
        private readonly ?Notifier $notifier = null,
        private readonly ?AuditLog $audit = null,
        private readonly string $siteUrl = '',
    ) {}

    /** @internal ContentModule: review events become timeline rows, audit records and notifications. */
    public function listen(EventDispatcher $events): void
    {
        $events->on(EntrySubmitted::class, fn(EntrySubmitted $e) => $this->onSubmitted($e));
        $events->on(EntryReviewed::class, fn(EntryReviewed $e) => $this->onReviewed($e));
        $events->on(EntryAssigned::class, fn(EntryAssigned $e) => $this->onAssigned($e));
    }

    // ── comments ─────────────────────────────────────────────────────

    /**
     * The entry's timeline, oldest first.
     *
     * @return list<array{id: int, kind: string, body: ?string, user: ?array{id: int, username: string}, created_at: ?string}>
     */
    public function timeline(Entry $entry, int $limit = 200): array
    {
        $rows = QueryBuilder::table('entry_comments')->where('entry_id', $entry->getKey())->orderByDesc('id')->limit($limit)->get();
        $names = self::usernames(array_column($rows, 'user_id'));

        return array_reverse(array_map(static fn(array $r) => [
            'id' => (int) $r['id'],
            'kind' => (string) $r['kind'],
            'body' => $r['body'] === null ? null : (string) $r['body'],
            'user' => $r['user_id'] === null ? null : ['id' => (int) $r['user_id'], 'username' => $names[(int) $r['user_id']] ?? '#' . $r['user_id']],
            'created_at' => \Kestello\Framework\Database\Orm\Caster::present($r['created_at']),
        ], $rows));
    }

    /** Anyone who can see the entry in the admin may comment on it. */
    public function comment(Entry $entry, string $body, Principal $p): int
    {
        $type = $this->typeOf($entry);
        if ($p->userId === null || !$this->entries->can($p, $type, 'view', $entry)) {
            throw ContentException::forbidden("You can't comment on this " . strtolower($type->singular()) . '.');
        }
        $body = self::body($body, 'body');
        $id = $this->record($entry, 'comment', $body, $p->userId);
        $this->events?->dispatch(new EntryCommented((int) $entry->getKey(), (string) $entry->type, $id, $p->userId));

        // Everyone named, then the author, the assignee and everyone who wrote on it before; never the writer, never twice.
        $author = $entry->author_id === null ? null : (int) $entry->author_id;
        $named = $this->mentionedIn($body, $author, $type);
        $people = QueryBuilder::table('entry_comments')->where('entry_id', $entry->getKey())->whereIn('kind', ['comment', 'changes_requested', 'submitted'])->whereNotNull('user_id')->distinct()->pluck('user_id');
        $to = array_values(array_diff(array_map('intval', array_filter(array_merge([$author, $this->assigneeOf($entry)], $people))), $named));
        $writer = self::username($p->userId);
        $this->notify($named, $p->userId, new CommentNotification('mentioned', (int) $entry->getKey(), $type->singular(), (string) $entry->title, $writer, $body, $this->editUrl($entry), $this->siteUrl));
        $this->notify($to, $p->userId, new CommentNotification('comment', (int) $entry->getKey(), $type->singular(), (string) $entry->title, $writer, $body, $this->editUrl($entry), $this->siteUrl));

        return $id;
    }

    /**
     * The usernames a comment names with @, in order, once each: "@maria" and "(@maria)" do,
     * an email address doesn't. At most MAX_MENTIONS.
     *
     * @return list<string>
     */
    public static function mentions(string $body): array
    {
        preg_match_all('/(?<![A-Za-z0-9_.@-])@([A-Za-z0-9_-]{3,50})(?![A-Za-z0-9_-])/', $body, $m);
        $out = [];
        foreach ($m[1] as $name) {
            $key = strtolower($name);
            $out[$key] ??= $name;
        }

        return array_slice(array_values($out), 0, self::MAX_MENTIONS);
    }

    /**
     * The people named in a comment who may open the entry (active accounts whose role may
     * edit entries of its type, and other people's too when it isn't theirs).
     *
     * @return list<int>
     */
    private function mentionedIn(string $body, ?int $authorId, ContentType $type): array
    {
        $names = array_map('strtolower', self::mentions($body));
        if ($names === []) {
            return [];
        }
        $others = $this->roles->rolesWith($type->capability('edit_others'));
        $ids = [];
        foreach ($this->assignable($type) as $person) {
            if (!in_array(strtolower($person['username']), $names, true)) {
                continue;
            }
            $own = $authorId === $person['id'];
            if ($own || in_array($this->roleOf($person['id']), $others, true)) {
                $ids[] = $person['id'];
            }
        }

        return $ids;
    }

    private function roleOf(int $userId): ?string
    {
        $role = QueryBuilder::table('users')->where('id', $userId)->value('role');

        return $role === null ? null : (string) $role;
    }

    // ── what waits on you ────────────────────────────────────────────

    /**
     * Entries waiting on this person, most urgent first, each once under the first reason that fits:
     *
     *   changes_requested  theirs, sent back to draft by a reviewer, not submitted again
     *   assigned           assigned to them and not published yet
     *   review             in review, and theirs to publish (not their own)
     *
     * Only types they may edit, and entries they may open; trashed entries are left out.
     *
     * @return list<array{id: int, type: string, type_label: string, title: string, status: string, reason: string, since: ?string}>
     */
    public function waitingFor(Principal $p, int $limit = 20): array
    {
        if ($p->userId === null) {
            return [];
        }
        $types = [];
        foreach ($this->content->types() as $t) {
            if ($p->can($t->capability('edit'))) {
                $types[$t->name] = $t;
            }
        }
        if ($types === []) {
            return [];
        }
        $columns = ['id', 'type', 'title', 'status', 'author_id', 'updated_at'];
        $found = [];

        // sent back: their drafts whose latest review step asks for changes
        $drafts = QueryBuilder::table('entries')->where('author_id', $p->userId)->where('status', 'draft')->whereIn('type', array_keys($types))->whereNull('deleted_at')
            ->orderByDesc('updated_at')->limit(200)->select(...$columns)->get();
        $steps = $this->latestSteps(array_map('intval', array_column($drafts, 'id')));
        foreach ($drafts as $row) {
            $step = $steps[(int) $row['id']] ?? null;
            if ($step !== null && $step['kind'] === 'changes_requested') {
                $found[] = [$row, 'changes_requested', $step['created_at']];
            }
        }

        // assigned to them
        $assigned = QueryBuilder::table('entry_assignees')->where('user_id', $p->userId)->orderByDesc('assigned_at')->limit(200)->select('entry_id', 'assigned_at')->get();
        $since = array_column($assigned, 'assigned_at', 'entry_id');
        if ($since !== []) {
            $rows = QueryBuilder::table('entries')->whereIn('id', array_map('intval', array_keys($since)))->whereIn('status', Entry::UNPUBLISHED)->whereIn('type', array_keys($types))->whereNull('deleted_at')
                ->select(...$columns)->get();
            foreach ($rows as $row) {
                $found[] = [$row, 'assigned', $since[$row['id']] ?? null];
            }
        }

        // in review, for those who may publish other people's entries of the type
        $reviewable = array_keys(array_filter($types, static fn(ContentType $t): bool => $p->can($t->capability('publish')) && $p->can($t->capability('edit_others'))));
        if ($reviewable !== []) {
            $rows = QueryBuilder::table('entries')->where('status', 'pending')->whereIn('type', $reviewable)->whereNull('deleted_at')
                ->where(static fn(QueryBuilder $q) => $q->whereNull('author_id')->orWhere('author_id', '!=', $p->userId))
                ->orderBy('updated_at')->limit(200)->select(...$columns)->get();
            foreach ($rows as $row) {
                $found[] = [$row, 'review', $row['updated_at']];
            }
        }

        $out = [];
        foreach ($found as [$row, $reason, $at]) {
            $id = (int) $row['id'];
            $type = $types[(string) $row['type']];
            $entry = (new Entry())->forceFill(['author_id' => $row['author_id'] === null ? null : (int) $row['author_id']]);
            if (isset($out[$id]) || !$this->entries->can($p, $type, 'view', $entry)) {
                continue;
            }
            $out[$id] = [
                'id' => $id, 'type' => $type->name, 'type_label' => $type->singular(), 'title' => (string) $row['title'], 'status' => (string) $row['status'],
                'reason' => $reason, 'since' => \Kestello\Framework\Database\Orm\Caster::present($at),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * The latest review step (submitted, changes_requested, approved) of each entry.
     *
     * @param list<int> $entryIds
     * @return array<int, array{kind: string, created_at: mixed}>
     */
    private function latestSteps(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $out = [];
        $rows = QueryBuilder::table('entry_comments')->whereIn('entry_id', $entryIds)->whereIn('kind', ['submitted', 'changes_requested', 'approved'])->orderByDesc('id')->select('entry_id', 'kind', 'created_at')->get();
        foreach ($rows as $r) {
            $out[(int) $r['entry_id']] ??= ['kind' => (string) $r['kind'], 'created_at' => $r['created_at']];
        }

        return $out;
    }

    /** Your own comments; people who may edit others' entries can remove any. Review steps stay. */
    public function deleteComment(Entry $entry, int $commentId, Principal $p): void
    {
        $type = $this->typeOf($entry);
        $row = QueryBuilder::table('entry_comments')->where('entry_id', $entry->getKey())->where('id', $commentId)->first();
        if ($row === null || $row['kind'] !== 'comment') {
            throw ContentException::notFound('No such comment.');
        }
        $own = $p->userId !== null && (int) $row['user_id'] === $p->userId;
        if (!$own && !$p->can($type->capability('edit_others'))) {
            throw ContentException::forbidden('Only its writer, or an editor, can delete this comment.');
        }
        QueryBuilder::table('entry_comments')->where('id', $commentId)->delete();
    }

    // ── review decisions ─────────────────────────────────────────────

    /** Send an entry in review back to draft, saying what to change. Needs the right to publish it. */
    public function requestChanges(Entry $entry, string $note, Principal $p): Entry
    {
        $type = $this->typeOf($entry);
        if (!$this->entries->can($p, $type, 'publish', $entry)) {
            throw ContentException::forbidden("You can't review this " . strtolower($type->singular()) . '.');
        }
        if ($entry->status !== 'pending') {
            throw ContentException::invalid(['status' => ['Only an entry in review can be sent back.']]);
        }
        $note = self::body($note, 'note');
        $entry = $this->entries->update($entry, ['status' => 'draft'], $p);
        $this->events?->dispatch(new EntryReviewed((int) $entry->getKey(), (string) $entry->type, (string) $entry->title, 'changes_requested', $p->userId, $note));

        return $entry;
    }

    // ── assignment ───────────────────────────────────────────────────

    /** Assign the entry to someone who may edit entries of its type, or to nobody (null). */
    public function assign(Entry $entry, ?int $userId, Principal $p): Entry
    {
        $type = $this->typeOf($entry);
        if (!$this->entries->can($p, $type, 'edit', $entry)) {
            throw ContentException::forbidden("You can't assign this " . strtolower($type->singular()) . '.');
        }
        if ($userId !== null && !in_array($userId, array_column($this->assignable($type), 'id'), true)) {
            throw ContentException::invalid(['user_id' => ['This person cannot edit ' . strtolower($type->plural()) . '.']]);
        }
        if ($this->assigneeOf($entry) === $userId) {
            return $entry;
        }
        QueryBuilder::table('entry_assignees')->where('entry_id', $entry->getKey())->delete();
        if ($userId !== null) {
            QueryBuilder::table('entry_assignees')->insert(['entry_id' => $entry->getKey(), 'user_id' => $userId, 'assigned_by' => $p->userId, 'assigned_at' => Model::freshTimestamp()]);
        }
        $this->events?->dispatch(new EntryAssigned((int) $entry->getKey(), (string) $entry->type, $userId, $p->userId));

        return $entry;
    }

    /**
     * People an entry of this type can be assigned to: those whose role may edit them.
     *
     * @return list<array{id: int, username: string}>
     */
    public function assignable(ContentType $type, int $limit = 500): array
    {
        $roles = $this->roles->rolesWith($type->capability('edit'));
        if ($roles === []) {
            return [];
        }

        return array_map(
            static fn(array $u) => ['id' => (int) $u['id'], 'username' => (string) $u['username']],
            QueryBuilder::table('users')->whereIn('role', $roles)->where('is_active', true)->orderBy('username')->limit($limit)->select('id', 'username')->get(),
        );
    }

    /**
     * Who reviews entries of a type: people who may publish other people's.
     *
     * @return list<int>
     */
    public function reviewers(ContentType $type): array
    {
        $roles = array_values(array_intersect($this->roles->rolesWith($type->capability('publish')), $this->roles->rolesWith($type->capability('edit_others'))));
        if ($roles === []) {
            return [];
        }

        return array_map('intval', QueryBuilder::table('users')->whereIn('role', $roles)->where('is_active', true)->orderBy('id')->limit(self::MAX_REVIEWERS)->pluck('id'));
    }

    public function assigneeOf(Entry $entry): ?int
    {
        $id = QueryBuilder::table('entry_assignees')->where('entry_id', $entry->getKey())->value('user_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Who each entry is assigned to, for lists.
     *
     * @param list<int> $entryIds
     * @return array<int, array{id: int, username: string}>
     */
    public static function assignees(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $rows = QueryBuilder::table('entry_assignees')->join('users', 'users.id', '=', 'entry_assignees.user_id')
            ->whereIn('entry_assignees.entry_id', $entryIds)->select('entry_assignees.entry_id', 'users.id', 'users.username')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['entry_id']] = ['id' => (int) $r['id'], 'username' => (string) $r['username']];
        }

        return $out;
    }

    // ── listeners ────────────────────────────────────────────────────

    private function onSubmitted(EntrySubmitted $e): void
    {
        $entry = Entry::query()->find($e->entryId);
        $type = $this->content->get($e->type);
        if ($entry === null || $type === null) {
            return;
        }
        $this->record($entry, 'submitted', null, $e->userId);
        $this->audit?->record('content.submitted', ['entry', $e->entryId], ['type' => $e->type, 'title' => $e->title], $e->userId);
        $assignee = $this->assigneeOf($entry);
        $to = $assignee !== null ? [$assignee] : $this->reviewers($type);
        $this->notify($to, $e->userId, new ReviewNotification('submitted', $e->entryId, $type->singular(), $e->title, self::username($e->userId), null, $this->editUrl($entry), $this->siteUrl));
    }

    private function onReviewed(EntryReviewed $e): void
    {
        $entry = Entry::query()->withTrashed()->find($e->entryId);
        $type = $this->content->get($e->type);
        if ($entry === null || $type === null) {
            return;
        }
        $submitter = $this->submitter($entry);
        $this->record($entry, $e->decision, $e->note, $e->reviewerId);
        $this->audit?->record('content.' . $e->decision, ['entry', $e->entryId], ['type' => $e->type, 'title' => $e->title], $e->reviewerId);
        $this->notify([$submitter], $e->reviewerId, new ReviewNotification($e->decision, $e->entryId, $type->singular(), $e->title, self::username($e->reviewerId), $e->note, $this->editUrl($entry), $this->siteUrl));
    }

    private function onAssigned(EntryAssigned $e): void
    {
        $entry = Entry::query()->find($e->entryId);
        $type = $this->content->get($e->type);
        if ($entry === null || $type === null) {
            return;
        }
        $this->audit?->record('content.assigned', ['entry', $e->entryId], ['type' => $e->type, 'assignee' => $e->assigneeId], $e->userId);
        if ($e->assigneeId !== null) {
            $this->notify([$e->assigneeId], $e->userId, new ReviewNotification('assigned', $e->entryId, $type->singular(), (string) $entry->title, self::username($e->userId), null, $this->editUrl($entry), $this->siteUrl));
        }
    }

    // ── internals ────────────────────────────────────────────────────

    /** Whoever last submitted it, or its author. */
    private function submitter(Entry $entry): ?int
    {
        $id = QueryBuilder::table('entry_comments')->where('entry_id', $entry->getKey())->where('kind', 'submitted')->orderByDesc('id')->value('user_id');

        return $id !== null ? (int) $id : ($entry->author_id === null ? null : (int) $entry->author_id);
    }

    private function record(Entry $entry, string $kind, ?string $body, ?int $userId): int
    {
        return (int) QueryBuilder::table('entry_comments')->insert([
            'entry_id' => $entry->getKey(), 'user_id' => $userId, 'kind' => $kind, 'body' => $body, 'created_at' => Model::freshTimestamp(),
        ]);
    }

    /** @param list<int|string|null> $to */
    private function notify(array $to, ?int $except, ReviewNotification $notification): void
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn($id) => $id === null ? 0 : (int) $id, $to), static fn(int $id) => $id > 0 && $id !== $except)));
        if ($ids !== [] && $this->notifier !== null) {
            $this->notifier->send($ids, $notification);
        }
    }

    private static function body(string $text, string $field): string
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') {
            throw ContentException::invalid([$field => ['Write something first.']]);
        }
        if (mb_strlen($text) > self::MAX_BODY) {
            throw ContentException::invalid([$field => ['Must be at most ' . self::MAX_BODY . ' characters.']]);
        }

        return $text;
    }

    private function editUrl(Entry $entry): string
    {
        return '/admin/content/' . rawurlencode((string) $entry->type) . '/' . $entry->getKey();
    }

    private static function username(?int $id): string
    {
        return $id === null ? 'Someone' : (self::usernames([$id])[$id] ?? 'Someone');
    }

    /**
     * @param list<int|string|null> $ids
     * @return array<int, string>
     */
    private static function usernames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : array_map('strval', array_column(QueryBuilder::table('users')->whereIn('id', $ids)->select('id', 'username')->get(), 'username', 'id'));
    }

    private function typeOf(Entry $entry): ContentType
    {
        return $this->content->get((string) $entry->type) ?? throw ContentException::forbidden("The content type [{$entry->type}] is not available (is its plugin active?).");
    }
}
