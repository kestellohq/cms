<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Emails;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Save an email's edit for one language. Other languages keep theirs. */
final class SaveEmail extends EmailEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/emails/{id}')->where('id', self::ID);
    }
    public function summary(): string
    {
        return 'Save an email\'s edit in one language';
    }
    public function body(): ?Schema
    {
        return self::editBody(true);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = self::email($request)['id'];
        $locale = self::locale($request->input('locale'));
        $edit = self::in($locale, static fn(): ?array => self::draft($request, $id)) ?? [];
        self::emails()->edits()->save($id, $locale, $edit, $request->userId());
        self::audit($request, 'email.edited', $id, $locale);

        return GetEmail::describe($id, $locale);
    }
}
