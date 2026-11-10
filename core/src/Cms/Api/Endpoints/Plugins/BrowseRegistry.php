<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Package\Registry\RegistryCatalog;

/**
 * Plugins → Add new: the registry's plugins, searched and filtered by category. With refresh=1
 * (when the screen opens) the site's copy of the registry is brought up to date first, at most
 * once a minute; a failed refresh keeps the copy it has and says why.
 */
final class BrowseRegistry extends PluginEndpoint
{
    private const int REFRESH_AFTER = 60;

    public function route(): ApiRoute
    {
        return ApiRoute::get('/plugins/registry');
    }
    public function summary(): string
    {
        return 'Browse plugins in the registry';
    }
    public function collection(): bool
    {
        return false;
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'q' => Schema::string()->max(RegistryCatalog::MAX_QUERY)->optional(),
            'category' => Schema::string()->max(40)->pattern('[a-z0-9-]+')->optional(),
            'page' => Schema::int()->min(1)->max(1000)->default(1),
            'refresh' => Schema::bool()->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $client = $this->registry();
        if ($client === null) {
            return ['enabled' => false, 'status' => null, 'total' => 0, 'page' => 1, 'per_page' => RegistryCatalog::PER_PAGE, 'categories' => (object) [], 'packages' => []];
        }
        $status = $client->status();
        if ($request->query('refresh', false) && ($status['checked_at'] ?? 0) < time() - self::REFRESH_AFTER) {
            $status = $client->refresh();
        }
        $index = $client->index();
        $installed = array_map(static fn($p): string => $p->version(), $this->extensions()->plugins());
        $result = $index === null
            ? ['total' => 0, 'page' => 1, 'per_page' => RegistryCatalog::PER_PAGE, 'categories' => [], 'packages' => []]
            : RegistryCatalog::browse($index, 'plugin', (string) $request->query('q', ''), $request->query('category'), (int) $request->query('page', 1), $this->cms()->version(), $installed);

        return [
            'enabled' => true,
            'status' => [
                'checked_at' => $status['checked_at'] !== null ? gmdate('Y-m-d\TH:i:s\Z', $status['checked_at']) : null,
                'error' => $status['error'],
                'usable' => $index !== null,
            ],
            ...$result,
            'categories' => (object) $result['categories'],
        ];
    }
}
