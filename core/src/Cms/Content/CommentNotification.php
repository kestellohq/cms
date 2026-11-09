<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

/** An editorial comment on an entry you wrote, are assigned to, or commented on, or one that names you. */
final class CommentNotification extends ReviewNotification
{
    public function type(): string
    {
        return 'content-comment';
    }
}
