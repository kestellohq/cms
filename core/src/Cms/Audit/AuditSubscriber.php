<?php

declare(strict_types=1);

namespace Kestello\Cms\Audit;

use Kestello\Cms\Events;
use Kestello\Cms\Platform\PlatformChanged;
use Kestello\Framework\Events\EventDispatcher;

/**
 * Writes core security events to the audit log.
 */
final class AuditSubscriber
{
    public function __construct(private readonly AuditLog $audit) {}

    public function subscribe(EventDispatcher $events): void
    {
        $a = $this->audit;

        $events->on(Events\UserLoggedIn::class, static fn(Events\UserLoggedIn $e) => $a->record('auth.login', ['user', $e->userId], ['method' => $e->method], $e->userId));
        $events->on(Events\UserLoggedOut::class, static fn(Events\UserLoggedOut $e) => $e->userId === null ? null : $a->record('auth.logout', ['user', $e->userId], actorId: $e->userId));
        $events->on(Events\LoginFailed::class, static fn(Events\LoginFailed $e) => $a->record(
            'auth.login_failed',
            $e->userId === null ? null : ['user', $e->userId],
            // Never the raw identifier: people paste passwords into the username field.
            ['reason' => $e->reason] + ($e->userId === null ? ['identifier_hash' => substr(hash('sha256', mb_strtolower(trim($e->identifier))), 0, 16)] : []),
        ));
        $events->on(Events\TwoFactorEnabled::class, static fn(Events\TwoFactorEnabled $e) => $a->record('auth.2fa_enabled', ['user', $e->userId], ['type' => $e->type], $e->userId));
        $events->on(Events\TwoFactorDisabled::class, static fn(Events\TwoFactorDisabled $e) => $a->record('auth.2fa_disabled', ['user', $e->userId], [], $e->byUserId));
        $events->on(Events\PasswordChanged::class, static fn(Events\PasswordChanged $e) => $a->record('auth.password_changed', ['user', $e->userId], [
            'via' => $e->via, 'sessions_revoked' => $e->sessionsRevoked, 'tokens_revoked' => $e->tokensRevoked,
        ]));
        $events->on(Events\PasswordResetRequested::class, static fn(Events\PasswordResetRequested $e) => $a->record('auth.password_reset_requested', ['user', $e->userId]));
        $events->on(Events\SessionRevoked::class, static fn(Events\SessionRevoked $e) => $a->record('auth.session_revoked', ['user', $e->userId], [
            'all_others' => $e->allOthers, 'count' => $e->count,
        ]));
        $events->on(Events\TokenCreated::class, static fn(Events\TokenCreated $e) => $a->record('auth.token_created', ['token', $e->tokenId], ['name' => $e->name, 'scopes' => $e->scopes, 'user_id' => $e->userId]));
        $events->on(Events\TokenRevoked::class, static fn(Events\TokenRevoked $e) => $a->record('auth.token_revoked', $e->tokenId === null ? ['user', $e->userId] : ['token', $e->tokenId], ['user_id' => $e->userId, 'all' => $e->tokenId === null]));
        $events->on(Events\IdentityLinked::class, static fn(Events\IdentityLinked $e) => $a->record('auth.identity_linked', ['user', $e->userId], ['provider' => $e->provider, 'via' => $e->via], $e->userId));
        $events->on(Events\IdentityUnlinked::class, static fn(Events\IdentityUnlinked $e) => $a->record('auth.identity_unlinked', ['user', $e->userId], ['provider' => $e->provider], $e->userId));
        $events->on(Events\UserRegistered::class, static fn(Events\UserRegistered $e) => $a->record('auth.registered', ['user', $e->userId], ['via' => $e->via], $e->userId));
        $events->on(PlatformChanged::class, static fn(PlatformChanged $e) => $a->record('platform.changed', ['platform', $e->subsystem], [
            'from' => $e->previousDriver, 'to' => $e->driver, 'changed' => $e->changed, 'reset' => $e->reset,
        ], $e->userId));
    }
}
