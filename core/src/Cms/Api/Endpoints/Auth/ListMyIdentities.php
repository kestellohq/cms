<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\External\Identities;
use Kestello\Cms\Auth\External\IdentityResolver;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Connected accounts, plus providers that can still be connected
 * (connect_url: open it in the browser after a recent sign-in).
 */
final class ListMyIdentities extends Endpoint
{
    public function __construct(private readonly Identities $identities) {}

    public function route(): ApiRoute
    {
        return ApiRoute::get('/me/identities');
    }
    public function capability(): ?string
    {
        return 'profile.manage';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function summary(): string
    {
        return 'List your connected sign-in accounts';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $userId = (int) $request->userId();
        $providers = Cms::instance()?->auth->authenticators() ?? [];
        $linked = [];
        $data = array_map(static function (array $row) use ($providers, &$linked): array {
            $linked[] = (string) $row['provider'];
            return [
                'id' => (int) $row['id'],
                'provider' => (string) $row['provider'],
                'label' => isset($providers[$row['provider']]) ? $providers[$row['provider']]->label() : (string) $row['provider'],
                'email' => $row['email'],
                'created_at' => (int) $row['created_at'],
                'last_used_at' => $row['last_used_at'] !== null ? (int) $row['last_used_at'] : null,
            ];
        }, $this->identities->forUser($userId));

        $available = [];
        foreach ($providers as $id => $a) {
            if (!in_array($id, $linked, true)) {
                $available[] = ['id' => $id, 'label' => $a->label(), 'connect_url' => '/auth/' . $id . '/redirect?intent=link'];
            }
        }
        $user = QueryBuilder::table('users')->where('id', $userId)->first() ?? [];

        return Response::json(['data' => $data, 'meta' => ['available' => $available, 'has_password' => IdentityResolver::hasUsablePassword($user)]]);
    }
}
