<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

/** A theme's markup for emails, as ThemeEmails found it. */
final class ThemeEmail
{
    /**
     * @param string|null $id the email this file is for; null for the layout every email uses
     * @param list<string> $locked blocks the email must show
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $file,
        public readonly string $html,
        public readonly array $locked = [],
    ) {}
}
