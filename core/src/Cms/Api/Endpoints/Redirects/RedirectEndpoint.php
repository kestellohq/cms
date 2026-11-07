<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Redirects;

use Closure;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Redirects\Redirects;

/** Shared plumbing: redirects need redirects.manage. */
abstract class RedirectEndpoint extends Endpoint
{
    public function capability(): ?string
    {
        return 'redirects.manage';
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function tags(): array
    {
        return ['redirects'];
    }

    protected static function cms(): Cms
    {
        return Cms::instance() ?? throw new \LogicException('Redirect endpoints need a booted CMS.');
    }

    protected static function redirects(): Redirects
    {
        return self::cms()->container->make(Redirects::class);
    }

    protected static function attempt(Closure $write): mixed
    {
        try {
            return $write();
        } catch (ContentException $e) {
            throw $e->kind === 'invalid' ? ApiProblem::validation($e->errors) : ApiProblem::conflict($e->getMessage());
        } catch (\OutOfBoundsException $e) {
            throw ApiProblem::notFound($e->getMessage());
        }
    }

    /** @param array<string, mixed> $data */
    protected static function audit(ApiRequest $request, string $event, ?int $id, array $data = []): void
    {
        self::cms()->audit->record($event, $id === null ? null : ['redirect', $id], $data, $request->userId());
    }
}
