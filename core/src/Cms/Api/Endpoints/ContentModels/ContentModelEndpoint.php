<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\ContentModels;

use Closure;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Content\Models\ContentModels;

/** Shared plumbing: building content types changes the site's structure, so administrators only. */
abstract class ContentModelEndpoint extends Endpoint
{
    public function capability(): ?string
    {
        return 'content_models.manage';
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function tags(): array
    {
        return ['content-models'];
    }

    protected static function cms(): Cms
    {
        return Cms::instance() ?? throw new \LogicException('Content model endpoints need a booted CMS.');
    }

    protected static function models(): ContentModels
    {
        return self::cms()->container->make(ContentModels::class);
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

    protected static function audit(ApiRequest $request, string $event, int $id, array $data = []): void
    {
        self::cms()->audit->record($event, ['content_model', $id], $data, $request->userId());
    }
}
