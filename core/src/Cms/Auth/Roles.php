<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use InvalidArgumentException;
use Kestello\Framework\Core\ExecutionContext;

/**
 * Roles and the capabilities they grant.
 *
 *   $cms->auth->roles->capability('acme-seo.manage', 'Manage SEO', grantTo: ['admin']);
 *   $cms->auth->roles->grant('editor', 'acme-seo.manage');
 *
 * Administrators hold "*" (every capability). Plugin capabilities must be
 * prefixed with the plugin slug ("acme-seo.").
 *
 * Roles declared in code are defaults. The Roles screen stores changes as
 * capabilities granted and revoked on top of them (RoleStore), so a plugin
 * update that grants a new capability still reaches a customized role.
 * Roles created in the admin are "custom": they start empty. The admin
 * role cannot be changed.
 *
 * @api
 */
final class Roles
{
    /** Core capabilities. */
    public const array CORE = [
        'read' => 'Sign in and see own profile',
        'admin.access' => 'Open the admin area',
        'content.access' => 'Use the content editor (each content type grants its own actions)',
        'profile.manage' => 'Manage own profile, tokens and sessions',
        'users.read' => 'List and view users',
        'users.manage' => 'Create, edit and delete users',
        'roles.manage' => 'Create and edit roles and what they may do',
        'menus.manage' => 'Edit menus and assign them to theme locations',
        'media.upload' => 'Upload files, and edit or delete their own',
        'media.manage' => 'Edit and delete everyone\'s files',
        'settings.manage' => 'Change site settings',
        'audit.read' => 'Read the audit log',
        'security.manage' => 'See everyone\'s sign-ins, sessions and access tokens, and end them',
        'webhooks.manage' => 'Set up webhooks that send site events to other services',
        'system.manage' => 'Manage infrastructure, background jobs, scheduled tasks and Site Health',
        'fleet.manage' => 'Watch other Kestello sites from Site → Fleet, with their fleet tokens',
    ];

    /**
     * Core capabilities as good as being an administrator: whoever has one
     * can make themselves one (users, roles), or act as anyone (sessions,
     * tokens, infrastructure). The Roles screen warns before granting them.
     */
    public const array SENSITIVE = ['users.manage', 'roles.manage', 'security.manage', 'system.manage'];

    /** @var array<string, array{label: string, owner: string, sensitive: bool}> */
    private array $capabilities = [];

    /** @var array<string, array{label: string, capabilities: list<string>}> */
    private array $roles = [
        'admin' => ['label' => 'Administrator', 'capabilities' => ['*']],
        'user' => ['label' => 'User', 'capabilities' => ['read', 'profile.manage']],
    ];

    /** @var array<string, array{label: string, description: ?string, custom: bool, granted: list<string>, revoked: list<string>}> */
    private array $stored = [];

    public function __construct()
    {
        foreach (self::CORE as $name => $label) {
            $this->capabilities[$name] = ['label' => $label, 'owner' => 'core', 'sensitive' => in_array($name, self::SENSITIVE, true)];
        }
    }

    /**
     * Declare a capability (and optionally grant it to roles right away).
     *
     * @param list<string> $grantTo
     * @param bool $sensitive as good as administrator access (adds code to pages, reads every row): flagged when granted
     */
    public function capability(string $name, string $label, array $grantTo = [], bool $sensitive = false): void
    {
        $owner = ExecutionContext::current();
        self::assertOwnedName($name, $owner, '.');

        if (isset($this->capabilities[$name]) && $this->capabilities[$name]['owner'] !== $owner) {
            throw new InvalidArgumentException("Capability [{$name}] is already declared by {$this->capabilities[$name]['owner']}.");
        }

        $this->capabilities[$name] = ['label' => $label, 'owner' => $owner, 'sensitive' => $sensitive];

        foreach ($grantTo as $role) {
            $this->grant($role, $name);
        }
    }

    /**
     * @param list<string> $capabilities
     */
    public function role(string $name, string $label, array $capabilities = []): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,19}$/', $name)) {
            throw new InvalidArgumentException("Invalid role name [{$name}].");
        }

        $this->roles[$name] ??= ['label' => $label, 'capabilities' => []];
        $this->roles[$name]['label'] = $label;
        $this->grant($name, ...$capabilities);
    }

    public function grant(string $role, string ...$capabilities): void
    {
        if (!isset($this->roles[$role])) {
            throw new InvalidArgumentException("Unknown role [{$role}].");
        }

        foreach ($capabilities as $capability) {
            if ($capability === '*') {
                throw new InvalidArgumentException('The "*" capability is reserved for administrators.');
            }
            if (!in_array($capability, $this->roles[$role]['capabilities'], true)) {
                $this->roles[$role]['capabilities'][] = $capability;
            }
        }
    }

    /**
     * Roles that hold a capability (directly or through "*"), for finding
     * who to notify: QueryBuilder::table('users')->whereIn('role', $roles->rolesWith('acme.manage')).
     *
     * @return list<string>
     */
    public function rolesWith(string $capability): array
    {
        $out = [];
        foreach (array_keys($this->roles()) as $role) {
            $caps = $this->capabilitiesFor($role);
            if (in_array('*', $caps, true) || in_array($capability, $caps, true)) {
                $out[] = $role;
            }
        }

        return $out;
    }

    /** Effective capabilities: the code defaults plus what the admin granted, minus what it revoked. */
    public function capabilitiesFor(string $role): array
    {
        return $this->roles()[$role]['capabilities'] ?? [];
    }

    public function exists(string $role): bool
    {
        return isset($this->roles()[$role]);
    }

    /**
     * @internal Kernel: overlay the roles edited in the admin (RoleStore::load()).
     *
     * @param array<string, array{label: string, description: ?string, custom: bool, granted: list<string>, revoked: list<string>}> $stored
     */
    public function applyStored(array $stored): void
    {
        unset($stored['admin']);
        $this->stored = $stored;
    }

    /** Capabilities the code declares for a role ([] for custom roles, null if unknown). */
    public function defaultsFor(string $role): ?array
    {
        if (isset($this->roles[$role])) {
            return $this->roles[$role]['capabilities'];
        }

        return ($this->stored[$role]['custom'] ?? false) ? [] : null;
    }

    public function isCustom(string $role): bool
    {
        return !isset($this->roles[$role]) && ($this->stored[$role]['custom'] ?? false);
    }

    public function isCustomized(string $role): bool
    {
        return isset($this->roles[$role], $this->stored[$role]);
    }

    public function description(string $role): ?string
    {
        return $this->stored[$role]['description'] ?? null;
    }

    public function hasCapability(string $name): bool
    {
        return isset($this->capabilities[$name]);
    }

    /** @return array<string, array{label: string, capabilities: list<string>}> effective roles, code-declared first */
    public function roles(): array
    {
        $out = $this->roles;
        foreach ($this->stored as $name => $row) {
            if ($name === 'admin' || (!isset($out[$name]) && !$row['custom'])) {
                continue; // a stored change to a role whose plugin is gone
            }
            $base = $out[$name]['capabilities'] ?? [];
            $caps = array_values(array_diff(array_unique([...$base, ...$row['granted']]), $row['revoked'], ['*']));
            $out[$name] = ['label' => $row['label'] !== '' ? $row['label'] : ($out[$name]['label'] ?? $name), 'capabilities' => $caps];
        }

        return $out;
    }

    /** @return array<string, array{label: string, owner: string, sensitive: bool}> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * Plugins may only declare names in their own namespace: "<slug><sep>...".
     */
    public static function assertOwnedName(string $name, string $owner, string $separator): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.:-]*$/', $name)) {
            throw new InvalidArgumentException("Invalid name [{$name}].");
        }

        if (str_starts_with($owner, 'plugin:') || str_starts_with($owner, 'theme:')) {
            $slug = explode(':', $owner, 2)[1];
            if (!str_starts_with($name, $slug . $separator)) {
                throw new InvalidArgumentException("[{$name}] must start with \"{$slug}{$separator}\" (declared by {$owner}).");
            }
        }
    }
}
