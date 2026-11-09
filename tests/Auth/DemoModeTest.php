<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Demo\DemoPolicy;

/** A public demo through the real API: what visitors signed in as "demo" can and cannot do. */
final class DemoModeTest extends AuthTestCase
{
    protected bool $demo = true;

    private function signIn(): void
    {
        $this->createUser(['username' => 'demo', 'email' => 'demo@example.test']);
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'demo', 'password' => self::PASSWORD])->statusCode());
    }

    /** @return array<string, mixed> */
    private function problem(\Kestello\Framework\Core\Response $response): array
    {
        return (array) json_decode($response->content(), true);
    }

    public function testTheAccountCannotBeTakenOver(): void
    {
        $this->signIn();
        foreach ([
            ['PUT', '/me/password', ['current_password' => self::PASSWORD, 'password' => 'a-new-long-passphrase-1']],
            ['PATCH', '/me', ['email' => 'mine@example.test']],
            ['POST', '/me/2fa/totp', []],
            ['POST', '/me/tokens', ['name' => 'x']],
            ['POST', '/users', ['username' => 'eve', 'email' => 'eve@example.test', 'role' => 'admin']],
            ['POST', '/auth/password/forgot', ['email' => 'demo@example.test']],
        ] as [$method, $path, $body]) {
            $response = $this->api($method, $path, $body);
            $this->assertSame(403, $response->statusCode(), "{$method} {$path}: " . $response->content());
            $this->assertSame(['urn:kestello:problem:demo', DemoPolicy::REFUSED], [$this->problem($response)['type'] ?? null, $this->problem($response)['detail'] ?? null]);
        }
        $this->assertSame([], $this->mail, 'no email went out');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'demo', 'password' => self::PASSWORD])->statusCode(), 'the password still works');
    }

    public function testOtherVisitorsTracesStayHidden(): void
    {
        $this->signIn();
        $this->assertSame(200, $this->api('GET', '/me')->statusCode());
        $this->assertSame(403, $this->api('GET', '/me/sessions')->statusCode(), 'every visitor shares the account: its sessions are theirs');
        $this->assertSame(403, $this->api('GET', '/audit')->statusCode());
    }

    public function testSigningOutAndNotificationsStillWork(): void
    {
        $this->signIn();
        $this->assertNotSame(403, $this->api('POST', '/me/notifications/read', [])->statusCode());
        $this->assertSame(204, $this->api('DELETE', '/auth/session')->statusCode());
    }

    public function testWhatTheDemoOpensIsReviewedWhenItChanges(): void
    {
        // a new endpoint under an open capability opens by itself: this list makes that a visible change
        $open = [];
        foreach ($this->cms->api->endpoints() as $entry) {
            $route = $entry['route'];
            if (!in_array($route->method, ['GET', 'HEAD'], true) && DemoPolicy::allows($route->method, $route->path, $entry['endpoint']->capability(), $entry['endpoint']->requiresRecentAuth())) {
                $open[] = $route->method . ' ' . $route->path;
            }
        }
        sort($open);
        $this->assertSame(self::OPEN, $open, json_encode($open, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private const array OPEN = [
        'DELETE /auth/session',
        'DELETE /content/{type}/entries/{id}',
        'DELETE /content/{type}/entries/{id}/comments/{comment}',
        'DELETE /content/{type}/entries/{id}/previews/{preview}',
        'DELETE /design/styles',
        'DELETE /design/templates/{kind}/{key}',
        'DELETE /design/templates/{kind}/{key}/draft',
        'DELETE /form-submissions/{id}',
        'DELETE /forms/{id}',
        'DELETE /media/uploads/{id}',
        'DELETE /media/{id}',
        'DELETE /menus/{id}',
        'DELETE /taxonomies/{taxonomy}/terms/{id}',
        'PATCH /content/{type}/entries/{id}',
        'PATCH /form-submissions/{id}',
        'PATCH /forms/{id}',
        'PATCH /media/{id}',
        'PATCH /taxonomies/{taxonomy}/terms/{id}',
        'POST /auth/2fa/verify',
        'POST /auth/confirm',
        'POST /auth/session',
        'POST /content/{type}/entries',
        'POST /content/{type}/entries/{id}/comments',
        'POST /content/{type}/entries/{id}/previews',
        'POST /content/{type}/entries/{id}/request-changes',
        'POST /content/{type}/entries/{id}/restore',
        'POST /content/{type}/entries/{id}/revisions/{revision}/restore',
        'POST /content/{type}/preview-blocks',
        'POST /design/preview',
        'POST /design/styles/revisions/{id}/restore',
        'POST /design/templates/revisions/{id}/restore',
        'POST /design/templates/{kind}',
        'POST /design/templates/{kind}/{key}/publish',
        'POST /forms',
        'POST /forms/{id}/submissions',
        'POST /me/notifications/read',
        'POST /media/uploads',
        'POST /media/uploads/{id}/complete',
        'POST /media/{id}/edit',
        'POST /media/{id}/restore',
        'POST /menus',
        'POST /taxonomies/{taxonomy}/terms',
        'PUT /content/{type}/entries/{id}/assignee',
        'PUT /content/{type}/entries/{id}/autosave',
        'PUT /design/styles',
        'PUT /design/templates/{kind}/{key}',
        'PUT /me/notification-preferences',
        'PUT /media/uploads/{id}/chunks/{index}',
        'PUT /menus/locations',
        'PUT /menus/{id}',
    ];
}
