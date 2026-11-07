<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use InvalidArgumentException;
use Kestello\Cms\Auth\External\OAuth2Authenticator;
use Kestello\Contracts\Auth\Authenticator;
use Kestello\Contracts\Auth\Guard;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Core\Request;

/**
 * $cms->auth: guards, roles and capabilities.
 *
 *   $cms->auth->guard(new MyOAuthGuard(...));
 *   $cms->auth->authenticator(new Google([...]));   // "Continue with Google" on the login page
 *   $cms->auth->roles->capability('acme.manage', 'Manage Acme', grantTo: ['admin']);
 *
 * @api
 */
final class AuthRegistry
{
    public readonly Roles $roles;

    /** @var array<string, Guard> */
    private array $guards = [];

    /** @var array<string, string> guard id => owner that registered it */
    private array $owners = [];

    /** @var \WeakMap<Request, Principal> one authentication per request */
    private \WeakMap $resolved;

    /** The principal authenticated most recently in this process (audit actor). */
    private ?Principal $current = null;

    /** @var array<string, Authenticator> */
    private array $authenticators = [];

    /** @var array<string, string> authenticator id => owner */
    private array $authenticatorOwners = [];

    /** @var (Closure(string $owner): (array<string, mixed>|null))|null declared manifest permissions */
    private ?Closure $permissions = null;

    /** Ids that would clash with core routes under /auth/. */
    private const array RESERVED = ['password', 'session', 'providers', 'confirm', '2fa', 'two-factor', 'local'];

    public function __construct(?Roles $roles = null)
    {
        $this->roles = $roles ?? new Roles();
        $this->resolved = new \WeakMap();
    }

    public function guard(Guard $guard): void
    {
        $id = $guard->id();
        $owner = ExecutionContext::current();

        if (isset($this->guards[$id])) {
            throw new InvalidArgumentException("Guard [{$id}] is already registered.");
        }
        if ($owner !== 'core') {
            Roles::assertOwnedName($id, $owner, ':');
        }

        $this->guards[$id] = $guard;
        $this->owners[$id] = $owner;
    }

    /** @param Closure(string $owner): (array<string, mixed>|null) $resolver */
    public function setPermissionResolver(Closure $resolver): void
    {
        $this->permissions = $resolver;
    }

    /**
     * Add a sign-in method (OAuth 2, OIDC, SAML…). Plugins need
     * "permissions": {"auth": ["authenticator"]} in their manifest.
     * Core adds /auth/{id}/redirect and /auth/{id}/callback, the login
     * button and Connected accounts. Unconfigured OAuth providers (no client
     * id/secret) are registered but hidden.
     */
    public function authenticator(Authenticator $authenticator): void
    {
        $id = $authenticator->id();
        $owner = ExecutionContext::current();

        if (preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id) !== 1 || in_array($id, self::RESERVED, true)) {
            throw new InvalidArgumentException("Invalid authenticator id [{$id}]: use 1–32 lowercase letters, digits and dashes (not a reserved name).");
        }
        if (isset($this->authenticators[$id])) {
            throw new InvalidArgumentException("Authenticator [{$id}] is already registered by {$this->authenticatorOwners[$id]}.");
        }
        if ($owner !== 'core') {
            $declared = $this->permissions !== null ? ($this->permissions)($owner) : null;
            if (!in_array('authenticator', (array) ($declared['auth'] ?? []), true)) {
                throw new InvalidArgumentException("{$owner} must declare \"permissions\": {\"auth\": [\"authenticator\"]} in its manifest to add a sign-in method.");
            }
        }

        $this->authenticators[$id] = $authenticator;
        $this->authenticatorOwners[$id] = $owner;
    }

    /** Usable authenticators (configured ones only). @return array<string, Authenticator> */
    public function authenticators(): array
    {
        return array_filter(
            $this->authenticators,
            static fn(Authenticator $a) => !$a instanceof OAuth2Authenticator || $a->configured(),
        );
    }

    public function findAuthenticator(string $id): ?Authenticator
    {
        return $this->authenticators()[$id] ?? null;
    }

    /**
     * Run provider code as the package that registered it (its network
     * allowlist applies, errors are attributed to it).
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function runAuthenticator(string $id, Closure $callback): mixed
    {
        return ExecutionContext::run($this->authenticatorOwners[$id] ?? 'core', $callback);
    }

    /** @return array<string, Guard> */
    public function guards(): array
    {
        return $this->guards;
    }

    /**
     * Authenticate a request. A request with a bearer token is only ever
     * authenticated by bearer guards; an unaccepted token throws.
     *
     * @throws InvalidCredentials
     */
    public function authenticate(Request $request): Principal
    {
        if (isset($this->resolved[$request])) {
            return $this->resolved[$request];
        }

        $bearer = $request->bearerToken() !== null;

        foreach ($this->guards as $id => $guard) {
            if ($guard->isBearer() !== $bearer) {
                continue;
            }

            $principal = ExecutionContext::run($this->owners[$id], static fn() => $guard->authenticate($request));
            if ($principal !== null) {
                return $this->resolved[$request] = $this->current = $principal;
            }
        }

        if ($bearer) {
            throw new InvalidCredentials('The access token is invalid or expired.');
        }

        return $this->resolved[$request] = Principal::guest();
    }

    /** The principal of the request being handled, if one was authenticated. */
    public function current(): ?Principal
    {
        return $this->current;
    }

    /**
     * Build a principal for a user row. Capabilities come from the role.
     *
     * @param array<string, mixed> $user  a users row
     * @param list<string>|null    $scopes
     */
    public function principalFor(array $user, string $method, ?array $scopes = null, ?string $credentialId = null, ?int $authenticatedAt = null): Principal
    {
        $role = (string) ($user['role'] ?? '');

        return new Principal(
            userId: (int) $user['id'],
            user: [
                'id' => (int) $user['id'],
                'username' => (string) ($user['username'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'role' => $role,
            ],
            method: $method,
            capabilities: $this->roles->capabilitiesFor($role),
            scopes: $scopes,
            credentialId: $credentialId,
            authenticatedAt: $authenticatedAt,
        );
    }
}
