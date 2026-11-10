<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Auth;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Auth\AccountExport;
use Kestello\Cms\Auth\AccountSecurity;
use Kestello\Cms\Auth\AuthModule;
use Kestello\Cms\Auth\EmailChanges;
use Kestello\Cms\Auth\TwoFactor;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Response;

/**
 * Download everything the site keeps about you, as one JSON file (AccountExport). In a signed-in
 * browser only, after a recent sign-in: the file holds where and when you signed in.
 */
final class ExportMyData extends Endpoint
{
    use SessionOnly;

    public function __construct(
        private readonly AccountSecurity $security,
        private readonly TwoFactor $twoFactor,
    ) {}

    public function route(): ApiRoute
    {
        return ApiRoute::get('/me/export');
    }
    public function capability(): ?string
    {
        return 'profile.manage';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [10, 3600];
    }
    public function summary(): string
    {
        return 'Download your data';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $userId = (int) $request->userId();
        [$name, $url] = AuthModule::site($cms);
        $changes = $cms->container->has(EmailChanges::class) ? $cms->container->make(EmailChanges::class) : null;
        $data = (new AccountExport($this->security, $this->twoFactor, $cms->notifications, $changes, $cms->filters, $name, $url))->for($userId)
            ?? throw ApiProblem::unauthenticated();
        $cms->audit->record('account.exported', ['user', (string) $userId], [], $userId);

        $file = self::filename($name, (string) $data['account']['username'], gmdate('Y-m-d'));

        return new Response((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $file . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** "acme-account-ana-2026-11-11.json": letters, digits and dashes only. */
    public static function filename(string $site, string $username, string $date): string
    {
        $slug = static fn(string $s): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
        $parts = array_filter([$slug($site), 'account', $slug($username), $date], static fn(string $p): bool => $p !== '');

        return implode('-', $parts) . '.json';
    }
}
