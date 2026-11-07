<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Media\MediaException;
use Kestello\Contracts\Auth\Principal;
use Throwable;

/**
 * Everything one slice of a running import works with.
 *
 * Options:
 *   authors          WordPress login → user id here (missing: default_author)
 *   default_author   user id for posts whose author has no match
 *   media            download files (default true)
 *   redirects        add redirects from old addresses (default true)
 *   uploads_dir      a copy of wp-content/uploads on this server, read before downloading
 *
 * @internal
 */
final class Run
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly Cms $cms,
        public readonly int $importId,
        public readonly OldSite $site,
        public readonly SourceMap $map,
        public readonly ImportLinks $links,
        public readonly Principal $principal,
        public readonly array $options,
        public readonly Notes $notes,
    ) {}

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /** The user to credit for a WordPress author login. */
    public function author(string $login): ?int
    {
        $authors = (array) $this->option('authors', []);
        $id = $authors[$login] ?? null;
        if (is_int($id) && $id > 0) {
            return $id;
        }
        $default = $this->option('default_author');

        return is_int($default) && $default > 0 ? $default : $this->principal->userId;
    }

    /** A failure as a sentence for the report, with field errors spelled out. */
    public static function describe(Throwable $e): string
    {
        $errors = $e instanceof ContentException || $e instanceof MediaException ? $e->errors : [];
        $parts = [];
        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $parts[] = (is_string($field) && $field !== '' ? $field . ': ' : '') . $message;
            }
        }
        $text = $parts === [] ? $e->getMessage() : implode(' ', array_slice($parts, 0, 5));

        return mb_substr(trim($text) !== '' ? $text : $e::class, 0, 480);
    }
}
