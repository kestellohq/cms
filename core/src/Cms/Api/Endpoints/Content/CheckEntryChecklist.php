<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Checklist;
use Kestello\Cms\Content\Entry;

/**
 * The type's editorial checklist against an entry's unsaved values, for the editor's
 * "Before publishing" card. Nothing is stored. Required items that fail stop the entry
 * from being published (Entries checks them again on save).
 */
final class CheckEntryChecklist extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/content/{type}/checklist')->where('type', self::TYPE);
    }
    public function summary(): string
    {
        return 'Check an entry against its type\'s checklist';
    }
    public function rateLimit(): ?array
    {
        return [600, 60];
    }   // the editor asks as you type

    public function body(): ?Schema
    {
        return Schema::object([
            'entry' => Schema::int()->nullable()->optional()->description('The entry being edited; empty for one not saved yet.'),
            'title' => Schema::string()->max(255)->optional(),
            'excerpt' => Schema::string()->max(1000)->nullable()->optional(),
            'data' => Schema::any(1048576, 40)->optional(),
            'terms' => Schema::any(65536, 4)->optional()->description('taxonomy => list of term ids or new names'),
        ]);
    }

    public function response(): ?Schema
    {
        return Schema::object([
            'check' => Schema::string(), 'level' => Schema::enum(Checklist::LEVELS), 'label' => Schema::string(),
            'passed' => Schema::bool()->nullable()->description('null: the check is not available (its plugin is off, or its taxonomy no longer applies)'),
        ]);
    }

    public function collection(): bool
    {
        return true;
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $id = isset($request->body['entry']) ? (int) $request->body['entry'] : 0;
        $entry = null;
        if ($id > 0) {
            $entry = Entry::query()->find($id);
            if (!$entry instanceof Entry || $entry->getAttribute('type') !== $type->name || !self::entries()->can($request->principal, $type, 'edit', $entry)) {
                throw ApiProblem::notFound('No such ' . strtolower($type->singular()) . '.');
            }
        } elseif (!self::entries()->can($request->principal, $type, 'create')) {
            throw ApiProblem::forbidden('You cannot create ' . strtolower($type->plural()) . '.');
        }
        $input = array_intersect_key($request->body, array_flip(['title', 'excerpt', 'data', 'terms']));

        return self::cms()->content->checks->run($type, self::entries()->checklistEntry($type, $entry, $input));
    }
}
