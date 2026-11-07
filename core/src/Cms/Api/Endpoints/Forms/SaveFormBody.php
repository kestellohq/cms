<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\Schema;

/** @internal The body of creating and changing a form. */
final class SaveFormBody
{
    public static function schema(): Schema
    {
        return Schema::object([
            'title' => Schema::string()->max(200),
            'status' => Schema::enum(['open', 'closed'])->default('open'),
            'definition' => Schema::any(),
        ]);
    }
}
