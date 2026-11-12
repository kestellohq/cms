<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Http\RequestId;
use Kestello\Framework\Log\Log;
use Kestello\Framework\Log\Logger;
use Kestello\Framework\Log\LogLevel;
use Kestello\Framework\Testing\TestCase;

/** Core's one log: JSON lines with the level, the owner, the request and the context. */
final class LoggerTest extends TestCase
{
    private ?Logger $previous = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Log::logger();
    }

    protected function tearDown(): void
    {
        Log::use($this->previous);
        RequestId::end();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private static function lines(string $folder): array
    {
        $text = (string) @file_get_contents($folder . '/' . date('Y-m-d') . '.log');

        return array_map(static fn(string $l): array => json_decode($l, true, flags: JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", $text))));
    }

    public function testALineSaysWhatHappenedWhereAndInWhichRequest(): void
    {
        $line = Logger::line(LogLevel::WARNING, 'the theme could not be read', ['theme' => 'base', 'tries' => 2], 'plugin:ledger', 'req-12345678', strtotime('2026-11-12 10:00:00 UTC'));
        $this->assertSame([
            'time' => '2026-11-12T10:00:00+00:00',
            'level' => 'warning',
            'message' => 'the theme could not be read',
            'owner' => 'plugin:ledger',
            'request_id' => 'req-12345678',
            'context' => ['theme' => 'base', 'tries' => 2],
        ], json_decode($line, true));
        $this->assertStringNotContainsString("\n", Logger::line(LogLevel::INFO, "two\nlines", ['x' => "a\nb"], 'core', null, 0), 'one entry, one line');
        $this->assertArrayNotHasKey('request_id', json_decode(Logger::line(LogLevel::INFO, 'x', [], 'core', null, 0), true), 'none outside a request');
    }

    public function testSecretsAreNeverWritten(): void
    {
        $context = json_decode(Logger::line(LogLevel::ERROR, 'x', [
            'password' => 'hunter2', 'smtp_password' => 's3', 'token' => 't', 'api_key' => 'k', 'Authorization' => 'Bearer t',
            'nested' => ['client_secret' => 'c', 'name' => 'kept'], 'empty_token' => '', 'host' => 'mail.example.com',
        ], 'core', null, 0), true)['context'];
        $this->assertSame('[redacted]', $context['password']);
        $this->assertSame('[redacted]', $context['smtp_password']);
        $this->assertSame('[redacted]', $context['token']);
        $this->assertSame('[redacted]', $context['api_key']);
        $this->assertSame('[redacted]', $context['Authorization']);
        $this->assertSame(['client_secret' => '[redacted]', 'name' => 'kept'], $context['nested']);
        $this->assertSame('', $context['empty_token'], 'nothing to hide');
        $this->assertSame('mail.example.com', $context['host']);
    }

    public function testValuesBecomeSomethingJsonCanHold(): void
    {
        $e = new \RuntimeException('outer', 0, new \LogicException('inner'));
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 1]]]]]];
        $context = json_decode(Logger::line(LogLevel::ERROR, 'x', [
            'exception' => $e, 'level' => LogLevel::INFO, 'when' => new \DateTimeImmutable('2026-11-12T10:00:00+00:00'),
            'object' => new \stdClass(), 'deep' => $deep, 'bad' => "\xB1\x31",
        ], 'core', null, 0), true)['context'];
        $this->assertSame(['class', 'message', 'file', 'line', 'trace', 'previous'], array_keys($context['exception']));
        $this->assertSame('outer', $context['exception']['message']);
        $this->assertSame('LogicException: inner', $context['exception']['previous']);
        $this->assertSame('info', $context['level']);
        $this->assertSame('2026-11-12T10:00:00+00:00', $context['when']);
        $this->assertSame('[stdClass]', $context['object']);
        $this->assertSame('[array]', $context['deep']['a']['b']['c']['d'], 'nesting stops');
        $this->assertSame("\u{FFFD}1", $context['bad'], 'invalid UTF-8 never loses the line');
    }

    public function testALineForStderrFitsWhatPhpFpmPassesOn(): void
    {
        $huge = new \RuntimeException(str_repeat('m', 5000));
        $long = ['exception' => $huge, 'rows' => array_fill(0, 200, str_repeat('r', 300))];
        foreach ([$long, ['blob' => str_repeat('x', 50_000)], array_fill(0, 2000, 'many small values')] as $context) {
            $line = Logger::line(LogLevel::ERROR, str_repeat('long message ', 400), $context, 'core', 'req-12345678', 0, Logger::STDERR_LINE);
            $this->assertTrue(strlen($line) <= Logger::STDERR_LINE, strlen($line) . ' bytes');
            $this->assertTrue(is_array(json_decode($line, true)), 'still one whole JSON object');
            $this->assertSame('req-12345678', json_decode($line, true)['request_id']);
        }
        $this->assertTrue(strlen(Logger::line(LogLevel::ERROR, 'x', $long, 'core', null, 0)) > Logger::STDERR_LINE, 'files keep everything');
    }

    public function testTheLoggerWritesDailyFilesFromItsLevelUp(): void
    {
        $folder = $this->tempDir() . '/logs';
        $logger = new Logger($folder, LogLevel::WARNING);
        RequestId::begin(new \Kestello\Framework\Core\Request('GET', '/', '/', ['X-Request-Id' => 'logged-request'], [], [], [], [], [], ''));
        $logger->info('too quiet');
        $logger->warning('first');
        ExecutionContext::run('plugin:ledger', static fn() => $logger->error('second', ['n' => 1]));
        $logger->log(LogLevel::CRITICAL, 'third', [], 'theme:exhibit');

        $lines = self::lines($folder);
        $this->assertSame(['first', 'second', 'third'], array_column($lines, 'message'));
        $this->assertSame(['core', 'plugin:ledger', 'theme:exhibit'], array_column($lines, 'owner'), 'the code running, or the owner named');
        $this->assertSame(['logged-request'], array_values(array_unique(array_column($lines, 'request_id'))));
    }

    public function testTheEnvironmentChoosesWhereAndHowMuch(): void
    {
        $file = Logger::fromEnvironment('/logs', [], false);
        $this->assertSame(['/logs', LogLevel::INFO], [$file->target(), $file->level()]);
        $this->assertSame(LogLevel::DEBUG, Logger::fromEnvironment('/logs', [], true)->level(), 'debug sites log everything');
        $stderr = Logger::fromEnvironment('/logs', ['KESTELLO_LOG' => 'STDERR', 'KESTELLO_LOG_LEVEL' => 'Error'], true);
        $this->assertSame([Logger::STDERR, LogLevel::ERROR], [$stderr->target(), $stderr->level()]);
        $this->assertSame(LogLevel::INFO, Logger::fromEnvironment('/logs', ['KESTELLO_LOG_LEVEL' => 'loud'], false)->level(), 'an unknown level is ignored');
        $this->assertSame('/logs', Logger::fromEnvironment('/logs', ['KESTELLO_LOG' => 'syslog'], false)->target(), 'so is an unknown target');
    }

    public function testLogWritesThroughTheSitesLogger(): void
    {
        $folder = $this->tempDir() . '/logs';
        Log::use(new Logger($folder));
        Log::warning('through the facade', ['k' => 'v']);
        ExecutionContext::run('plugin:ledger', static fn() => Log::error('from a plugin'));
        Log::write(LogLevel::ERROR, 'blamed', [], 'plugin:other');
        $this->assertSame([['through the facade', 'core'], ['from a plugin', 'plugin:ledger'], ['blamed', 'plugin:other']], array_map(static fn(array $l): array => [$l['message'], $l['owner']], self::lines($folder)));
    }

    public function testOldLogFilesAreDeleted(): void
    {
        $folder = $this->tempDir();
        $now = strtotime('2026-11-12 12:00:00 UTC');
        foreach (['2026-10-01.log', '2026-10-28.log', '2026-10-29.log', '2026-11-12.log', 'php_errors.log', 'errors.json', 'notes-2020-01-01.log'] as $name) {
            touch("{$folder}/{$name}");
        }
        $this->assertSame(2, Logger::prune($folder, 14, $now));
        $left = array_map('basename', glob("{$folder}/*") ?: []);
        sort($left);
        $this->assertSame(['2026-10-29.log', '2026-11-12.log', 'errors.json', 'notes-2020-01-01.log', 'php_errors.log'], $left, 'only dated files older than the cutoff');
        $this->assertSame(0, Logger::prune($folder . '/missing', 14, $now));
    }

    /** A class-wide check: core logs through Log, so every line has its level, owner and request. */
    public function testCoreNeverWritesToPhpsErrorLogDirectly(): void
    {
        $core = dirname(__DIR__, 2) . '/core/src';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($core, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php') || str_starts_with($path, $core . '/Framework/Log/')) {
                continue;
            }
            foreach (\PhpToken::tokenize((string) file_get_contents($path)) as $i => $token) {
                if ($token->is(T_STRING) && strtolower($token->text) === 'error_log') {
                    $found[] = substr($path, strlen($core) + 1) . ':' . $token->line;
                }
            }
        }
        $this->assertSame([], $found, 'use Log::warning() / Log::error() instead of error_log()');
    }
}
