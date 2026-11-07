<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Closure;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Forms\Forms;

/** Shared plumbing: forms and their submissions need forms.manage. */
abstract class FormEndpoint extends Endpoint
{
    public function capability(): ?string
    {
        return 'forms.manage';
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function tags(): array
    {
        return ['forms'];
    }

    protected static function cms(): Cms
    {
        return Cms::instance() ?? throw new \LogicException('Form endpoints need a booted CMS.');
    }

    protected static function forms(): Forms
    {
        return self::cms()->container->make(Forms::class);
    }

    protected static function attempt(Closure $work): mixed
    {
        try {
            return $work();
        } catch (ContentException $e) {
            throw match ($e->kind) {
                'invalid' => ApiProblem::validation($e->errors),
                'forbidden' => ApiProblem::forbidden($e->getMessage()),
                default => ApiProblem::conflict($e->getMessage()),
            };
        } catch (\OutOfBoundsException $e) {
            throw ApiProblem::notFound($e->getMessage());
        }
    }

    protected static function audit(ApiRequest $request, string $event, array $subject, array $data = []): void
    {
        self::cms()->audit->record($event, $subject, $data, $request->userId());
    }

    /** Pages showing the form are cached: refresh them. */
    protected static function purgePages(int $formId): void
    {
        $c = self::cms()->container;
        if ($c->has(\Kestello\Contracts\Cache\PageCache::class)) {
            $c->make(\Kestello\Contracts\Cache\PageCache::class)->purge('form:' . $formId);
        }
    }
}
