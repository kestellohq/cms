<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use InvalidArgumentException;
use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\Checklist;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Media\MediaModule;
use Kestello\Framework\Database\Model;
use Kestello\Tests\Auth\AuthTestCase;

/** Editorial checklists: what an entry must or should have before it is published. */
final class ChecklistTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        foreach ([new ContentModule(), new MediaModule(), new BlocksModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        $this->cms->content->taxonomy('topic')->label('Topic', 'Topics')->for('story');
        $this->cms->content->type('story')->label('Story', 'Stories')->excerpt()->fields([Field::image('cover'), Field::richText('body')])->checklist([
            ['check' => 'excerpt', 'level' => 'required'],
            ['check' => 'terms', 'taxonomy' => 'topic', 'level' => 'required'],
            ['check' => 'words', 'min' => 5],
            ['check' => 'featured_image'],
            ['check' => 'acme.signed', 'level' => 'required'],
        ]);
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    protected function tearDown(): void
    {
        Model::resetRegistry();
        parent::tearDown();
    }

    public function testItemsAreCheckedWhenDeclared(): void
    {
        $this->assertSame(['check' => 'excerpt', 'level' => 'recommended'], Checklist::item(['check' => 'excerpt']), 'recommended unless it says required');
        $this->assertSame(['check' => 'words', 'level' => 'required', 'min' => 300], Checklist::item(['check' => 'words', 'level' => 'required', 'min' => 300, 'extra' => 1]));
        foreach ([['check' => 'nope'], ['check' => 'excerpt', 'level' => 'must'], ['check' => 'terms'], ['check' => 'words', 'min' => 0], ['check' => 'Acme.X'], []] as $bad) {
            try {
                Checklist::item($bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        try {
            $this->cms->content->checks->define('signed', 'Signed', static fn(): bool => true);
            $this->fail('a plugin check is named after its plugin');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('<plugin>.<check>', $e->getMessage());
        }
    }

    public function testPublishingWaitsForTheRequiredItems(): void
    {
        $r = $this->api('POST', '/content/story/entries', ['title' => 'Rye', 'status' => 'published']);
        $this->assertSame(422, $r->statusCode(), 'a new entry is checked too');
        $this->assertStringContainsString('Before publishing: Has an excerpt.', $r->content());

        $story = $this->json($this->api('POST', '/content/story/entries', ['title' => 'Rye']))['data'];
        $checks = $this->json($this->api('POST', '/content/story/checklist', ['entry' => $story['id']]))['data'];
        $this->assertSame([
            ['excerpt', 'required', 'Has an excerpt', false],
            ['terms', 'required', 'Has at least one of the topics', false],
            ['words', 'recommended', 'At least 5 words', false],
            ['featured_image', 'recommended', 'Has a featured image', false],
            ['acme.signed', 'required', 'acme.signed (not available: is its plugin active?)', null],
        ], array_map(static fn(array $c) => [$c['check'], $c['level'], $c['label'], $c['passed']], $checks));

        $unsaved = $this->json($this->api('POST', '/content/story/checklist', [
            'entry' => $story['id'], 'excerpt' => 'Sour and dark.', 'terms' => ['topic' => ['Bread']], 'data' => ['body' => '<p>One two three four five.</p>'],
        ]))['data'];
        $this->assertSame([true, true, true, false, null], array_column($unsaved, 'passed'), 'the editor checks what is typed, before saving');

        $r = $this->api('PATCH', "/content/story/entries/{$story['id']}", ['status' => 'published']);
        $this->assertSame(422, $r->statusCode());
        $this->assertStringContainsString('Has at least one of the topics', $r->content());
        $this->assertStringNotContainsString('acme.signed', $r->content(), 'an unavailable check never blocks');
        $this->assertStringNotContainsString('words', $r->content(), 'recommended items never block');
        $this->assertSame(200, $this->api('PATCH', "/content/story/entries/{$story['id']}", ['status' => 'pending'])->statusCode(), 'a submission is not held back');

        $r = $this->api('PATCH', "/content/story/entries/{$story['id']}", ['excerpt' => 'Sour and dark.', 'terms' => ['topic' => ['Bread']], 'status' => 'published']);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame(200, $this->api('PATCH', "/content/story/entries/{$story['id']}", ['excerpt' => ''])->statusCode(), 'once public, it is not held back');

        // later saves read the terms already saved
        $this->api('PATCH', "/content/story/entries/{$story['id']}", ['status' => 'draft', 'excerpt' => 'Back.']);
        $this->assertSame(200, $this->api('PATCH', "/content/story/entries/{$story['id']}", ['status' => 'published'])->statusCode());
    }

    public function testPluginsAddTheirOwnChecks(): void
    {
        $this->cms->content->checks->define('acme.signed', 'Ends with a signature', static fn(array $entry): bool => str_ends_with(trim(strip_tags((string) ($entry['data']['body'] ?? ''))), '— Ana'));
        $this->assertContains('acme.signed', $this->cms->content->checks->names());
        $type = $this->cms->content->get('story');
        $run = $this->cms->content->checks->run($type, ['excerpt' => 'x', 'terms' => ['topic' => 1], 'data' => ['body' => '<p>Hi — Ana</p>']]);
        $this->assertSame(['Ends with a signature', true], [$run[4]['label'], $run[4]['passed']]);
        $this->assertSame([], $this->cms->content->checks->blocking($type, ['excerpt' => 'x', 'terms' => ['topic' => 1], 'data' => ['body' => '<p>Hi — Ana</p>']]));
        $this->assertSame(['Ends with a signature'], $this->cms->content->checks->blocking($type, ['excerpt' => 'x', 'terms' => ['topic' => 1], 'data' => ['body' => '<p>Hi</p>']]));
    }
}
