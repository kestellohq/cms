<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Emails;

use Closure;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\I18n\SiteLanguage;
use Kestello\Cms\Mail\EmailRegistry;
use Kestello\Support\Translator;

/**
 * Shared plumbing for Settings → Emails: every email the site sends
 * ($cms->emails), its edits per language, previews and test sends. Needs
 * emails.manage.
 */
abstract class EmailEndpoint extends Endpoint
{
    /** The route's {id}: a named email, such as auth.password_reset. */
    protected const string ID = '[a-z][a-z0-9_-]*\.[a-z0-9_.-]{1,100}';

    public function capability(): ?string
    {
        return 'emails.manage';
    }
    public function scopes(): array
    {
        return ['settings:write'];
    }
    public function tags(): array
    {
        return ['emails'];
    }

    protected static function emails(): EmailRegistry
    {
        return Cms::instance()->emails ?? throw new \LogicException('Email endpoints need a booted CMS.');
    }

    /** @return array{id: string, label: string, group: string, description: string, owner: string, sample: array<string, mixed>, locked: list<string>, build: Closure(array<string, mixed>): \Kestello\Cms\Notifications\MailMessage} */
    protected static function email(ApiRequest $request): array
    {
        $id = (string) $request->param('id');
        if (!self::emails()->has($id)) {
            throw ApiProblem::notFound(Translator::get('No email is registered as “{id}”.', ['id' => $id]));
        }

        return self::emails()->get($id);
    }

    /** The language asked for (one of the site's), the site's own when none is. */
    protected static function locale(mixed $asked, string $field = 'locale'): string
    {
        $asked = is_string($asked) ? $asked : '';
        if ($asked === '') {
            return SiteLanguage::site();
        }
        if (!array_key_exists($asked, SiteLanguage::choices())) {
            throw ApiProblem::validation([$field => [Translator::get('The site has no language “{code}”. It has: {codes}.', ['code' => $asked, 'codes' => implode(', ', array_keys(SiteLanguage::choices()))])]]);
        }

        return $asked;
    }

    /**
     * $work run in a language, with the edit's errors turned into a 422.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    protected static function in(string $locale, Closure $work): mixed
    {
        return Translator::withLocale($locale, $work);
    }

    /**
     * The draft in a request body, checked against the email; null when the
     * body has no blocks (use what is saved).
     *
     * @return array{subject: string, blocks: list<array<string, mixed>>}|null
     */
    protected static function draft(ApiRequest $request, string $id): ?array
    {
        if (!$request->has('blocks')) {
            return null;
        }
        $edit = ['subject' => (string) $request->input('subject', ''), 'blocks' => $request->input('blocks')];
        $errors = self::emails()->check($id, $edit);
        if ($errors !== []) {
            throw ApiProblem::validation($errors, Translator::get('The email can’t be built like this.'));
        }

        /** @var array{subject: string, blocks: list<array<string, mixed>>} $edit */
        return $edit;
    }

    /** The body of a save, a preview and a test: a language and, optionally, a draft edit. */
    protected static function editBody(bool $required): Schema
    {
        $blocks = Schema::any(65536, 6)->description('The blocks, in order (see EmailEdit): heading, paragraph, note, button, image, divider, original');

        return Schema::object([
            'locale' => Schema::string()->max(12)->optional()->description('One of the site\'s languages; the site\'s own when left out'),
            'subject' => Schema::string()->max(255)->optional()->description('Empty keeps the email\'s own subject. Values go in as {name}.'),
            'blocks' => $required ? $blocks : $blocks->optional(),
        ]);
    }

    protected static function audit(ApiRequest $request, string $event, string $id, string $locale): void
    {
        Cms::instance()?->audit->record($event, ['email', $id], ['locale' => $locale], $request->userId());
    }
}
