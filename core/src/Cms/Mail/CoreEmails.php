<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

use Kestello\Cms\Notifications\MailMessage;
use Kestello\Support\Translator;

/**
 * Core's own emails, registered by name. Each builder takes the values its
 * sender passes (and the sample values, for a preview) and returns the
 * message. The block that carries an email's link is named "link" and the
 * sentence that tells someone what to do if they didn't ask is named
 * "safety"; both are locked. The sentence saying how long a link works is
 * named "expiry", so an edit keeps its number.
 *
 * @internal
 */
final class CoreEmails
{
    private const string LINK = 'https://example.com/link?token=sample';

    public static function register(EmailRegistry $emails): void
    {
        // named arguments, so bin/cms i18n:extract finds the label and description
        $accounts = 'Accounts';
        $emails->register(
            'auth.sign_up_link',
            label: 'Sign-up link',
            build: self::signUpLink(...),
            sample: ['site' => 'Kestello', 'link' => self::LINK, 'hours' => 24],
            group: $accounts,
            description: 'Sent when someone signs up with an address that has no account yet. The link lets them choose a password.',
            locked: ['link', 'safety'],
        );
        $emails->register(
            'auth.sign_up_existing',
            label: 'Sign-up for an existing account',
            build: self::signUpExisting(...),
            sample: ['site' => 'Kestello', 'name' => 'ana', 'login_url' => 'https://example.com/login', 'reset_url' => 'https://example.com/forgot-password'],
            group: $accounts,
            description: 'Sent instead of the sign-up link when the address already has an account, so nobody learns which addresses do.',
            locked: ['link', 'safety'],
        );
        $emails->register(
            'auth.password_reset',
            label: 'Password reset',
            build: self::passwordReset(...),
            sample: ['site' => 'Kestello', 'name' => 'ana', 'link' => self::LINK, 'minutes' => 60],
            group: $accounts,
            description: 'Sent when someone asks to reset their password.',
            locked: ['link', 'safety'],
        );
        $emails->register(
            'auth.email_change_confirm',
            label: 'New email address',
            build: self::emailChangeConfirm(...),
            sample: ['site' => 'Kestello', 'name' => 'ana', 'link' => self::LINK, 'hours' => 24],
            group: $accounts,
            description: 'Sent to the new address when someone changes their email. The account keeps the old one until this is confirmed.',
            locked: ['link', 'safety'],
        );
        $emails->register(
            'auth.email_change_notice',
            label: 'Email address changing',
            build: self::emailChangeNotice(...),
            sample: ['site' => 'Kestello', 'name' => 'ana', 'email' => 'ana@new.example'],
            group: $accounts,
            description: 'Sent to the old address when someone asks to change their email.',
            locked: ['safety'],
        );
        $emails->register(
            'auth.sign_in_method_added',
            label: 'Sign-in method added',
            build: self::signInMethodAdded(...),
            sample: ['site' => 'Kestello', 'name' => 'ana', 'provider' => 'github', 'label' => 'GitHub'],
            group: $accounts,
            description: 'Sent when a provider such as GitHub or Google is connected to an existing account.',
            locked: ['safety'],
        );
        $emails->register(
            'auth.password_known',
            label: 'Password known to someone else',
            build: self::passwordKnown(...),
            sample: ['site' => 'Kestello', 'name' => 'ana', 'count' => 5],
            group: $accounts,
            description: 'Sent when someone enters the right password but keeps failing the two-step code.',
            locked: ['safety'],
        );
        $emails->register(
            'auth.account_deleted',
            label: 'Account deleted',
            build: self::accountDeleted(...),
            sample: ['site' => 'Kestello'],
            group: $accounts,
            description: 'Sent after someone deletes their own account.',
            locked: ['safety'],
        );
        $emails->register(
            'forms.submission',
            label: 'Form submission',
            build: self::formSubmission(...),
            sample: [
                'subject' => 'New submission: Contact', 'form' => 'Contact', 'site' => 'Kestello', 'page' => '/contact',
                'rows' => [['label' => 'Name', 'value' => 'Ana'], ['label' => 'Message', 'value' => "Two loaves, please.\nPickup on Friday."]],
                'admin_url' => 'https://example.com/admin/forms/1/submissions?open=1',
            ],
            group: 'Forms',
            description: 'Sent to the addresses a form notifies when someone sends it. Each form sets its own subject.',
        );
        $emails->register(
            'settings.test_email',
            label: 'Test email',
            build: self::testEmail(...),
            sample: ['site' => 'Kestello'],
            group: 'Site',
            description: 'Sent from Settings → Email to check that the site can send email.',
        );
    }

    /** @param array<string, mixed> $d site, link, hours */
    public static function signUpLink(array $d): MailMessage
    {
        $site = ['site' => (string) $d['site']];

        return MailMessage::make(Translator::get('Finish creating your {site} account', $site))
            ->heading(Translator::get('Hello,'))
            ->paragraph(Translator::get('Someone asked to create a {site} account with this email address. If it was you, choose your password with the button below.', $site))
            ->button(Translator::get('Finish creating my account'), (string) $d['link'])->name('link')
            ->paragraph(self::hours((int) $d['hours']))->name('expiry')
            ->paragraph(Translator::get('If you didn’t ask for this, ignore this email: no account is created.'))->name('safety');
    }

    /** @param array<string, mixed> $d site, name, login_url, reset_url */
    public static function signUpExisting(array $d): MailMessage
    {
        $site = ['site' => (string) $d['site']];

        return MailMessage::make(Translator::get('You already have a {site} account', $site))
            ->heading(Translator::get('Hi {name},', ['name' => (string) $d['name']]))
            ->paragraph(Translator::get('Someone tried to create a {site} account with this email address, but you already have one.', $site))
            ->button(Translator::get('Sign in'), (string) $d['login_url'])->name('link')
            ->paragraph(Translator::get('If you forgot your password, reset it here: {url}', ['url' => (string) $d['reset_url']]))
            ->paragraph(Translator::get('If it wasn’t you, ignore this email: nothing about your account changed.'))->name('safety');
    }

    /** @param array<string, mixed> $d site, name, link, minutes */
    public static function passwordReset(array $d): MailMessage
    {
        $site = ['site' => (string) $d['site']];

        return MailMessage::make(Translator::get('Reset your {site} password', $site))
            ->heading(Translator::get('Hi {name},', ['name' => (string) $d['name']]))
            ->paragraph(Translator::get('Someone asked to reset the password for your {site} account. If it was you, choose a new password with the button below.', $site))
            ->button(Translator::get('Reset my password'), (string) $d['link'])->name('link')
            ->paragraph(Translator::get('{minutes, plural, one {The link works once and expires in # minute.} other {The link works once and expires in # minutes.}}', ['minutes' => (int) $d['minutes']]))->name('expiry')
            ->paragraph(Translator::get('If you didn’t ask for this, ignore this email: your password stays the same.'))->name('safety');
    }

    /** @param array<string, mixed> $d site, name, link, hours */
    public static function emailChangeConfirm(array $d): MailMessage
    {
        $site = ['site' => (string) $d['site']];

        return MailMessage::make(Translator::get('Confirm your new {site} email', $site))
            ->heading(Translator::get('Hi {name},', ['name' => (string) $d['name']]))
            ->paragraph(Translator::get('You asked to use this address for your {site} account. Confirm it with the button below.', $site))
            ->button(Translator::get('Use this email address'), (string) $d['link'])->name('link')
            ->paragraph(self::hours((int) $d['hours']))->name('expiry')
            ->paragraph(Translator::get('Until then your account keeps its old address. If you didn’t ask for this, ignore this email.'))->name('safety');
    }

    /** @param array<string, mixed> $d site, name, email */
    public static function emailChangeNotice(array $d): MailMessage
    {
        return MailMessage::make(Translator::get('Your {site} email is being changed', ['site' => (string) $d['site']]))
            ->heading(Translator::get('Hi {name},', ['name' => (string) $d['name']]))
            ->paragraph(Translator::get('Someone signed in to your {site} account asked to change its email address to {email}. Nothing changes until that address confirms it.', ['site' => (string) $d['site'], 'email' => (string) $d['email']]))
            ->paragraph(Translator::get('If this wasn’t you, sign in and change your password, and tell your site’s administrator.'))->name('safety');
    }

    /** @param array<string, mixed> $d site, name, provider, label */
    public static function signInMethodAdded(array $d): MailMessage
    {
        return MailMessage::make(Translator::get('New sign-in method on {site}', ['site' => (string) $d['site']]))
            ->heading(Translator::get('Hi {name},', ['name' => (string) $d['name']]))
            ->paragraph(Translator::get('A {provider} account was connected to your {site} account (“{label}”). It can now be used to sign in.', ['provider' => (string) $d['provider'], 'site' => (string) $d['site'], 'label' => (string) $d['label']]))
            ->paragraph(Translator::get('If this wasn’t you, change your password and disconnect it from your profile right away.'))->name('safety');
    }

    /** @param array<string, mixed> $d site, name, count */
    public static function passwordKnown(array $d): MailMessage
    {
        return MailMessage::make(Translator::get('Someone has your {site} password', ['site' => (string) $d['site']]))
            ->heading(Translator::get('Hi {name},', ['name' => (string) $d['name']]))
            ->paragraph(Translator::get('{count, plural, one {Someone entered your {site} password correctly, then a wrong two-factor code # time in the last hour.} other {Someone entered your {site} password correctly, then a wrong two-factor code # times in the last hour.}} Two-factor authentication stopped them, but they know your password.', ['count' => (int) $d['count'], 'site' => (string) $d['site']]))
            ->paragraph(Translator::get('Sign in and change your password now. If you made these attempts yourself, you can ignore this email.'))->name('safety');
    }

    /** @param array<string, mixed> $d site */
    public static function accountDeleted(array $d): MailMessage
    {
        $site = ['site' => (string) $d['site']];

        return MailMessage::make(Translator::get('Your {site} account was deleted', $site))
            ->heading(Translator::get('Your account was deleted'))
            ->paragraph(Translator::get('Your {site} account and the data kept with it were deleted at your request. You are signed out everywhere.', $site))
            ->paragraph(Translator::get('If you did not do this, reply to this email or contact the site at once.'))->name('safety');
    }

    /**
     * @param array<string, mixed> $d subject, form, site, page ('' when unknown),
     *                                rows (list of label and value), admin_url (null without the site's address)
     */
    public static function formSubmission(array $d): MailMessage
    {
        $form = (string) $d['form'];
        $message = MailMessage::make((string) $d['subject'])
            ->heading(Translator::get('New submission: {form}', ['form' => $form]))
            ->paragraph((string) $d['page'] !== ''
                ? Translator::get('Someone sent {form} on {site}, from {page}.', ['form' => $form, 'site' => (string) $d['site'], 'page' => (string) $d['page']])
                : Translator::get('Someone sent {form} on {site}.', ['form' => $form, 'site' => (string) $d['site']]));
        $before = count($message->blocks());
        $message->rows((array) $d['rows']);
        if (count($message->blocks()) > $before) {
            $message->name('answers');
        }
        if (is_string($d['admin_url'] ?? null) && MailMessage::isLink($d['admin_url'])) {
            $message->button(Translator::get('Open it in the admin'), $d['admin_url'])->name('link');
        }

        return $message;
    }

    /** @param array<string, mixed> $d site */
    public static function testEmail(array $d): MailMessage
    {
        $site = ['site' => (string) $d['site']];

        return MailMessage::make(Translator::get('Test email from {site}', $site))
            ->heading(Translator::get('Your email settings work'))
            ->paragraph(Translator::get('This is a test email from {site}. It reached you, so the site can send email with the settings you saved.', $site))
            ->note(Translator::get('Someone sent this from Settings → Email on {site}.', $site));
    }

    private static function hours(int $hours): string
    {
        return Translator::get('{hours, plural, one {The link works once and expires in # hour.} other {The link works once and expires in # hours.}}', ['hours' => $hours]);
    }
}
