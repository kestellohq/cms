<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Fleet;

use InvalidArgumentException;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\Fleet\FleetSites;
use OutOfBoundsException;

/**
 * Site → Fleet, the console that watches other Kestello sites. Only where app.fleet.enabled is
 * on (otherwise every address answers 404); needs fleet.manage (administrators).
 */
abstract class FleetEndpoint extends Endpoint
{
    public function capability(): ?string
    {
        return 'fleet.manage';
    }
    public function scopes(): array
    {
        return ['system'];
    }
    public function tags(): array
    {
        return ['fleet'];
    }

    final public function handle(ApiRequest $request): mixed
    {
        if (self::cms()->config->get('app.fleet.enabled', false) !== true) {
            throw ApiProblem::notFound('The fleet console is off on this site (app.fleet.enabled).');
        }
        try {
            return $this->act($request, self::cms()->container->make(FleetSites::class));
        } catch (OutOfBoundsException) {
            throw ApiProblem::notFound('No such site in the fleet.');
        } catch (InvalidArgumentException $e) {
            [$field, $message] = str_contains($e->getMessage(), ': ') ? explode(': ', $e->getMessage(), 2) : ['site', $e->getMessage()];
            throw ApiProblem::validation([$field => [$message]]);
        }
    }

    abstract protected function act(ApiRequest $request, FleetSites $sites): mixed;

    protected static function cms(): Cms
    {
        return Cms::instance() ?? throw new \LogicException('Fleet endpoints need a booted CMS.');
    }

    protected static function siteSchema(): Schema
    {
        return Schema::object([
            'id' => Schema::int(),
            'name' => Schema::string(),
            'url' => Schema::string(),
            'token_last4' => Schema::string()->description('the token itself is never shown again'),
            'report' => Schema::any()->nullable()->description('the last report the site gave (GET /fleet/report on that site)'),
            'checked_at' => Schema::string()->nullable(),
            'error' => Schema::string()->nullable()->description('why the last check failed; the last good report is kept'),
        ]);
    }
}
