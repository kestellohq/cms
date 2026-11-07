<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Core\Response;

/** A form's submissions as CSV (audited: it's personal data leaving the site). */
final class ExportSubmissions extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/forms/{id}/submissions/export')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Export submissions as CSV';
    }
    public function query(): ?Schema
    {
        return Schema::object(['spam' => Schema::bool()->default(false)]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $csv = self::attempt(fn() => self::forms()->csv($id, (bool) $request->query('spam')));
        self::audit($request, 'form.exported', ['form', $id]);

        return (new Response($csv, 200))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="form-' . $id . '-' . gmdate('Y-m-d') . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
