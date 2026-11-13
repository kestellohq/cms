<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Resources\SubmissionResource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Forms\Forms;
use Kestello\Framework\Database\QueryBuilder;

/** A form's submissions, newest first, with search and a status filter. */
final class ListSubmissions extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/forms/{id}/submissions')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'List submissions';
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'page' => Schema::int()->min(1)->max(100000)->default(1),
            'per_page' => Schema::int()->min(1)->max(100)->default(25),
            'search' => Schema::string()->max(200)->optional(),
            'status' => Schema::enum(['inbox', 'new', 'read', 'spam'])->default('inbox'),
        ]);
    }

    public function resource(): ?string
    {
        return SubmissionResource::class;
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $form = self::attempt(fn() => self::forms()->find($id));
        $q = QueryBuilder::table('form_submissions')->where('form_id', $id);
        $status = (string) $request->query('status');
        $status === 'inbox' ? $q->where('status', '!=', 'spam') : $q->where('status', $status);
        if (($search = trim((string) $request->query('search', ''))) !== '') {
            $q->whereLike('data', '%' . QueryBuilder::escapeLike($search) . '%');
        }
        $total = (clone $q)->count();
        $perPage = (int) $request->query('per_page');
        $page = (int) $request->query('page');
        $rows = $q->orderBy('id', 'DESC')->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $counts = ['new' => 0, 'spam' => 0];
        foreach (QueryBuilder::table('form_submissions')->where('form_id', $id)->whereIn('status', ['new', 'spam'])->select('status')->get() as $r) {
            $counts[$r['status']]++;
        }

        return SubmissionResource::collection(array_map(Forms::presentSubmission(...), $rows), [
            'form' => $form, 'counts' => $counts,
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage)),
        ]);
    }
}
