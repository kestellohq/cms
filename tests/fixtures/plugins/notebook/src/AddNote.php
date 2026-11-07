<?php

declare(strict_types=1);

namespace Plugins\Notebook;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;

/** POST /api/v1/notebook/pages/{id}/notes {text}: a row in the plugin's own table. */
final class AddNote extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/notebook/pages/{id}/notes')->where('id', '\d+');
    }
    public function capability(): ?string
    {
        return 'notebook.write';
    }
    public function scopes(): array
    {
        return ['notebook:write'];
    }
    public function summary(): string
    {
        return 'Add a note to a notebook page';
    }

    public function body(): ?Schema
    {
        return Schema::object(['text' => Schema::string()->min(1)->max(200)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        if (QueryBuilder::table('entries')->where('id', $id)->where('type', NotebookProvider::PAGE)->count() === 0) {
            throw ApiProblem::notFound('No such notebook page.');
        }
        QueryBuilder::table('p_notebook_notes')->insert(['entry_id' => $id, 'text' => (string) $request->input('text'), 'created_at' => date('Y-m-d H:i:s')]);

        return Response::json(['data' => ['notes' => QueryBuilder::table('p_notebook_notes')->where('entry_id', $id)->count()]]);
    }
}
