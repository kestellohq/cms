<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Media;

use Kestello\Cms\Cms;
use Kestello\Cms\Media\Media;
use Kestello\Cms\Media\MediaLibrary;
use Kestello\Cms\Media\MediaVariants;
use Kestello\Framework\Console\Command;

/**
 * Make missing image variants and remove outdated ones, e.g. after a theme
 * changed its sizes.
 */
final class MediaVariantsCommand extends Command
{
    protected string $name = 'media:variants';
    protected string $description = 'Make missing image variants and PDF first pages, delete outdated ones [--id=N] [--prune-only] [--rebuild]';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $variants = $cms->container->make(MediaVariants::class);
        if ($variants->engine() === null) {
            $this->output->error('  No image engine: install the GD or Imagick PHP extension.');

            return 1;
        }
        $library = $cms->container->make(MediaLibrary::class);
        $query = Media::query()->where(static fn($q) => $q->where('kind', 'image')->orWhere('ext', 'pdf'))->orderBy('id');
        if (isset($options['id'])) {
            $query->where('id', (int) $options['id']);
        }
        $made = $pruned = $failed = 0;
        foreach ($query->get()->all() as $media) {
            try {
                if (isset($options['rebuild'])) {
                    $variants->deleteAll($media);
                    if ($media->ext === 'pdf') {
                        $meta = $media->meta ?? [];
                        unset($meta['poster']);
                        $media->meta = $meta === [] ? null : $meta;
                        $media->save();
                    }
                }
                if ($media->ext === 'pdf' && empty($media->meta['poster']) && !isset($options['prune-only'])) {
                    $library->makePoster($media);         // PDFs from before, or a rebuild
                }
                $pruned += $variants->prune($media);
                if (!isset($options['prune-only'])) {
                    $made += $variants->generate($media);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->output->error("  #{$media->getKey()} {$media->filename}: {$e->getMessage()}");
            }
        }
        $this->output->success("  Made {$made} variant(s), removed {$pruned} outdated." . ($failed > 0 ? " {$failed} file(s) failed." : ''));

        return $failed > 0 ? 1 : 0;
    }
}
