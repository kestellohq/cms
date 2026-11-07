<?php

declare(strict_types=1);

namespace Kestello\Support\I18n;

/**
 * Evaluates a gettext Plural-Forms expression ("n%10==1 && n%100!=11 ? 0 :
 * …") with a tiny recursive-descent parser: n, integers, ( ), ! * / % + -
 * < <= > >= == != && || and ?:. Nothing is ever eval()'d.
 *
 * @internal
 */
final class PluralRule
{
    /** @var list<string> */
    private array $tokens;
    private int $pos = 0;
    private int $n = 0;

    public function __construct(private readonly string $expression)
    {
        if (preg_match_all('/\s*(\d+|n|&&|\|\||==|!=|<=|>=|[<>!?:()%*\/+-])/A', $expression, $m) === false
            || implode('', $m[1]) !== (string) preg_replace('/\s+/', '', $expression)) {
            throw new \InvalidArgumentException("Unsupported plural expression \"{$expression}\".");
        }
        $this->tokens = $m[1];
        $this->index(1); // validate syntax once
    }

    public function index(int $n): int
    {
        $this->n = abs($n);
        $this->pos = 0;
        $value = $this->ternary();
        if ($this->pos !== count($this->tokens)) {
            throw new \InvalidArgumentException("Unsupported plural expression \"{$this->expression}\".");
        }

        return max(0, $value);
    }

    private function ternary(): int
    {
        $cond = $this->binary(0);
        if ($this->accept('?')) {
            $a = $this->ternary();
            $this->expect(':');
            $b = $this->ternary();
            return $cond !== 0 ? $a : $b;
        }

        return $cond;
    }

    private const array LEVELS = [['||'], ['&&'], ['==', '!='], ['<', '<=', '>', '>='], ['+', '-'], ['*', '/', '%']];

    private function binary(int $level): int
    {
        if ($level === count(self::LEVELS)) {
            return $this->unary();
        }
        $left = $this->binary($level + 1);
        while (in_array($this->tokens[$this->pos] ?? null, self::LEVELS[$level], true)) {
            $op = $this->tokens[$this->pos++];
            $right = $this->binary($level + 1);
            $left = match ($op) {
                '||' => (int) ($left || $right), '&&' => (int) ($left && $right),
                '==' => (int) ($left === $right), '!=' => (int) ($left !== $right),
                '<' => (int) ($left < $right), '<=' => (int) ($left <= $right), '>' => (int) ($left > $right), '>=' => (int) ($left >= $right),
                '+' => $left + $right, '-' => $left - $right, '*' => $left * $right,
                '/' => $right === 0 ? 0 : intdiv($left, $right), '%' => $right === 0 ? 0 : $left % $right,
            };
        }

        return $left;
    }

    private function unary(): int
    {
        if ($this->accept('!')) {
            return (int) ($this->unary() === 0);
        }
        if ($this->accept('(')) {
            $v = $this->ternary();
            $this->expect(')');
            return $v;
        }
        $t = $this->tokens[$this->pos++] ?? throw new \InvalidArgumentException("Unsupported plural expression \"{$this->expression}\".");
        if ($t === 'n') {
            return $this->n;
        }
        if (ctype_digit($t)) {
            return (int) $t;
        }

        throw new \InvalidArgumentException("Unsupported plural expression \"{$this->expression}\".");
    }

    private function accept(string $t): bool
    {
        if (($this->tokens[$this->pos] ?? null) === $t) {
            $this->pos++;
            return true;
        }

        return false;
    }

    private function expect(string $t): void
    {
        if (!$this->accept($t)) {
            throw new \InvalidArgumentException("Unsupported plural expression \"{$this->expression}\".");
        }
    }
}
