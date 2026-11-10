<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Cms\Auth\External\IdentityResolver;
use Kestello\Cms\Notifications\Notifier;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Events\Filters;

/**
 * Everything the site keeps about one person, for them to download (GET /me/export): the
 * account, its connected sign-ins, sessions, API tokens, two-step sign-in, notification
 * choices, the audit entries about them and the form submissions they sent. Never a secret:
 * no password hash, token, session id or two-step key. Plugins add their own sections with the
 * filter account.export.
 *
 *   $export->for($userId)   ['format' => 'kestello.account-export', 'version' => 1, 'account' => [...], ...]
 */
final class AccountExport
{
    public const string FORMAT = 'kestello.account-export';

    /** The newest audit entries and form submissions kept in one export. */
    public const int LIMIT = 5000;

    /** The sections core writes; a plugin's section may not replace one. */
    public const array CORE = ['format', 'version', 'exported_at', 'site', 'account', 'two_factor', 'connected_sign_ins', 'sessions', 'api_tokens', 'notification_preferences', 'audit_log', 'form_submissions'];

    public function __construct(
        private readonly AccountSecurity $security,
        private readonly TwoFactor $twoFactor,
        private readonly Notifier $notifier,
        private readonly ?EmailChanges $emailChanges,
        private readonly ?Filters $filters,
        private readonly string $siteName = '',
        private readonly string $siteUrl = '',
    ) {}

    /** @return array<string, mixed> null when there is no such account */
    public function for(int $userId, ?int $now = null): ?array
    {
        $user = QueryBuilder::table('users')->where('id', $userId)->first();
        if ($user === null) {
            return null;
        }
        $user = (array) $user;
        $twoFactor = $this->twoFactor->status($userId);

        $data = [
            'format' => self::FORMAT,
            'version' => 1,
            'exported_at' => gmdate('c', $now ?? time()),
            'site' => ['name' => $this->siteName, 'url' => $this->siteUrl],
            'account' => [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'email' => (string) $user['email'],
                'pending_email' => $this->emailChanges?->pending($userId),
                'role' => (string) $user['role'],
                'active' => (bool) $user['is_active'],
                'bio' => $user['bio'] ?? null,
                'has_password' => IdentityResolver::hasUsablePassword($user),
                'created_at' => self::date($user['created_at'] ?? null),
                'updated_at' => self::date($user['updated_at'] ?? null),
                'last_login_at' => self::date($user['last_login_at'] ?? null),
            ],
            'two_factor' => [
                'enabled' => (bool) $twoFactor['enabled'],
                'type' => $twoFactor['type'],
                'enabled_at' => self::date($twoFactor['enabled_at']),
                'last_used_at' => self::date($twoFactor['last_used_at']),
                'recovery_codes_remaining' => (int) $twoFactor['recovery_codes_remaining'],
            ],
            'connected_sign_ins' => array_map(static fn(array $r): array => [
                'provider' => (string) $r['provider'],
                'email' => $r['email'],
                'connected_at' => self::date($r['created_at']),
                'last_used_at' => self::date($r['last_used_at']),
            ], self::rows(QueryBuilder::table('user_identities')->where('user_id', $userId)->orderBy('id')->get())),
            'sessions' => array_map(static fn(array $s): array => [
                'ip' => $s['ip'],
                'user_agent' => $s['user_agent'],
                'signed_in_at' => self::date($s['created_at']),
                'last_active_at' => self::date($s['last_active_at']),
            ], $this->security->sessions($userId)),
            'api_tokens' => array_map(static fn(array $r): array => [
                'name' => (string) $r['name'],
                'scopes' => self::list($r['scopes']),
                'created_at' => self::date($r['created_at']),
                'expires_at' => self::date($r['expires_at']),
                'last_used_at' => self::date($r['last_used_at']),
                'last_used_ip' => $r['last_used_ip'],
            ], self::rows(QueryBuilder::table('personal_access_tokens')->where('user_id', $userId)->orderBy('id')->get())),
            'notification_preferences' => $this->preferences($userId),
            'audit_log' => $this->audit($userId),
            'form_submissions' => $this->submissions($userId, (string) $user['email']),
        ];

        return $this->withPlugins($data, $user);
    }

    /**
     * A plugin's sections are added; core's sections stay as they are.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function withPlugins(array $data, array $user): array
    {
        if ($this->filters === null) {
            return $data;
        }
        $filtered = $this->filters->apply('account.export', $data, $user);
        if (!is_array($filtered)) {
            return $data;
        }
        foreach ($filtered as $key => $value) {
            if (is_string($key) && !in_array($key, self::CORE, true)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /** @return list<array{type: string, label: string, channel: string, enabled: bool}> */
    private function preferences(int $userId): array
    {
        $out = [];
        foreach ($this->notifier->types() as $type => $t) {
            $off = $this->notifier->disabledChannels($userId, $type);
            foreach ($this->notifier->channels() as $channel) {
                $out[] = ['type' => $type, 'label' => (string) $t['label'], 'channel' => $channel, 'enabled' => !in_array($channel, $off, true)];
            }
        }

        return $out;
    }

    /**
     * What the person did and what was done to their account. An entry where they acted on
     * something else keeps its event, time and place but not its details, which may be about
     * someone else.
     *
     * @return list<array<string, mixed>>
     */
    private function audit(int $userId): array
    {
        $rows = QueryBuilder::table('audit_log')
            ->where(static fn(QueryBuilder $q) => $q->where('actor_id', $userId)
                ->orWhere(static fn(QueryBuilder $q) => $q->where('subject_type', 'user')->where('subject_id', (string) $userId)))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(self::LIMIT)->get();

        return array_map(static function (array $r) use ($userId): array {
            $aboutThem = $r['subject_type'] === null || ($r['subject_type'] === 'user' && (string) $r['subject_id'] === (string) $userId);

            return [
                'event' => (string) $r['event'],
                'occurred_at' => self::date($r['occurred_at']),
                'by_you' => $r['actor_id'] !== null && (int) $r['actor_id'] === $userId,
                'subject' => $r['subject_type'] === null ? null : $r['subject_type'] . ':' . $r['subject_id'],
                'ip' => $r['ip'],
                'user_agent' => $r['user_agent'],
                'data' => $aboutThem ? self::json($r['data']) : null,
            ];
        }, self::rows($rows));
    }

    /**
     * Submissions sent while signed in as them, or with their address as one of the answers.
     *
     * @return list<array<string, mixed>>
     */
    private function submissions(int $userId, string $email): array
    {
        $rows = QueryBuilder::table('form_submissions')
            ->leftJoin('forms', 'forms.id', '=', 'form_submissions.form_id')
            ->select('form_submissions.id', 'form_submissions.data', 'form_submissions.page', 'form_submissions.ip', 'form_submissions.user_agent', 'form_submissions.user_id', 'form_submissions.created_at', 'forms.title')
            ->where(static fn(QueryBuilder $q) => $q->where('form_submissions.user_id', $userId)
                ->orWhere(static fn(QueryBuilder $q) => $email === '' ? $q->whereRaw('1 = 0') : $q->whereLike('form_submissions.data', '%' . QueryBuilder::escapeLike($email) . '%')))
            ->orderByDesc('form_submissions.id')->limit(self::LIMIT)->get();

        $out = [];
        foreach (self::rows($rows) as $r) {
            $answers = self::json($r['data']) ?? [];
            // the LIKE above is a prefilter: keep a stranger's submission only if an answer is exactly the address
            $theirs = $r['user_id'] !== null && (int) $r['user_id'] === $userId;
            if (!$theirs && !self::answeredWith($answers, $email)) {
                continue;
            }
            $out[] = [
                'form' => $r['title'],
                'sent_at' => self::date($r['created_at']),
                'page' => (string) $r['page'],
                'ip' => (string) $r['ip'],
                'user_agent' => (string) $r['user_agent'],
                'answers' => $answers,
            ];
        }

        return $out;
    }

    /** @param array<mixed> $answers */
    private static function answeredWith(array $answers, string $email): bool
    {
        foreach ($answers as $value) {
            if (is_string($value) && strcasecmp(trim($value), $email) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows): array
    {
        return array_values(array_map(static fn($r): array => (array) $r, $rows));
    }

    /** @return array<mixed>|null */
    private static function json(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    private static function list(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        if (is_array($decoded)) {
            return array_values(array_map('strval', $decoded));
        }

        return is_string($value) && $value !== '' ? array_values(array_filter(array_map('trim', explode(',', $value)))) : [];
    }

    /** Unix seconds or a database timestamp, as ISO 8601 in UTC. */
    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (is_int($value) || ctype_digit((string) $value)) {
            return gmdate('c', (int) $value);
        }
        $time = strtotime((string) $value . ' UTC');

        return $time === false ? (string) $value : gmdate('c', $time);
    }
}
