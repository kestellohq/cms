<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Contracts\Security\SecretStore;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Hash;
use LogicException;

/**
 * TOTP second factor with recovery codes.
 *
 * Secrets are sealed with the app key and bound to their user (a secret
 * copied to another row fails to open). Each code works once (replay
 * protection by time step). Recovery codes are single-use and stored as
 * HMACs; the plain codes are shown once.
 */
final class TwoFactor
{
    public const string METHODS = 'mfa_methods';
    public const string CODES = 'mfa_recovery_codes';
    public const int RECOVERY_CODES = 10;

    private const string CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** @param Closure(): SecretStore $secrets */
    public function __construct(private readonly Closure $secrets) {}

    public function isEnabled(int $userId): bool
    {
        return QueryBuilder::table(self::METHODS)->where('user_id', $userId)->whereNotNull('confirmed_at')->exists();
    }

    /** @return array{enabled: bool, type: ?string, enabled_at: ?int, last_used_at: ?int, recovery_codes_remaining: int} */
    public function status(int $userId): array
    {
        $method = QueryBuilder::table(self::METHODS)->where('user_id', $userId)->whereNotNull('confirmed_at')->first();

        return [
            'enabled' => $method !== null,
            'type' => $method['type'] ?? null,
            'enabled_at' => isset($method['confirmed_at']) ? (int) $method['confirmed_at'] : null,
            'last_used_at' => isset($method['last_used_at']) ? (int) $method['last_used_at'] : null,
            'recovery_codes_remaining' => $method === null ? 0 : $this->recoveryCodesLeft($userId),
        ];
    }

    /**
     * Start TOTP enrollment. Returns the secret and an otpauth:// URI for the
     * QR code. Nothing changes for login until confirmTotp() succeeds.
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function beginTotp(int $userId, string $account, string $issuer): array
    {
        if ($this->isEnabled($userId)) {
            throw new LogicException('Two-factor authentication is already enabled. Disable it first to enroll a new device.');
        }

        QueryBuilder::table(self::METHODS)->where('user_id', $userId)->whereNull('confirmed_at')->delete();

        $secret = Totp::generateSecret();
        QueryBuilder::table(self::METHODS)->insert([
            'user_id' => $userId,
            'type' => 'totp',
            'secret' => ($this->secrets)()->seal($secret, self::context($userId)),
            'label' => 'Authenticator app',
            'created_at' => time(),
        ]);

        return ['secret' => $secret, 'otpauth_uri' => Totp::provisioningUri($secret, $account, $issuer)];
    }

    /**
     * Finish enrollment with a code from the app. Returns the recovery codes
     * (shown once), or null when the code is wrong or there is no enrollment.
     *
     * @return list<string>|null
     */
    public function confirmTotp(int $userId, #[\SensitiveParameter] string $code): ?array
    {
        $method = QueryBuilder::table(self::METHODS)->where('user_id', $userId)->whereNull('confirmed_at')->orderBy('id', 'DESC')->first();
        if ($method === null || time() - (int) $method['created_at'] > 3600) {
            return null;
        }

        $step = Totp::verify($this->open($method), $code);
        if ($step === null) {
            return null;
        }

        QueryBuilder::table(self::METHODS)->where('id', $method['id'])->update([
            'confirmed_at' => time(),
            'last_used_at' => time(),
            'last_used_step' => $step,
        ]);

        return $this->regenerateRecoveryCodes($userId);
    }

    /**
     * Check a login code: a TOTP code, or a recovery code (used up).
     *
     * @return 'totp'|'recovery'|null
     */
    public function verify(int $userId, #[\SensitiveParameter] string $code): ?string
    {
        $method = QueryBuilder::table(self::METHODS)->where('user_id', $userId)->whereNotNull('confirmed_at')->first();
        if ($method === null) {
            return null;
        }

        $digits = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . Totp::DIGITS . '}$/', $digits) === 1) {
            $last = $method['last_used_step'] === null ? null : (int) $method['last_used_step'];
            $step = Totp::verify($this->open($method), $digits, $last);
            if ($step === null) {
                return null;
            }

            // Conditional update: two requests racing with one code can't both win.
            $updated = QueryBuilder::table(self::METHODS)
                ->where('id', $method['id'])
                ->where(fn(QueryBuilder $q) => $q->whereNull('last_used_step')->orWhere('last_used_step', '<', $step))
                ->update(['last_used_step' => $step, 'last_used_at' => time()]);

            return $updated === 1 ? 'totp' : null;
        }

        return $this->useRecoveryCode($userId, $code) ? 'recovery' : null;
    }

    /** Remove every second factor and recovery code of the user. */
    public function disable(int $userId): bool
    {
        $removed = QueryBuilder::table(self::METHODS)->where('user_id', $userId)->delete();
        QueryBuilder::table(self::CODES)->where('user_id', $userId)->delete();

        return $removed > 0;
    }

    /** @return list<string> new codes, shown once ("ABCDE-FGHJK") */
    public function regenerateRecoveryCodes(int $userId): array
    {
        QueryBuilder::table(self::CODES)->where('user_id', $userId)->delete();

        $codes = [];
        $rows = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::CROCKFORD[random_int(0, 31)];
            }
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $rows[] = ['user_id' => $userId, 'code_hash' => Hash::hmac($raw, 'mfa.recovery')];
        }
        QueryBuilder::table(self::CODES)->insertMany($rows);

        return $codes;
    }

    public function recoveryCodesLeft(int $userId): int
    {
        return QueryBuilder::table(self::CODES)->where('user_id', $userId)->whereNull('used_at')->count();
    }

    private function useRecoveryCode(int $userId, #[\SensitiveParameter] string $code): bool
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        // Crockford: people type O for 0 and I/L for 1.
        $normalized = strtr($normalized, ['O' => '0', 'I' => '1', 'L' => '1']);
        if (strlen($normalized) !== 10) {
            return false;
        }

        foreach (QueryBuilder::table(self::CODES)->where('user_id', $userId)->whereNull('used_at')->get() as $row) {
            if (Hash::verifyHmac($normalized, (string) $row['code_hash'], 'mfa.recovery')) {
                return QueryBuilder::table(self::CODES)->where('id', $row['id'])->whereNull('used_at')->update(['used_at' => time()]) === 1;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $method */
    private function open(array $method): string
    {
        return ($this->secrets)()->open((string) $method['secret'], self::context((int) $method['user_id']));
    }

    private static function context(int $userId): string
    {
        return 'mfa.totp.' . $userId;
    }
}
