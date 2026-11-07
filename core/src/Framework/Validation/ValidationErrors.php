<?php

declare(strict_types=1);

namespace Kestello\Framework\Validation;

/**
 * Value object holding validation errors from the session.
 * Used by the template engine to render field errors.
 */
final class ValidationErrors
{
    /**
     * @param array<string, array<string>> $errors
     */
    public function __construct(
        private readonly array $errors = [],
    ) {}

    /**
     * Check if any errors exist.
     */
    public function any(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Check if a specific field has errors.
     */
    public function has(string $field): bool
    {
        return isset($this->errors[$field]) && !empty($this->errors[$field]);
    }

    /**
     * Get the first error for a field.
     */
    public function first(string $field): string
    {
        return $this->errors[$field][0] ?? '';
    }

    /**
     * Get all errors for a field.
     *
     * @return array<string>
     */
    public function get(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Get all errors as a flat array.
     *
     * @return array<string>
     */
    public function all(): array
    {
        $flat = [];
        foreach ($this->errors as $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $flat[] = $error;
            }
        }
        return $flat;
    }

    /**
     * Get the raw errors array.
     *
     * @return array<string, array<string>>
     */
    public function toArray(): array
    {
        return $this->errors;
    }
}
