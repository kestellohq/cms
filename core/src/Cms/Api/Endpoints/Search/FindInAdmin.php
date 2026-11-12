<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Search;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\Entries;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Media\Media;
use Kestello\Cms\Models\User;
use Kestello\Framework\Database\QueryBuilder;

/**
 * The admin finder (Ctrl/Cmd+K): screens, entries (drafts too), media and
 * people matching a few letters, limited to what the user may open.
 */
final class FindInAdmin extends Endpoint
{
    public const int PER_GROUP = 6;

    public function route(): ApiRoute
    {
        return ApiRoute::get('/admin/find');
    }
    public function capability(): ?string
    {
        return 'admin.access';
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function tags(): array
    {
        return ['search'];
    }
    public function summary(): string
    {
        return 'Find screens, entries, media and people in the admin';
    }

    public function query(): ?Schema
    {
        return Schema::object(['q' => Schema::string()->min(1)->max(100)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = Cms::instance() ?? throw ApiProblem::server();
        $p = $request->principal;
        $q = trim((string) $request->query('q'));
        $needle = mb_strtolower($q);
        $pattern = '%' . QueryBuilder::escapeLike($q) . '%';
        $groups = [];

        $screens = [];
        foreach ($cms->admin->menuFor($p) as $group) {
            foreach ($group['items'] as $item) {
                if (str_contains(mb_strtolower($item['label']), $needle)) {
                    $screens[] = ['label' => $item['label'], 'detail' => $group['label'], 'href' => $item['href'], 'icon' => $item['icon']];
                }
            }
        }
        $groups[] = ['id' => 'screens', 'label' => 'Screens', 'items' => array_slice($screens, 0, self::PER_GROUP)];

        $entries = $cms->container->make(Entries::class);
        $types = [];
        foreach ($cms->content->types() as $type) {
            if ($entries->can($p, $type, 'edit')) {
                $types[$type->name] = $type;
            }
        }
        $found = [];
        if ($types !== []) {
            $query = Entry::query()->whereIn('type', array_keys($types))->whereLike('title', $pattern)->orderByDesc('updated_at')->orderByDesc('id')->limit(self::PER_GROUP * 3);
            foreach ($query->get()->all() as $entry) {
                $type = $types[$entry->type];
                if ($entries->can($p, $type, 'edit', $entry) && count($found) < self::PER_GROUP) {
                    $found[] = ['label' => (string) $entry->title !== '' ? $entry->title : '(untitled)', 'detail' => $type->singular() . ' · ' . $entry->status,
                        'href' => "/admin/content/{$type->name}/{$entry->getKey()}", 'icon' => $type->getIcon()];
                }
            }
        }
        $groups[] = ['id' => 'entries', 'label' => 'Content', 'items' => $found];

        if (($p->can('media.upload') || $p->can('media.manage'))) {
            $media = Media::query()->where(fn(QueryBuilder $w) => $w->whereLike('title', $pattern)->orWhereLike('filename', $pattern))->orderByDesc('id')->limit(self::PER_GROUP)->get()->all();
            $groups[] = ['id' => 'media', 'label' => 'Media', 'items' => array_map(static fn(Media $m) => [
                'label' => $m->title !== '' ? $m->title : $m->filename, 'detail' => strtoupper((string) $m->ext), 'href' => '/admin/media?file=' . $m->getKey(),
                'icon' => ['image' => 'image', 'video' => 'film-strip', 'audio' => 'music-notes', 'archive' => 'file-zip'][$m->kind] ?? 'file-text',
            ], $media)];
        }

        if ($p->can('users.read') && $p->hasScope('users:read')) {
            $users = User::query()->where(fn(QueryBuilder $w) => $w->whereLike('username', $pattern)->orWhereLike('email', $pattern))->orderBy('username')->limit(self::PER_GROUP)->get()->all();
            $groups[] = ['id' => 'people', 'label' => 'People', 'items' => array_map(static fn($u) => [
                'label' => (string) $u->username, 'detail' => (string) $u->email, 'href' => '/admin/users/' . $u->getKey(), 'icon' => 'user',
            ], $users)];
        }

        return ['query' => $q, 'groups' => array_values(array_filter($groups, static fn($g) => $g['items'] !== []))];
    }
}
