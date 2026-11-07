<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

use Kestello\Framework\Core\Response;
use RuntimeException;

/**
 * An RFC 9457 problem (application/problem+json). Throw it from an endpoint
 * to answer with an error; the dispatcher renders it.
 *
 *   throw ApiProblem::notFound('No user with that id.');
 *   throw ApiProblem::validation(['email' => ['Already taken.']]);
 *
 * Problem types are URNs: urn:kestello:problem:<code>.
 *
 * @api
 */
final class ApiProblem extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors field => messages
     * @param array<string, mixed>        $extra  additional members
     * @param array<string, string>       $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $problem,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $errors = [],
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail ?? $title, $status);
    }

    public static function unauthenticated(string $detail = 'Authentication is required.'): self
    {
        return new self(401, 'unauthenticated', 'Unauthenticated', $detail, headers: ['WWW-Authenticate' => 'Bearer']);
    }

    public static function invalidToken(string $detail = 'The access token is invalid or expired.'): self
    {
        return new self(401, 'invalid_token', 'Invalid token', $detail, headers: ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
    }

    public static function reauthRequired(): self
    {
        return new self(401, 'reauth_required', 'Recent authentication required', 'Confirm your password to continue.', extra: ['reauth_required' => true]);
    }

    /** Wrong login, password or code. Deliberately the same for unknown accounts. */
    public static function invalidCredentials(string $detail = 'Those credentials are not correct.'): self
    {
        return new self(401, 'invalid_credentials', 'Invalid credentials', $detail);
    }

    public static function conflict(string $detail): self
    {
        return new self(409, 'conflict', 'Conflict', $detail);
    }

    public static function forbidden(string $detail = 'You are not allowed to do this.'): self
    {
        return new self(403, 'forbidden', 'Forbidden', $detail);
    }

    public static function insufficientScope(array $scopes): self
    {
        return new self(
            403,
            'insufficient_scope',
            'Insufficient scope',
            'The token lacks a required scope.',
            extra: ['required_scopes' => array_values($scopes)],
            headers: ['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="' . implode(' ', $scopes) . '"'],
        );
    }

    public static function csrf(): self
    {
        return new self(403, 'csrf_mismatch', 'CSRF token mismatch', 'Send the X-CSRF-TOKEN header with cookie-authenticated requests.');
    }

    public static function notFound(string $detail = 'The resource was not found.'): self
    {
        return new self(404, 'not_found', 'Not found', $detail);
    }

    /** The body isn't valid JSON (or is too large): never treated as an empty body. */
    public static function malformedBody(string $detail = 'The request body is not valid JSON.', int $status = 400): self
    {
        return new self($status, 'malformed_body', $status === 413 ? 'Body too large' : 'Malformed body', $detail);
    }

    public static function methodNotAllowed(): self
    {
        return new self(405, 'method_not_allowed', 'Method not allowed');
    }

    /** @param array<string, list<string>> $errors */
    public static function validation(array $errors, string $detail = 'The request has invalid fields.'): self
    {
        return new self(422, 'validation', 'Validation failed', $detail, $errors);
    }

    public static function tooManyRequests(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'Too many requests', "Try again in {$retryAfter} seconds.", headers: ['Retry-After' => (string) max(1, $retryAfter)]);
    }

    public static function server(?string $detail = null): self
    {
        return new self(500, 'server_error', 'Server error', $detail ?? 'Something went wrong.');
    }

    public function type(): string
    {
        return 'urn:kestello:problem:' . $this->problem;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $body = ['type' => $this->type(), 'title' => $this->title, 'status' => $this->status];

        if ($this->detail !== null) {
            $body['detail'] = $this->detail;
        }
        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        return $body + $this->extra;
    }

    public function toResponse(): Response
    {
        $response = Response::json($this->toArray(), $this->status)
            ->withHeader('Content-Type', 'application/problem+json; charset=UTF-8');

        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
