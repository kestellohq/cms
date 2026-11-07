<?php

declare(strict_types=1);

namespace Kestello\Support;

/** @api */
final readonly class ProcessResult
{
    /** @param list<string> $command */
    public function __construct(
        public array $command,
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public bool $timedOut,
        public float $seconds,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }

    /** @throws \RuntimeException with the error output */
    public function throw(): self
    {
        if (!$this->ok()) {
            $why = $this->timedOut ? 'timed out' : "exited with {$this->exitCode}";
            throw new \RuntimeException("\"{$this->command[0]}\" {$why}: " . trim(substr($this->errorOutput !== '' ? $this->errorOutput : $this->output, 0, 500)));
        }

        return $this;
    }
}
