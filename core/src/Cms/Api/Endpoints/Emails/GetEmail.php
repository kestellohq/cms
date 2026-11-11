<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Emails;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\I18n\Labels;
use Kestello\Cms\Mail\EmailEdit;

/**
 * One email in one language: what its code writes (with sample values), the
 * edit to start from, the edit saved for that language, and the values its
 * words can use.
 */
final class GetEmail extends EmailEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/emails/{id}')->where('id', self::ID);
    }
    public function summary(): string
    {
        return 'Get an email and its edit in one language';
    }
    public function query(): ?Schema
    {
        return Schema::object(['locale' => Schema::string()->max(12)->optional()->description('One of the site\'s languages; the site\'s own when left out')]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $email = self::email($request);

        return self::describe($email['id'], self::locale($request->query('locale')));
    }

    /** @return array<string, mixed> */
    public static function describe(string $id, string $locale): array
    {
        $emails = self::emails();
        $email = $emails->get($id);

        return self::in($locale, static function () use ($emails, $email, $id, $locale): array {
            $original = $emails->original($id, $email['sample']);

            return [
                'id' => $id,
                'label' => Labels::text($email['label'], Labels::domain($email['owner'])),
                'group' => Labels::text($email['group'], Labels::domain($email['owner'])),
                'description' => Labels::text($email['description'], Labels::domain($email['owner'])),
                'owner' => $email['owner'],
                'locale' => $locale,
                'locked' => $email['locked'],
                'placeholders' => array_map(static fn(string $k): array => ['name' => $k, 'sample' => (string) $email['sample'][$k]], EmailEdit::placeholders($email['sample'])),
                'editable' => !$original->custom(),
                'original' => ['subject' => $original->subject, 'blocks' => $original->blocks()],
                'template' => $emails->template($id),
                'edit' => $emails->edits()->find($id, $locale),
            ];
        });
    }
}
