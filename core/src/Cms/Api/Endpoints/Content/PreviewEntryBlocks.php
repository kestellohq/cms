<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Blocks\BlockContext;
use Kestello\Cms\Blocks\BlockRegistry;
use Kestello\Cms\Content\Entry;

/**
 * Prepare an entry's unsaved blocks the way its page would show them, for
 * the editor's canvas. Nothing is stored; blocks that don't validate are
 * left out, as they would be on the site.
 */
final class PreviewEntryBlocks extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/content/{type}/preview-blocks')->where('type', self::TYPE);
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    public function summary(): string
    {
        return 'Preview an entry\'s blocks';
    }
    public function rateLimit(): ?array
    {
        return [600, 60];
    }   // the canvas asks on every change

    public function body(): ?Schema
    {
        return Schema::object([
            'blocks' => Schema::any(1048576, 40),
            'field' => Schema::string()->max(64)->pattern('^[a-z][a-z0-9_]*$'),
            'entry' => Schema::int()->nullable()->optional()->description('The entry being edited; empty for one not saved yet.'),
        ]);
    }

    public function response(): ?Schema
    {
        return Schema::object([
            'blocks' => Schema::list(Schema::any()),
            'components' => Schema::list(Schema::string()),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $field = $type->getField((string) $request->body['field']);
        if ($field === null || $field->type !== 'blocks' || $field->isInternal()) {
            throw ApiProblem::validation(['body.field' => ['Not a blocks field of this type.']]);
        }
        $id = isset($request->body['entry']) ? (int) $request->body['entry'] : 0;
        if ($id > 0) {
            $entry = Entry::query()->ofType($type->name)->with('author', 'parent')->find($id);
            if ($entry === null || !self::entries()->can($request->principal, $type, 'edit', $entry)) {
                throw ApiProblem::notFound('No such ' . strtolower($type->singular()) . '.');
            }
        } else {
            if (!self::entries()->can($request->principal, $type, 'create')) {
                throw ApiProblem::forbidden('You cannot create ' . strtolower($type->plural()) . '.');
            }
            $entry = (new Entry())->forceFill(['type' => $type->name, 'title' => '', 'slug' => '', 'status' => 'draft', 'data' => []]);
        }
        $ctx = new BlockContext($entry, $type, $request->http, \Kestello\Cms\Auth\Auth::getInstance()?->user());
        $allowed = $field->getOption('allowed');
        $blocks = self::cms()->blocks->prepare($request->body['blocks'] ?? [], $ctx, is_array($allowed) ? $allowed : null, draft: true);

        return ['blocks' => $blocks, 'components' => BlockRegistry::components($blocks)];
    }
}
