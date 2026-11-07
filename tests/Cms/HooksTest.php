<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use InvalidArgumentException;
use Kestello\Cms\Events\EntryPublished;
use Kestello\Cms\Events\UserRegistered;
use Kestello\Cms\Hooks\Hook;
use Kestello\Cms\Hooks\HookReference;
use Kestello\Cms\Hooks\HookRegistry;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Filters;
use Kestello\Tests\Auth\AuthTestCase;

/**
 * The hook reference is built from HookRegistry, so it must hold every
 * hook core uses, and nothing core does not.
 */
final class HooksTest extends AuthTestCase
{
    private const string CORE = __DIR__ . '/../../core/src';

    public function testCoreDeclaresEveryHookItUses(): void
    {
        [$filters, $events, $created] = self::scanCore();
        $hooks = $this->cms->hooks;
        $this->assertTrue($filters !== []);
        foreach ($filters as $name => $file) {
            $this->assertNotNull($hooks->get(Hook::FILTER, $name), "{$file} applies the filter \"{$name}\", which CoreHooks does not declare.");
        }
        foreach ($events as $class => $file) {
            $this->assertNotNull($hooks->get(Hook::EVENT, $class), "{$file} dispatches {$class}, which CoreHooks does not declare.");
        }

        foreach ($hooks->all() as $hook) {
            if ($hook->kind === Hook::FILTER && !str_contains($hook->name, '{')) {
                $this->assertArrayHasKey($hook->name, $filters, "The filter {$hook->name} is declared but core never applies it.");
            }
            if ($hook->kind === Hook::EVENT && $hook->group !== 'Models') {
                $this->assertArrayHasKey($hook->name, $created, "The event {$hook->name} is declared but core never creates it.");
            }
        }
    }

    public function testCoreHooksDescribeThemselves(): void
    {
        foreach ($this->cms->hooks->all() as $hook) {
            $this->assertSame('core', $hook->owner);
            $this->assertTrue($hook->description !== '', "{$hook->name} needs a description (for an event, the first paragraph of its class docblock).");
            foreach ($hook->properties as $name => $p) {
                $this->assertTrue($p['description'] !== '', "{$hook->name}::\${$name} needs an @param description on the constructor.");
            }
            $this->assertTrue(preg_match('/[\x{2013}\x{2014}]/u', $hook->description . json_encode($hook->properties)) === 0, 'the docs use no dashes');
        }
        $published = $this->cms->hooks->get(Hook::EVENT, EntryPublished::class);
        $this->assertSame(['type' => '?string', 'description' => 'Its address on the site, or null for a type without public pages.'], $published->properties['path']);
        $this->assertTrue($this->cms->hooks->get(Hook::EVENT, \Kestello\Framework\Database\Orm\Events\Saving::class)->stoppable);
        $this->assertSame('api.response.{resource}', $this->cms->hooks->get(Hook::FILTER, 'api.response.users')?->name, 'a pattern matches the names used in code');
    }

    public function testPluginsDeclareHooksUnderTheirOwnName(): void
    {
        $hooks = new HookRegistry();
        ExecutionContext::run('plugin:acme', function () use ($hooks) {
            $hooks->filter('acme.share_text', '1.2.0', 'string', 'The text of share links.', ['Entry $entry' => 'The entry being shared.'], 'Sharing');
            $hooks->event(AcmeThingHappened::class, '1.0.0', 'Things');
        });
        $this->assertSame(['plugin:acme', '1.2.0'], [$hooks->get(Hook::FILTER, 'acme.share_text')->owner, $hooks->get(Hook::FILTER, 'acme.share_text')->since]);
        $this->assertSame('Something happened to a thing.', $hooks->get(Hook::EVENT, AcmeThingHappened::class)->description);

        $refused = [
            fn() => $hooks->filter('page.head', '1.0.0', 'string', 'x'),                    // not its name
            fn() => $hooks->filter('acme.share_text', '1.0.0', 'string', 'x'),              // declared already
            fn() => $hooks->filter('acme.Bad Name', '1.0.0', 'string', 'x'),
            fn() => $hooks->filter('acme.later', 'soon', 'string', 'x'),                    // since is a version
            fn() => $hooks->event(EntryPublished::class, '1.0.0'),                           // core's event
            fn() => $hooks->event('Acme\\Missing', '1.0.0'),
        ];
        foreach ($refused as $i => $call) {
            $this->assertThrows(InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:acme', $call), "case {$i}");
        }
    }

    public function testDeprecatedHooksKeepWorkingAndAreReported(): void
    {
        $filters = new Filters();
        $events = new EventDispatcher();
        $hooks = new HookRegistry();
        $hooks->connect($filters);
        $hooks->filter('page.title', '0.1.0', 'string', 'The page title.');
        $hooks->filter('page.heading', '0.1.0', 'string', 'The old name of page.title.', deprecated: ['since' => '0.2.0', 'use' => 'page.title', 'removal' => '0.4.0']);
        ExecutionContext::run('plugin:old', fn() => $filters->add('page.heading', fn(string $t) => $t . ' (old)'));
        ExecutionContext::run('plugin:new', fn() => $filters->add('page.title', fn(string $t) => $t . ' (new)'));

        $this->assertSame('Home (old) (new)', $filters->apply('page.title', 'Home'), 'callbacks on the old name still run');
        $uses = $hooks->deprecatedUses($filters, $events);
        $this->assertSame([['plugin:old', 'page.heading']], array_map(static fn(array $u) => [$u['owner'], $u['as']], $uses));

        $md = HookReference::markdown($hooks->all(), '0.2.0');
        $this->assertStringContainsString('Deprecated in 0.2.0, removed in 0.4.0. Use `page.title` instead.', $md);
    }

    public function testDeprecatedHooksShowOnSiteHealth(): void
    {
        $this->assertSame('good', $this->health()['status']);
        ExecutionContext::run('core', fn() => $this->cms->hooks->filter('robots.legacy', '0.1.0', 'string', 'Old robots text.', deprecated: ['since' => '0.1.0', 'use' => 'robots.txt', 'removal' => '0.3.0']));
        ExecutionContext::run('plugin:seo-kit', fn() => $this->cms->filters->add('robots.legacy', fn(string $t) => $t));
        $check = $this->health();
        $this->assertSame('recommended', $check['status']);
        $this->assertStringContainsString('plugin:seo-kit: robots.legacy (removed in 0.3.0)', $check['message']);
    }

    public function testTheReferencePageAndTheCommand(): void
    {
        $md = HookReference::markdown($this->cms->hooks->all(), '0.1.0');
        foreach (['# Hook reference', '### Accounts and sign-in', '#### UserRegistered', '#### `media.scan`', '| `$entryId` | `int` | The entry. |', '`<head>`'] as $needle) {
            $this->assertStringContainsString($needle, $md);
        }
        $this->assertTrue(preg_match('/[\x{2013}\x{2014}]/u', $md) === 0);

        \Kestello\Cms\Cms::setInstance($this->cms);
        try {
            $out = fopen('php://memory', 'w+');
            $command = new \Kestello\Cms\Console\HooksListCommand();
            $command->setOutput(new \Kestello\Framework\Console\Output($out, false));
            ExecutionContext::run('plugin:typo', fn() => $this->cms->filters->add('page.haed', fn(string $h) => $h));
            $this->assertSame(0, $command->handle([], ['filters' => true]));
            rewind($out);
            $text = (string) stream_get_contents($out);
            $this->assertStringContainsString('security.csp', $text);
            $this->assertStringNotContainsString('EntryPublished', $text);
            $this->assertStringContainsString('page.haed: plugin:typo', $text, 'a typo in a filter name is pointed out');
        } finally {
            \Kestello\Cms\Cms::setInstance(null);
        }
    }

    public function testCreatingAUserIsAnnounced(): void
    {
        $seen = [];
        $this->cms->events->on(UserRegistered::class, function (UserRegistered $e) use (&$seen) {
            $seen[] = [$e->via];
        });
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $r = $this->api('POST', '/users', ['username' => 'bob', 'email' => 'bob@example.test', 'password' => 'a long enough password 42', 'role' => 'user']);
        $this->assertSame(201, $r->statusCode(), $r->content());
        $this->assertSame([['admin']], $seen);
    }

    /** @return array{status: string, message: string} */
    private function health(): array
    {
        \Kestello\Cms\Health\CoreHealthChecks::register($this->cms);
        $report = $this->cms->health->run(true, ['core.deprecated-hooks']);

        return array_values($report['checks'])[0];
    }

    /**
     * Filter names core applies and event classes it dispatches, from the source.
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>} filters applied, events dispatched with new, every class created with new
     */
    private static function scanCore(): array
    {
        $filters = $events = $created = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::CORE, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $rel = substr($file->getPathname(), strlen(self::CORE) + 1);
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $code);     // not the examples in docblocks
            preg_match_all('/filters\??->apply\(\s*\'([^\']+)\'/', $code, $m);
            foreach ($m[1] as $name) {
                $filters[$name] = $rel;
            }
            preg_match_all('/events\??->dispatch\(\s*new\s+(\\\\?[A-Za-z_][\w\\\\]*)/', $code, $m);
            preg_match_all('/\bnew\s+(\\\\?[A-Z][\w\\\\]*)/', $code, $all);
            preg_match('/^namespace\s+([\w\\\\]+);/m', $code, $ns);
            preg_match_all('/^use\s+([\w\\\\]+?)(?:\s+as\s+(\w+))?;/m', $code, $uses, PREG_SET_ORDER);
            $imports = [];
            foreach ($uses as $u) {
                $imports[$u[2] ?? '' ?: substr(strrchr('\\' . $u[1], '\\'), 1)] = $u[1];
            }
            $resolve = static function (string $class) use ($imports, $ns): string {
                if (str_starts_with($class, '\\')) {
                    return ltrim($class, '\\');
                }
                $first = explode('\\', $class)[0];

                return isset($imports[$first]) ? $imports[$first] . substr($class, strlen($first)) : ($ns[1] ?? '') . '\\' . $class;
            };
            foreach ($m[1] as $class) {
                $events[$resolve($class)] = $rel;
            }
            foreach ($all[1] as $class) {
                $created[$resolve($class)] = $rel;
            }
        }

        return [$filters, $events, $created];
    }
}

/**
 * Something happened to a thing.
 *
 * @api
 */
final readonly class AcmeThingHappened
{
    /** @param int $thingId The thing. */
    public function __construct(public int $thingId) {}
}
