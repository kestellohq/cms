<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;

/**
 * Try again to put back an update that stopped halfway, after the site
 * gave up trying by itself. The next request does it, before plugins load.
 */
final class RetryRecovery extends UpdatesEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/updates/recover');
    }
    public function summary(): string
    {
        return 'Try again to put back an update that stopped halfway';
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [5, 60];
    }

    public function handle(ApiRequest $request): mixed
    {
        $journal = $this->recovery()->journal();
        if ($journal->stopped() === null) {
            throw \Kestello\Cms\Api\ApiProblem::conflict('No update stopped halfway.');
        }
        $journal->retry();
        $this->cms()->audit->record('update.recovery_retried', null, ['owner' => $journal->current()['owner'] ?? null], $request->userId());

        return $this->versions();
    }
}
