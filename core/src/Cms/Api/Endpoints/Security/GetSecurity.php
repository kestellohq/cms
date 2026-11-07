<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Security;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Auth\AuthModule;
use Kestello\Framework\Core\Response;

/** Two-step sign-in coverage, failed sign-ins and recent account events. */
final class GetSecurity extends SecurityEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/security');
    }
    public function summary(): string
    {
        return 'Sign-in security at a glance';
    }

    public function response(): ?Schema
    {
        return Schema::object([
            'two_factor' => Schema::object([
                'roles' => Schema::list(Schema::string())->description('Roles expected to use two-step sign-in (auth.two_factor_roles)'),
                'required' => Schema::int()->description('Active people in those roles'),
                'covered' => Schema::int()->description('Of those, how many use it'),
                'missing' => Schema::list(Schema::object(['id' => Schema::int(), 'username' => Schema::string(), 'role' => Schema::string()]))->description('Up to 100 who don\'t'),
                'everyone' => Schema::object(['total' => Schema::int(), 'covered' => Schema::int()]),
            ]),
            'role_labels' => Schema::dictionary()->description('Role names to the labels people see'),
            'failed_sign_ins' => Schema::object([
                'day' => Schema::int(), 'week' => Schema::int(),
                'top_ips' => Schema::list(Schema::object(['ip' => Schema::string(), 'count' => Schema::int()]))->description('The last week'),
                'recent' => Schema::list(Schema::object([
                    'id' => Schema::int(), 'occurred_at' => Schema::int(), 'ip' => Schema::string()->nullable(),
                    'reason' => Schema::string()->description('credentials, throttled, two_factor, inactive, external, external_refused'),
                    'user' => self::person()->nullable()->description('The account, when the login named one that exists'),
                ])),
            ]),
            'events' => Schema::list(Schema::object([
                'id' => Schema::int(), 'occurred_at' => Schema::int(), 'event' => Schema::string(), 'ip' => Schema::string()->nullable(),
                'subject_type' => Schema::string()->nullable(), 'subject_id' => Schema::string()->nullable(), 'data' => Schema::any(),
                'actor' => self::person()->nullable(),
            ]))->description('Recent account and access events from the audit log'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $overview = self::overview();
        $labels = array_map(static fn(array $r) => (string) $r['label'], self::cms()->auth->roles->roles());

        return Response::json(['data' => [
            'two_factor' => $overview->twoFactor(AuthModule::twoFactorRoles(self::cms())),
            'role_labels' => $labels,
            'failed_sign_ins' => $overview->failedSignIns(time()),
            'events' => $overview->events(),
        ]]);
    }
}
