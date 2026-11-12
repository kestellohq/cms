<?php

declare(strict_types=1);

namespace Kestello\Tests\Health;

use Kestello\Cms\Api\Endpoints\System\ReportClientError;
use Kestello\Framework\Core\ErrorHandler;
use Kestello\Framework\Core\ErrorTally;
use Kestello\Tests\Auth\AuthTestCase;

/**
 * Errors reach the site owner: what the server failed at (per plugin) and
 * what the admin hit in the browser, counted per day and shown in Site Health.
 */
final class ErrorsTodayTest extends AuthTestCase
{
    private string $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logs = $this->tempDir();
        $logs = $this->logs;
        $this->cms->container->singleton(ErrorHandler::class, static fn(): ErrorHandler => new ErrorHandler(false, $logs));
    }

    public function testTheTallyCountsPerSourceAndDay(): void
    {
        $tally = new ErrorTally($this->logs . '/errors.json');
        $day = strtotime('2026-11-12 10:00:00 UTC');
        $tally->record('plugin:ledger', "first\nline", $day);
        $tally->record('plugin:ledger', 'RuntimeException: the latest', $day + 60);
        $tally->record('admin', 'TypeError: x', $day + 120);
        $tally->record('core', 'yesterday', $day - 86400);

        $this->assertSame([
            'plugin:ledger' => ['count' => 2, 'message' => 'RuntimeException: the latest', 'at' => $day + 60],
            'admin' => ['count' => 1, 'message' => 'TypeError: x', 'at' => $day + 120],
        ], $tally->day($day), 'the most frequent first, with the last message');
        $this->assertSame(['core'], array_keys($tally->day($day - 86400)));

        for ($i = 1; $i <= ErrorTally::DAYS + 2; $i++) {
            $tally->record('core', 'old', $day - $i * 86400);
        }
        $this->assertSame([], $tally->day($day - (ErrorTally::DAYS + 1) * 86400), 'only the last week is kept');
        $this->assertSame(['plugin:ledger', 'admin'], array_keys($tally->day($day)));
    }

    public function testCountingNeverThrows(): void
    {
        $file = $this->logs . '/errors.json';
        file_put_contents($file, '{not json');
        $tally = new ErrorTally($file);
        $tally->record('core', 'after a broken file');
        $this->assertSame(1, $tally->day()['core']['count'], 'a broken file starts over');

        (new ErrorTally('/dev/null/cannot/be/made/errors.json'))->record('core', 'nowhere to write');
        $this->assertTrue(true, 'no exception');
    }

    public function testAnUncaughtErrorIsCountedAgainstItsPlugin(): void
    {
        $e = new \RuntimeException('the ledger broke');
        \Kestello\Framework\Core\ExecutionContext::run('plugin:ledger', static function () use ($e): void {
            try {
                throw $e;
            } catch (\RuntimeException $caught) {
                \Kestello\Framework\Core\ExecutionContext::blame($caught, 'plugin:ledger');
            }
        });
        $handler = new ErrorHandler(false, $this->logs);
        // what handleException() and the request's error response both do first
        (new \ReflectionMethod($handler, 'logException'))->invoke($handler, $e);
        $this->assertSame(1, $handler->tally()?->day()['plugin:ledger']['count'] ?? 0);
        $this->assertStringContainsString('[plugin:ledger] RuntimeException: the ledger broke', (string) file_get_contents($this->logs . '/' . date('Y-m-d') . '.log'));
    }

    public function testTheAdminReportsWhatItHitInTheBrowser(): void
    {
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $response = $this->api('POST', '/system/client-errors', [
            'message' => "TypeError: Cannot read properties of null\n[fake] another entry",
            'screen' => '/admin/content/page/1',
            'where' => 'render function',
            'stack' => "TypeError: ...\n    at Edit.vue:12",
        ]);
        $this->assertSame(200, $response->statusCode(), $response->content());
        $this->assertAnswerMatches(ReportClientError::class, $response);
        $today = (new ErrorTally($this->logs . '/errors.json'))->day();
        $this->assertSame(1, $today['admin']['count']);
        $this->assertSame('TypeError: Cannot read properties of null [fake] another entry (/admin/content/page/1)', $today['admin']['message'], 'one line');

        $this->assertSame(422, $this->api('POST', '/system/client-errors', ['message' => str_repeat('x', 501), 'screen' => '/admin'])->statusCode(), 'fields are length-limited');
    }

    public function testOnlySignedInAdminUsersCanReport(): void
    {
        $this->assertSame(401, $this->api('POST', '/system/client-errors', ['message' => 'x', 'screen' => '/admin'])->statusCode());
        $this->createUser(['username' => 'bob', 'email' => 'bob@example.test', 'role' => 'user']);
        $this->api('POST', '/auth/session', ['login' => 'bob', 'password' => self::PASSWORD]);
        $this->assertSame(403, $this->api('POST', '/system/client-errors', ['message' => 'x', 'screen' => '/admin'])->statusCode());
        $this->assertSame([], (new ErrorTally($this->logs . '/errors.json'))->day());
    }

    public function testSiteHealthSaysWhatFailedToday(): void
    {
        (new \Kestello\Cms\Health\HealthModule())->boot($this->cms);
        $good = $this->cms->health->run(fresh: true, only: ['core.errors'])['checks'][0];
        $this->assertSame(['good', 'No errors today.'], [$good['status'], $good['message']]);

        $tally = new ErrorTally($this->logs . '/errors.json');
        foreach (range(1, 3) as $_) {
            $tally->record('plugin:ledger', 'RuntimeException: the ledger broke');
        }
        $tally->record('admin', 'TypeError: x (/admin)');
        $check = $this->cms->health->run(fresh: true, only: ['core.errors'])['checks'][0];
        $this->assertSame('recommended', $check['status']);
        $this->assertSame('The site hit 4 errors today (the plugin ledger: 3, the admin in the browser: 1). The details show the last message from each; the full errors are in storage/logs.', $check['message']);
        $this->assertSame(['plugin:ledger' => '3× RuntimeException: the ledger broke', 'admin' => '1× TypeError: x (/admin)'], $check['details']);
    }
}
