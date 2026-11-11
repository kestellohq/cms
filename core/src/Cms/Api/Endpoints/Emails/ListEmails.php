<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Emails;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\I18n\Labels;
use Kestello\Cms\I18n\SiteLanguage;

/** Every email the site sends, grouped, with the languages each has an edit in. */
final class ListEmails extends EmailEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/emails');
    }
    public function summary(): string
    {
        return 'List the emails the site sends';
    }

    public function response(): ?Schema
    {
        return Schema::object([
            'site_language' => Schema::string(),
            'languages' => self::languagesSchema(),
            'emails' => Schema::list(Schema::object([
                'id' => Schema::string(),
                'label' => Schema::string(),
                'group' => Schema::string(),
                'description' => Schema::string(),
                'owner' => Schema::string()->description('core, or the plugin that sends it'),
                'edited' => Schema::list(Schema::string())->description('The languages it has an edit in'),
            ])),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $edited = self::emails()->edits()->edited();
        $emails = [];
        foreach (self::emails()->all() as $id => $email) {
            $emails[] = [
                'id' => $id,
                'label' => Labels::text($email['label'], Labels::domain($email['owner'])),
                'group' => Labels::text($email['group'], Labels::domain($email['owner'])),
                'description' => Labels::text($email['description'], Labels::domain($email['owner'])),
                'owner' => $email['owner'],
                'edited' => $edited[$id] ?? [],
            ];
        }
        return [
            'site_language' => SiteLanguage::site(),
            'languages' => self::languages(),
            'emails' => $emails,
        ];
    }
}
