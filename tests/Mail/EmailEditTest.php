<?php

declare(strict_types=1);

namespace Kestello\Tests\Mail;

use Kestello\Cms\Mail\EmailEdit;
use Kestello\Cms\Mail\MailBrand;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Framework\Testing\TestCase;

/** Checking an edit of a named email against it, and building the edited message. */
final class EmailEditTest extends TestCase
{
    private const array VALUES = ['site' => 'Studio Ana', 'name' => 'ana', 'link' => 'https://studio.test/reset?t=abc', 'minutes' => 60];

    private static function original(array $v = self::VALUES): MailMessage
    {
        return MailMessage::make("Reset your {$v['site']} password")
            ->heading("Hi {$v['name']},")
            ->paragraph('Choose a new password with the button below.')
            ->button('Reset my password', (string) $v['link'])->name('link')
            ->paragraph("The link expires in {$v['minutes']} minutes.")->name('expiry')
            ->paragraph('If you didn’t ask for this, ignore this email.')->name('safety');
    }

    private static function media(): \Closure
    {
        return static fn(int $id): ?array => $id === 7 ? ['url' => 'https://studio.test/media/banner.png', 'width' => 1200, 'height' => 400] : null;
    }

    /** @param array<string, mixed> $edit */
    private static function errors(array $edit): array
    {
        return EmailEdit::validate($edit, self::original(), ['link', 'safety'], self::VALUES, self::media());
    }

    public function testAnEditThatKeepsTheLockedBlocksCanBeSaved(): void
    {
        $edit = [
            'subject' => 'A new password for {site}',
            'blocks' => [
                ['type' => 'heading', 'text' => 'Hello {name}'],
                ['type' => 'image', 'media' => 7, 'alt' => 'The studio'],
                ['type' => 'original', 'name' => 'link', 'label' => 'Choose a password'],
                ['type' => 'divider'],
                ['type' => 'original', 'name' => 'safety', 'text' => 'Not you? Ignore this, {name}.'],
                ['type' => 'button', 'label' => 'Visit {site}', 'url' => 'https://studio.test/'],
                ['type' => 'note', 'text' => 'Sent by {site}.'],
            ],
        ];
        $this->assertSame([], self::errors($edit));

        $message = EmailEdit::apply($edit, self::original(), self::VALUES, self::media());
        $this->assertSame('A new password for Studio Ana', $message->subject);
        $this->assertSame(['heading', 'image', 'button', 'divider', 'paragraph', 'button', 'note'], array_column($message->blocks(), 'type'));
        $this->assertSame(['type' => 'button', 'label' => 'Choose a password', 'url' => self::VALUES['link'], 'name' => 'link'], $message->block('link'), 'the link is the one the code made, with new words');
        $this->assertSame('Not you? Ignore this, ana.', $message->block('safety')['text'] ?? null);
        $this->assertSame(['type' => 'image', 'url' => 'https://studio.test/media/banner.png', 'alt' => 'The studio', 'width' => 1200, 'height' => 400], $message->blocks()[1]);

        $html = $message->render(MailBrand::named('Studio Ana'));
        $this->assertStringContainsString('width="496" height="165"', $html, 'a wide image is drawn at the card\'s width, in proportion');
        $this->assertStringContainsString('alt="The studio"', $html);
        $this->assertStringContainsString("[The studio]\n\nChoose a password\n" . self::VALUES['link'], $message->text('Studio Ana'), 'the text part names the image');
    }

    public function testAnEmptySubjectKeepsTheEmailsOwn(): void
    {
        $edit = ['subject' => '  ', 'blocks' => [['type' => 'original', 'name' => 'link'], ['type' => 'original', 'name' => 'safety']]];
        $this->assertSame([], self::errors($edit));
        $this->assertSame('Reset your Studio Ana password', EmailEdit::apply($edit, self::original(), self::VALUES, self::media())->subject);
    }

    public function testEachMistakeIsNamedWhereItIs(): void
    {
        $errors = self::errors([
            'subject' => 'For {sitename}',
            'blocks' => [
                ['type' => 'paragraph', 'text' => ' '],
                ['type' => 'script', 'text' => 'x'],
                ['type' => 'button', 'label' => 'Go', 'url' => 'javascript:alert(1)'],
                ['type' => 'button', 'label' => 'Relative', 'url' => '/login'],
                ['type' => 'image', 'media' => 8, 'alt' => ''],
                ['type' => 'original', 'name' => 'link'],
                ['type' => 'original', 'name' => 'link'],
                ['type' => 'original', 'name' => 'expiry', 'label' => 'x'],
                ['type' => 'original', 'name' => 'gone'],
                ['type' => 'heading', 'text' => str_repeat('a', 5001)],
            ],
        ]);
        $this->assertStringContainsString('no value called {sitename}', $errors['subject'][0]);
        $this->assertStringContainsString('{site}, {name}, {link}, {minutes}', $errors['subject'][0], 'and lists the ones it has');
        $this->assertSame(['Write something here, or remove the block.'], $errors['blocks.0.text']);
        $this->assertArrayHasKey('blocks.1.type', $errors);
        $this->assertArrayHasKey('blocks.2.url', $errors, 'only http(s)');
        $this->assertArrayHasKey('blocks.3.url', $errors, 'a mail app has no page to resolve a relative link against');
        $this->assertArrayHasKey('blocks.4.media', $errors);
        $this->assertSame(['The block “link” is already in this email once.'], $errors['blocks.6.name']);
        $this->assertSame(['The words of the block “expiry” can’t be changed.'], $errors['blocks.7.label'], 'a paragraph has text, not a label');
        $this->assertArrayHasKey('blocks.8.name', $errors);
        $this->assertSame(['Keep it under 5000 characters.'], $errors['blocks.9.text']);
        $this->assertSame(['Keep the block “safety”: the email doesn’t work without it.'], $errors['blocks']);

        $this->assertSame(['blocks' => ['An email needs at least one block.']], self::errors(['subject' => '', 'blocks' => []]));
        $this->assertArrayHasKey('blocks', self::errors(['blocks' => array_fill(0, 41, ['type' => 'divider'])]));
    }

    public function testAButtonMayUseOneOfTheEmailsLinks(): void
    {
        $edit = ['blocks' => [['type' => 'button', 'label' => 'Again', 'url' => '{link}'], ['type' => 'original', 'name' => 'link'], ['type' => 'original', 'name' => 'safety']]];
        $this->assertSame([], self::errors($edit));
        $sent = EmailEdit::apply($edit, self::original(), self::VALUES, self::media());
        $this->assertSame(self::VALUES['link'], $sent->blocks()[0]['url']);

        $notALink = ['link' => 'not a link'] + self::VALUES;
        $this->assertSame(['paragraph'], array_column(EmailEdit::apply(['blocks' => [['type' => 'button', 'label' => 'Name', 'url' => '{name}'], ['type' => 'original', 'name' => 'safety']]], self::original(), $notALink, self::media())->blocks(), 'type'), 'a value that turns out not to be a link this time is left out');
    }

    public function testWhatCantBeDrawnThisTimeIsLeftOut(): void
    {
        $edit = ['blocks' => [['type' => 'image', 'media' => 7, 'alt' => ''], ['type' => 'original', 'name' => 'expiry'], ['type' => 'paragraph', 'text' => 'Hi']]];
        $gone = static fn(int $id): ?array => null;
        $original = MailMessage::make('S')->paragraph('Only this');
        $this->assertSame([['type' => 'paragraph', 'text' => 'Hi']], EmailEdit::apply($edit, $original, self::VALUES, $gone)->blocks(), 'an image that is gone, a block the email didn\'t build this time');
    }

    public function testValuesFillOnlyWhatTheEmailHas(): void
    {
        $this->assertSame(['site', 'name', 'link', 'minutes'], EmailEdit::placeholders(self::VALUES + ['rows' => [['label' => 'a', 'value' => 'b']]]), 'lists are not values');
        $this->assertSame('Hi ana, {nope} {Site} 60', EmailEdit::fill('Hi {name}, {nope} {Site} {minutes}', self::VALUES));
        $this->assertSame('<b>{name}</b>', EmailEdit::fill('<b>{name}</b>', ['name' => []]), 'only text and numbers');
    }

    public function testTheEditToStartFromShowsWhereEachValueGoes(): void
    {
        $values = EmailEdit::templateValues(self::VALUES);
        $this->assertSame(['site' => '{site}', 'name' => '{name}', 'link' => self::VALUES['link'], 'minutes' => 60], $values, 'links and numbers keep their sample');

        $built = self::original($values)->paragraph('Or open ' . self::VALUES['link'])->code('123456');
        $template = EmailEdit::template($built, self::VALUES);
        $this->assertSame('Reset your {site} password', $template['subject']);
        $this->assertSame([
            ['type' => 'heading', 'text' => 'Hi {name},'],
            ['type' => 'paragraph', 'text' => 'Choose a new password with the button below.'],
            ['type' => 'original', 'name' => 'link'],
            ['type' => 'original', 'name' => 'expiry'],
            ['type' => 'original', 'name' => 'safety'],
            ['type' => 'paragraph', 'text' => 'Or open {link}'],
        ], $template['blocks'], 'named blocks are kept, a link in the words becomes its value, unnamed code is left out');
        $this->assertSame([], EmailEdit::validate($template, self::original(), ['link', 'safety'], self::VALUES, self::media()), 'the template is a valid edit');
    }

    public function testKeepCarriesTheBlockAndItsName(): void
    {
        $message = MailMessage::make('S')->keep(['type' => 'note', 'text' => 'x', 'name' => 'small']);
        $this->assertSame(['type' => 'note', 'text' => 'x', 'name' => 'small'], $message->block('small'));
        $this->assertThrows(\InvalidArgumentException::class, static fn() => MailMessage::make('S')->keep(['type' => 'script']), 'Unknown email block type "script"');
        $this->assertTrue(MailMessage::make('S')->html('<p>x</p>')->custom());
    }
}
