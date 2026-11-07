<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Cms\Cms;
use Kestello\Contracts\Queue\Job;

/** Make a file's missing variants and drop outdated ones (queued after upload and focal point changes). */
final class GenerateVariants implements Job
{
    public function __construct(public readonly int $mediaId) {}

    public function handle(): void
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $media = Media::query()->find($this->mediaId);
        if ($media instanceof Media) {
            $variants = $cms->container->make(MediaVariants::class);
            $variants->generate($media);
            $variants->prune($media);
        }
    }

    public function retries(): int
    {
        return 2;
    }
    public function timeout(): int
    {
        return 300;
    }
    public function uniqueKey(): ?string
    {
        return 'media-variants:' . $this->mediaId;
    }
}
