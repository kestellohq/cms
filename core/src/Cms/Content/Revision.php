<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Cms\Models\User;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\Relations\BelongsTo;

/** A snapshot of an entry's content. */
final class Revision extends Model
{
    protected string $table = 'revisions';
    protected bool $timestamps = false;
    protected array $casts = ['snapshot' => 'array', 'entry_id' => 'int', 'author_id' => 'int', 'created_at' => 'datetime'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'entry_id');
    }
}
