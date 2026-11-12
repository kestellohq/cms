<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Log\Log;

/**
 * The saved edits of the site's emails, one per email and language
 * (the email_edits table). EmailEdit checks and applies them.
 *
 *   $edits->find('auth.password_reset', 'sr')   ['subject' => …, 'blocks' => […], 'updated_at' => …, 'updated_by' => 3] or null
 *   $edits->save('auth.password_reset', 'sr', $edit, $userId)
 *   $edits->delete('auth.password_reset', 'sr')  back to the email as its code writes it
 *   $edits->edited()                            ['auth.password_reset' => ['en', 'sr'], …]
 *
 * @internal
 */
final class EmailEdits
{
    public const string TABLE = 'email_edits';

    /** @var array<string, array{subject: string, blocks: list<array<string, mixed>>, updated_at: ?string, updated_by: ?int}|null> by "email|locale", for one request */
    private array $found = [];

    /** Forget what find() read, so edits saved since are used: between a worker's jobs. */
    public function forget(): void
    {
        $this->found = [];
    }

    /** @return array{subject: string, blocks: list<array<string, mixed>>, updated_at: ?string, updated_by: ?int}|null */
    public function find(string $email, string $locale): ?array
    {
        $key = $email . '|' . $locale;
        if (array_key_exists($key, $this->found)) {
            return $this->found[$key];
        }
        try {
            $row = QueryBuilder::table(self::TABLE)->where('email', $email)->where('locale', $locale)->first();
        } catch (\PDOException $e) {
            Log::error("email edits could not be read ({$e->getMessage()}); \"{$email}\" is sent as its code writes it. Run: php bin/cms migrate");

            return $this->found[$key] = null;
        }
        $blocks = $row !== null ? json_decode((string) $row['blocks'], true) : null;
        if ($row !== null && !is_array($blocks)) {
            Log::warning("the saved edit of \"{$email}\" ({$locale}) is not valid JSON; it is sent as its code writes it.");
        }

        return $this->found[$key] = is_array($blocks) ? [
            'subject' => (string) $row['subject'],
            'blocks' => array_values($blocks),
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
        ] : null;
    }

    /** @param array{subject: string, blocks: list<array<string, mixed>>} $edit a valid edit (EmailEdit::validate()) */
    public function save(string $email, string $locale, array $edit, ?int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        QueryBuilder::table(self::TABLE)->upsert([[
            'email' => $email,
            'locale' => $locale,
            'subject' => trim($edit['subject']),
            'blocks' => json_encode(array_values($edit['blocks']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['email', 'locale'], ['subject', 'blocks', 'updated_by', 'updated_at']);
        unset($this->found[$email . '|' . $locale]);
    }

    /** True when there was an edit to remove. */
    public function delete(string $email, string $locale): bool
    {
        unset($this->found[$email . '|' . $locale]);

        return QueryBuilder::table(self::TABLE)->where('email', $email)->where('locale', $locale)->delete() > 0;
    }

    /** @return array<string, list<string>> the languages each edited email has an edit for */
    public function edited(): array
    {
        $edited = [];
        foreach (QueryBuilder::table(self::TABLE)->select('email', 'locale')->orderBy('locale')->get() as $row) {
            $edited[(string) $row['email']][] = (string) $row['locale'];
        }

        return $edited;
    }
}
