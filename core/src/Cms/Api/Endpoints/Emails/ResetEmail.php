<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Emails;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/** Remove an email's edit in one language: it goes back to what its code writes. */
final class ResetEmail extends EmailEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::delete('/emails/{id}')->where('id', self::ID);
    }
    public function summary(): string
    {
        return 'Undo an email\'s edit in one language';
    }
    public function query(): ?Schema
    {
        return Schema::object(['locale' => Schema::string()->max(12)->optional()->description('One of the site\'s languages; the site\'s own when left out')]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = self::email($request)['id'];
        $locale = self::locale($request->query('locale'));
        if (self::emails()->edits()->delete($id, $locale)) {
            self::audit($request, 'email.reset', $id, $locale);
        }

        return GetEmail::describe($id, $locale);
    }
}
