<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Menus;

use Closure;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Menus\Menus;

/** Shared plumbing: menus need menus.manage. */
abstract class MenuEndpoint extends Endpoint
{
    public function capability(): ?string
    {
        return 'menus.manage';
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function tags(): array
    {
        return ['menus'];
    }

    protected static function cms(): Cms
    {
        return Cms::instance() ?? throw new \LogicException('Menu endpoints need a booted CMS.');
    }

    protected static function menus(): Menus
    {
        return self::cms()->container->make(Menus::class);
    }

    /** @return array<string, mixed> */
    protected static function menu(int $id): array
    {
        $menu = self::menus()->find($id) ?? throw ApiProblem::notFound('No such menu.');
        $menu['items'] = self::menus()->describe($menu['items']);

        return $menu;
    }

    protected static function attempt(Closure $write): mixed
    {
        try {
            return $write();
        } catch (ContentException $e) {
            throw match ($e->kind) {
                'invalid' => ApiProblem::validation($e->errors),
                'conflict' => ApiProblem::conflict($e->getMessage()),
                default => ApiProblem::forbidden($e->getMessage()),
            };
        }
    }

    protected static function audit(ApiRequest $request, string $event, int $menuId, array $data = []): void
    {
        self::cms()->audit->record($event, ['menu', $menuId], $data, $request->userId());
    }
}
