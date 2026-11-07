<?php

declare(strict_types=1);

namespace Kestello\Framework\Http\Client;

use Kestello\Support\Json;

/** @api */
final readonly class HttpResponse
{
    /** @param array<string, list<string>> $headers lowercase names */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $url = '',
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true) && $this->header('location') !== null;
    }

    /** Decoded JSON body. @throws HttpException when it isn't JSON */
    public function json(): mixed
    {
        try {
            return Json::decode($this->body);
        } catch (\JsonException $e) {
            throw new HttpException("Expected JSON from {$this->url} (HTTP {$this->status}).", $this, $e);
        }
    }

    /** @throws HttpException unless 2xx */
    public function throw(): self
    {
        if (!$this->ok()) {
            throw new HttpException("HTTP {$this->status} from {$this->url}.", $this);
        }

        return $this;
    }
}
