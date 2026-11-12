<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Testing\TestCase;

/**
 * Passwords, secrets and keys never reach a stack trace: PHP replaces a
 * #[\SensitiveParameter] argument with a SensitiveParameterValue in traces,
 * error logs and var_dump. This fails on any parameter in core whose name
 * says it holds a secret but lacks the attribute. Parameters named more
 * loosely ($token, $code) are marked by hand where they hold one.
 */
final class SensitiveParametersTest extends TestCase
{
    private const string SECRET_NAMES = 'password|newPassword|currentPassword|secret|clientSecret|plaintext|passphrase|privateKey|appKey|previousKeys|recoveryCode|plainToken';

    /** @return list<string> "file:line function($name)" for each secret parameter without the attribute */
    public static function unmarked(string $source, string $file = ''): array
    {
        $found = [];
        preg_match_all('/function\s+(\w+)\s*\(([^)]*)\)/s', $source, $functions, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($functions as $f) {
            preg_match_all('/((?:#\[[^\]]*\]\s*)*)(?:(?:public|private|protected|readonly)\s+)*(?:[\w\\\\?|]+\s+)?&?(?:\.\.\.)?\$(' . self::SECRET_NAMES . ')\b/', $f[2][0], $params, PREG_SET_ORDER);
            foreach ($params as $p) {
                if (!str_contains($p[1], 'SensitiveParameter')) {
                    $found[] = $file . ':' . (substr_count($source, "\n", 0, $f[0][1]) + 1) . " {$f[1][0]}(\${$p[2]})";
                }
            }
        }

        return $found;
    }

    public function testTheCheckFindsUnmarkedSecrets(): void
    {
        $code = <<<'PHP'
            <?php
            function a(string $password, string $name) {}
            function b(#[\SensitiveParameter] string $password) {}
            function c(
                #[\SensitiveParameter]
                private readonly string $secret,
                string $plaintext = '',
            ) {}
            function d(string $passwordHash, string $token) {}
            PHP;
        $this->assertSame([':2 a($password)', ':4 c($plaintext)'], self::unmarked($code));
    }

    public function testNoSecretInCoreCanReachAStackTrace(): void
    {
        $root = dirname(__DIR__, 2) . '/core/src';
        $problems = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                array_push($problems, ...self::unmarked((string) file_get_contents($file->getPathname()), substr($file->getPathname(), strlen($root) + 1)));
            }
        }
        $this->assertSame([], $problems, 'Add #[\SensitiveParameter] to these parameters');
    }

    public function testAMarkedArgumentIsHiddenInTraces(): void
    {
        $fail = static function (#[\SensitiveParameter] string $password): never {
            throw new \RuntimeException('no');
        };
        try {
            $fail('hunter2-correct-horse');
        } catch (\RuntimeException $e) {
            $this->assertFalse(str_contains(print_r($e->getTrace(), true), 'hunter2'), 'the value is not in the trace');
            $this->assertInstanceOf(\SensitiveParameterValue::class, $e->getTrace()[0]['args'][0] ?? new \SensitiveParameterValue(''));
        }
    }
}
