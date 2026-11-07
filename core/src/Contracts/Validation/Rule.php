<?php

declare(strict_types=1);

namespace Kestello\Contracts\Validation;

use Closure;

/**
 * A validation rule object:
 *
 *   final class Isbn implements Rule {
 *       public function validate(string $attribute, mixed $value, Closure $fail, array $data): void {
 *           if (!self::valid($value)) { $fail('The {attribute} is not a valid ISBN.'); }
 *       }
 *   }
 *   Validator::make($data, ['isbn' => ['required', new Isbn()]]);
 *
 * $fail() takes a message ({attribute} is replaced, translated in the
 * rule owner's domain when $domain is given).
 *
 * @api
 */
interface Rule
{
    /**
     * @param Closure(string $message, array<string, mixed> $params = [], string $domain = 'core'): void $fail
     * @param array<string, mixed> $data all input
     */
    public function validate(string $attribute, mixed $value, Closure $fail, array $data): void;
}
