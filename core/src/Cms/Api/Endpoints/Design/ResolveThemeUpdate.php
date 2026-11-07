<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/**
 * The theme changed a template, part or pattern the site had changed:
 * keep the site's version, take the theme's, or open the theme's as a
 * draft to merge by hand (the site's version stays live meanwhile).
 */
final class ResolveThemeUpdate extends TemplateEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/design/templates/{kind}/{key}/theme-update')->where('kind', self::KIND)->where('key', self::KEY);
    }
    public function summary(): string
    {
        return 'Resolve a theme update to a changed template, part or pattern';
    }

    public function body(): ?Schema
    {
        return Schema::object(['action' => Schema::enum(['keep', 'theirs', 'draft'])]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $kind = (string) $request->param('kind');
        $key = (string) $request->param('key');
        $t = $this->templates();
        $theirs = $t->file($kind, $key);
        if ($theirs === null || !$t->hasThemeUpdate($kind, $key)) {
            throw \Kestello\Cms\Api\ApiProblem::conflict('There is no theme update to resolve.');
        }
        $action = (string) $request->body['action'];
        match ($action) {
            'keep' => $this->attempt(fn() => $t->keepMine($kind, $key)),
            'theirs' => $this->attempt(fn() => $t->reset($kind, $key, $request->userId())),
            'draft' => $this->attempt(function () use ($t, $kind, $key, $theirs, $request): void {
                $t->save($kind, $key, $theirs, $request->userId(), publish: false);
                $t->keepMine($kind, $key);
            }),
        };
        $this->changed('theme_update_' . $action, $kind, $key, $request->userId());

        return $this->document($request, $kind, $key);
    }
}
