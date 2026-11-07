<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Cms\Media\Image\GdEngine;
use Kestello\Cms\Media\Image\ImagickEngine;
use Kestello\Contracts\Media\ImageEngine;

/**
 * $cms->media: image sizes and image engines.
 *
 *   $cms->media->size('card', width: 640, height: 400, fit: 'cover');
 *   $cms->media->size('hero', width: 2560);                    // width only
 *   $cms->media->engine(new VipsEngine());                      // preferred over the core ones
 *
 * Registering an existing name replaces it (a theme can redefine "large").
 * Core sizes: thumb (320 × 320, cover), medium (960 wide), large (1920 wide).
 *
 * @api
 */
final class MediaRegistry
{
    /** @var array<string, MediaSize> */
    private array $sizes = [];

    /** @var list<ImageEngine> plugin engines first, then Imagick, then GD */
    private array $engines;

    private ?string $preferred = null;

    public function __construct()
    {
        $this->engines = [new ImagickEngine(), new GdEngine()];
    }

    /** @param list<int>|null $widths explicit responsive widths (default: steps from a quarter of $width) */
    public function size(string $name, int $width, ?int $height = null, string $fit = 'contain', ?array $widths = null, bool $public = true): MediaSize
    {
        return $this->sizes[$name] = new MediaSize($name, $width, $height, $fit, $widths, $public);
    }

    public function forget(string $name): void
    {
        unset($this->sizes[$name]);
    }

    public function get(string $name): ?MediaSize
    {
        return $this->sizes[$name] ?? null;
    }

    /** @return array<string, MediaSize> */
    public function sizes(): array
    {
        return $this->sizes;
    }

    public function engine(ImageEngine $engine): void
    {
        array_unshift($this->engines, $engine);
    }

    /** Force an engine by name ("gd", "imagick", ...); null picks automatically. */
    public function useEngine(?string $name): void
    {
        $this->preferred = $name;
    }

    /** @return list<ImageEngine> */
    public function engines(): array
    {
        return $this->engines;
    }

    /** The engine in use: the forced one when available, else the first available. */
    public function activeEngine(): ?ImageEngine
    {
        foreach ($this->engines as $engine) {
            if ($this->preferred !== null && $engine->name() === $this->preferred && $engine->available()) {
                return $engine;
            }
        }
        foreach ($this->engines as $engine) {
            if ($engine->available()) {
                return $engine;
            }
        }

        return null;
    }
}
