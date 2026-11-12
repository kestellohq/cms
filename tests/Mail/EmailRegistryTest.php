<?php

declare(strict_types=1);

namespace Kestello\Tests\Mail;

use Kestello\Cms\Events\MailSending;
use Kestello\Cms\Events\MailSent;
use Kestello\Cms\Mail\EmailRegistry;
use Kestello\Cms\Mail\MailBrand;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Tests\Auth\AuthTestCase;

/** Named emails: the registry, core's own, and the hooks around sending. */
final class EmailRegistryTest extends AuthTestCase
{
    /** @var list<array{string, string, string, string}> */
    private array $out = [];
    /** @var list<object> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([MailSending::class, MailSent::class] as $event) {
            $this->cms->events->on($event, function (object $e): void {
                $this->seen[] = $e;
            });
        }
    }

    private function transport(bool $ok = true): \Closure
    {
        return function (string $to, string $subject, string $html, string $text) use ($ok): bool {
            $this->out[] = [$to, $subject, $html, $text];

            return $ok;
        };
    }

    public function testEveryCoreEmailIsRegisteredAndBuildsFromItsSample(): void
    {
        $ids = array_keys($this->cms->emails->all());
        $this->assertSame([
            'auth.sign_up_link', 'auth.sign_up_existing', 'auth.password_reset', 'auth.email_change_confirm', 'auth.email_change_notice',
            'auth.sign_in_method_added', 'auth.password_known', 'auth.account_deleted', 'forms.submission', 'settings.test_email',
        ], $ids);
        foreach ($this->cms->emails->all() as $id => $email) {
            $this->assertSame('core', $email['owner'], $id);
            $this->assertTrue($email['label'] !== '' && $email['group'] !== '' && $email['description'] !== '', "{$id} says what it is");
            $message = $this->cms->emails->sample($id);
            $this->assertTrue($message->subject !== '' && $message->blocks() !== [], "{$id} builds from its sample");
            foreach ($email['locked'] as $name) {
                $this->assertNotNull($message->block($name), "{$id} has its locked block {$name}");
            }
        }
        $this->assertSame(['link', 'safety'], $this->cms->emails->get('auth.password_reset')['locked']);
        $this->assertSame('button', $this->cms->emails->sample('auth.password_reset')->block('link')['type'] ?? null, 'the link is the button');
    }

    public function testAPluginRegistersItsOwnUnderItsSlug(): void
    {
        $build = static fn(array $d): MailMessage => MailMessage::make("Order {$d['order']} shipped")->paragraph('It left today.')->button('Track it', (string) $d['url'])->name('link');
        ExecutionContext::run('plugin:shop', fn() => $this->cms->emails->register('shop.order_shipped', 'Order shipped', $build, ['order' => '1', 'url' => 'https://a.test'], 'Shop', 'When an order leaves.', ['link']));
        $this->assertSame('plugin:shop', $this->cms->emails->get('shop.order_shipped')['owner']);

        $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:shop', fn() => $this->cms->emails->register('auth.hijack', 'x', $build, [])), 'must start with "shop."');
        $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:other', fn() => $this->cms->emails->register('shop.order_shipped', 'x', $build, [])), 'must start with "other."');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->cms->emails->register('Bad Id', 'x', $build, []), 'lowercase group and name');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->cms->emails->get('auth.pasword_reset'), 'Did you mean "auth.password_reset"?');

        $this->assertTrue($this->cms->emails->send('shop.order_shipped', 'ana@example.test', ['order' => '1042', 'url' => 'https://shop.test/t/1042'], $this->transport()));
        $this->assertSame('Order 1042 shipped', $this->out[0][1]);
        $this->assertStringContainsString('href="https://shop.test/t/1042"', $this->out[0][2]);
    }

    public function testSendingRunsTheHooksInOrder(): void
    {
        $this->cms->filters->add('mail.message', static fn(MailMessage $m, string $id, string $to): MailMessage => $id === 'settings.test_email' ? $m->note("Added for {$to}.") : $m);
        $this->cms->filters->add('mail.branding', static fn(MailBrand $b, string $id): MailBrand => new MailBrand("{$b->name} ({$id})"));
        $this->assertTrue($this->cms->emails->send('settings.test_email', 'ana@example.test', ['site' => 'Bakery'], $this->transport(), 'Bakery'));

        [$to, $subject, $html, $text] = $this->out[0];
        $this->assertSame(['ana@example.test', 'Test email from Bakery'], [$to, $subject]);
        $this->assertStringContainsString('Added for ana@example.test.', $text, 'mail.message changed the blocks');
        $this->assertStringEndsWith("\n\nBakery (settings.test_email)", $text, 'mail.branding changed the look');
        $this->assertSame([MailSending::class, MailSent::class], array_map(get_class(...), $this->seen));
        $this->assertSame(['settings.test_email', 'ana@example.test', 'Test email from Bakery'], [$this->seen[1]->id, $this->seen[1]->to, $this->seen[1]->subject]);
    }

    public function testAListenerCanChangeOrStopAnEmail(): void
    {
        $this->cms->events->on(MailSending::class, static function (MailSending $e): void {
            if (str_ends_with($e->to, '@blocked.test')) {
                $e->cancel('No mail for blocked.test.');
            }
            $e->message->paragraph('A word from a listener.');
        });
        $this->assertTrue($this->cms->emails->send('settings.test_email', 'x@blocked.test', ['site' => 'Bakery'], $this->transport()), 'stopped on purpose is not a failure');
        $this->assertSame([], $this->out, 'and nothing went to the mail server');
        $this->assertSame([MailSending::class], array_map(get_class(...), $this->seen), 'no MailSent');

        $this->cms->emails->send('settings.test_email', 'ana@example.test', ['site' => 'Bakery'], $this->transport());
        $this->assertStringContainsString('A word from a listener.', $this->out[0][3]);
    }

    public function testAFailedSendIsFalseAndNotAnnounced(): void
    {
        $this->assertFalse($this->cms->emails->send('settings.test_email', 'ana@example.test', ['site' => 'Bakery'], $this->transport(false)));
        $this->assertSame([MailSending::class], array_map(get_class(...), $this->seen));
    }

    public function testLockedBlocksMustStay(): void
    {
        $this->cms->filters->add('mail.message', static fn(MailMessage $m): MailMessage => MailMessage::make($m->subject)->paragraph('Everything replaced.'));
        $this->assertThrows(\LogicException::class, fn() => $this->cms->emails->sample('auth.password_reset'), 'must have a block named "link"');
        $this->assertSame([], $this->out);
    }

    public function testBlockNamesAreChecked(): void
    {
        $this->assertThrows(\LogicException::class, fn() => MailMessage::make('x')->name('first'), 'Add a block before naming it');
        $this->assertThrows(\InvalidArgumentException::class, fn() => MailMessage::make('x')->paragraph('a')->name('Link'), 'lowercase');
        $this->assertThrows(\InvalidArgumentException::class, fn() => MailMessage::make('x')->paragraph('a')->name('a')->paragraph('b')->name('a'), 'already has a block named "a"');
        $this->assertNull(MailMessage::make('x')->paragraph('a')->block('missing'));
    }

    public function testARegistryOutsideTheSiteStillHasCoreEmails(): void
    {
        $this->assertTrue((new EmailRegistry())->has('auth.password_reset'));
    }
}
