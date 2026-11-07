<?php

declare(strict_types=1);

namespace Kestello\Cms\Models;

use Kestello\Framework\Database\Model;

/**
 * User model.
 */
final class User extends Model
{
    protected string $table = 'users';

    protected array $fillable = [
        'username',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected array $hidden = [
        'password',
    ];

    protected array $casts = [
        'is_active' => 'bool',
    ];

    /**
     * Verify a password against the user's hash.
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, (string) $this->password);
    }

    /**
     * Set the password (hashes it automatically).
     */
    public function setPassword(string $password): void
    {
        $this->attributes['password'] = \Kestello\Support\Hash::password($password);
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
