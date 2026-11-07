<?php

declare(strict_types=1);

namespace Kestello\Framework\Validation;

use Kestello\Framework\Core\Application;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Session\Session;

/**
 * Base form request class for request validation.
 *
 * Extend this class in app/Requests/ and define rules(), and optionally
 * authorize(), messages(), and aliases(). The router/controller will
 * call validate() which runs the validator, flashes old input and errors
 * to the session on failure.
 */
abstract class FormRequest
{
    protected Request $request;
    protected Validator $validator;
    private bool $validated = false;

    /**
     * Define the validation rules.
     *
     * @return array<string, string> Field => pipe-separated rules.
     */
    abstract public function rules(): array;

    /**
     * Determine if the user is authorized to make this request.
     * Override to add authorization logic.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Custom error messages. Override to customize.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Custom field display names. Override to customize.
     *
     * @return array<string, string>
     */
    public function aliases(): array
    {
        return [];
    }

    /**
     * Create from the current HTTP request.
     */
    public static function fromRequest(Request $request): static
    {
        $instance = new static();
        $instance->request = $request;
        return $instance;
    }

    /**
     * Run validation. Returns true if passed, false if failed.
     * On failure, flashes errors and old input to the session.
     */
    public function validate(): bool
    {
        $this->validated = true;

        $data = array_merge($this->request->query, $this->request->post, $this->request->files);

        $this->validator = Validator::make($data, $this->rules(), $this->messages());

        $aliases = $this->aliases();
        if (!empty($aliases)) {
            $this->validator->setAliases($aliases);
        }

        if ($this->validator->fails()) {
            $this->flashErrorsAndInput(array_diff_key($data, $this->request->files)); // uploads are never flashed
            return false;
        }

        return true;
    }

    /**
     * Check authorization. Returns false if not authorized.
     */
    public function isAuthorized(): bool
    {
        return $this->authorize();
    }

    /**
     * Get the validator instance (after validate() has been called).
     */
    public function validator(): Validator
    {
        return $this->validator;
    }

    /**
     * Get all errors.
     *
     * @return array<string, array<string>>
     */
    public function errors(): array
    {
        return $this->validator->errors();
    }

    /**
     * Get validated data.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validator->validated();
    }

    /**
     * Get a single validated value.
     */
    public function get(string $field, mixed $default = null): mixed
    {
        return $this->validator->get($field, $default);
    }

    /**
     * Get the underlying HTTP request.
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Flash validation errors and old input to session.
     */
    private function flashErrorsAndInput(array $data): void
    {
        $app = Application::getInstance();
        if (!$app->container()->has(Session::class)) {
            return;
        }

        $session = $app->container()->make(Session::class);

        // Flash errors
        $session->flash('_errors', $this->validator->errors());

        // Flash old input (exclude sensitive fields)
        $sensitive = ['password', 'password_confirm', 'password_confirmation', 'secret', 'token', 'api_token'];
        $old = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $sensitive, true) && is_string($value)) {
                $old[$key] = $value;
            }
        }
        $session->flash('_old_input', $old);
    }
}
