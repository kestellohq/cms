<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\Api\ApiRegistry;
use Kestello\Cms\Auth\AuthRegistry;
use Kestello\Cms\Cms;
use Kestello\Cms\Notifications\SendNotificationJob;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Framework\Core\Config;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Filters;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Framework\Testing\TestCase;
use Kestello\Tests\Support\TestCms;

/** The site the tests use is put together by the Kernel's own factory. */
final class CmsFactoryTest extends TestCase
{
    public function testTheCmsIsWiredAsTheKernelWiresIt(): void
    {
        $cms = TestCms::make($this->tempDir());
        $c = $cms->container;

        $this->assertSame(['session', 'token'], array_keys($cms->auth->guards()), 'people sign in with a session or a token');
        foreach ([Cms::class => $cms, EventDispatcher::class => $cms->events, Filters::class => $cms->filters, AuthRegistry::class => $cms->auth, ApiRegistry::class => $cms->api, JobQueue::class => $cms->queue, Config::class => $cms->config] as $id => $part) {
            $this->assertSame($part, $c->make($id), "{$id} from the container is the Cms's own");
        }
        $this->assertTrue($c->make(RateLimiter::class) instanceof RateLimiter);
        $this->assertSame('core', $cms->queue->registered()[SendNotificationJob::class] ?? null, 'notifications can be queued');
    }

    public function testFiltersAreStrictInDebugAsOnARealSite(): void
    {
        foreach ([true, false] as $debug) {
            $filters = TestCms::make($this->tempDir(), debug: $debug)->filters;
            $strict = (new \ReflectionProperty($filters, 'strict'))->getValue($filters);
            $this->assertSame($debug, $strict);
        }
    }

    public function testNothingElseBuildsTheCmsByHand(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        foreach (['core/src', 'tests'] as $folder) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$folder}", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = (string) $file;
                if (str_ends_with($path, '.php') && !str_contains($path, '/tests/browser/') && preg_match('/new\s+(?:\\\\?Kestello\\\\Cms\\\\)?Cms\s*\(/', (string) file_get_contents($path)) === 1) {
                    $found[] = substr($path, strlen($root) + 1);
                }
            }
        }
        $this->assertSame(['core/src/Cms/CmsFactory.php'], $found, 'build it with CmsFactory::make (tests: TestCms::make)');
    }
}
