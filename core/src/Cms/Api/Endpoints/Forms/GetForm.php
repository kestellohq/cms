<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Resources\FormResource;
use Kestello\Cms\Forms\Forms;
use Kestello\Contracts\Forms\Captcha;
use Kestello\Framework\Core\Response;

/** One form, with what the builder needs to know (field types, limits, whether a captcha is installed). */
final class GetForm extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/forms/{id}')->where('id', '\d{1,18}');
    }
    public function summary(): string
    {
        return 'Get a form';
    }

    public function resource(): ?string
    {
        return FormResource::class;
    }

    public function handle(ApiRequest $request): mixed
    {
        $form = self::attempt(fn() => self::forms()->find($request->intParam('id')));

        // meta: what the form builder needs, beside the form
        return Response::json(['data' => (new FormResource($form))->resolve($request->principal, self::cms()->api), 'meta' => self::meta()]);
    }

    public static function meta(): array
    {
        return ['options' => Forms::OPTIONS, 'max_upload_mb' => Forms::MAX_UPLOAD_MB, 'captcha' => self::cms()->container->has(Captcha::class), 'types' => [], 'taxonomies' => []];
    }
}
