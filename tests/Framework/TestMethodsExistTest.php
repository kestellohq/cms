<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Testing\TestCase;
use Kestello\Framework\Testing\TestRunner;

/**
 * Kestello's TestCase is its own, not PHPUnit's. A PHPUnit habit
 * (markTestSkipped(), expectException()) fails only when that line runs,
 * which for a test that needs MySQL is never on a machine without it. This
 * finds every method a test calls on itself that its class does not have.
 */
final class TestMethodsExistTest extends TestCase
{
    /**
     * The methods code calls on $this: real variables only (not in strings or
     * comments), and not inside an anonymous class, whose $this is another object.
     *
     * @return list<string>
     */
    public static function callsOnThis(string $code): array
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize($code), static fn(\PhpToken $t): bool => !$t->isIgnorable()));
        $methods = [];
        $depth = 0;
        $anonymous = [];    // brace depths where an anonymous class body starts
        $pendingClass = false;
        foreach ($tokens as $i => $t) {
            if ($t->is(T_NEW) && ($tokens[$i + 1] ?? null)?->is(T_CLASS)) {
                $pendingClass = true;
            } elseif ($t->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($pendingClass && $t->is('{')) {
                    $anonymous[] = $depth;
                    $pendingClass = false;
                }
            } elseif ($t->is('}')) {
                if ($anonymous !== [] && end($anonymous) === $depth) {
                    array_pop($anonymous);
                }
                $depth--;
            } elseif ($anonymous === [] && $t->is(T_VARIABLE) && $t->text === '$this'
                && ($tokens[$i + 1] ?? null)?->is(T_OBJECT_OPERATOR)
                && ($tokens[$i + 2] ?? null)?->is(T_STRING)
                && ($tokens[$i + 3] ?? null)?->is('(')) {
                $methods[] = $tokens[$i + 2]->text;
            }
        }

        return array_values(array_unique($methods));
    }

    public function testTheScanReadsOnlyRealCalls(): void
    {
        $code = <<<'PHP'
            <?php
            class T {
                /** $this->inComment() */
                function a() {
                    $this->real();
                    $s = "\$this->inString()";
                    $o = new class { function b() { $this->onTheAnonymousClass(); } };
                    $this->afterIt($o->{$x}());
                    $this->property->notOnThis();
                }
            }
            PHP;
        $this->assertSame(['real', 'afterIt'], self::callsOnThis($code));
    }

    public function testEveryMethodATestCallsOnItselfExists(): void
    {
        $root = dirname(__DIR__);
        foreach (TestRunner::testFiles($root) as $file) {
            require_once $file;
        }
        $classes = [];
        foreach (get_declared_classes() as $class) {
            $file = (string) (new \ReflectionClass($class))->getFileName();
            if (str_starts_with($file, $root . '/') && str_ends_with($file, 'Test.php')) {
                $classes[$file][] = $class;
            }
        }

        $missing = [];
        foreach ($classes as $file => $declared) {
            foreach (self::callsOnThis((string) file_get_contents($file)) as $method) {
                if (array_filter($declared, static fn(string $c): bool => method_exists($c, $method)) === []) {
                    $missing[] = substr($file, strlen($root) + 1) . ": \$this->{$method}()";
                }
            }
        }
        $this->assertSame([], $missing, "Not methods of these tests (Kestello's TestCase is not PHPUnit's):\n" . implode("\n", $missing));
    }
}
