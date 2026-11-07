<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

/**
 * What a conversion could not carry over exactly, counted by kind, for the
 * import report. Keys:
 *   block:<name>        a block Kestello does not have, kept as its HTML
 *   dropped:<name>      a block that only lists or shows other things (latest posts, search, …), left out
 *   shortcode:<name>    a shortcode left as text
 *   embed:<host>        an embed Kestello cannot show, kept as a link
 *   media-missing       an image or file that was not imported, kept pointing at the old address
 *   html                custom HTML, kept without scripts, styles and frames
 *   frame:<host>        an <iframe> that is not YouTube or Vimeo, left out
 *   removed:<tag>       scripts, styles, forms and other elements rich text never keeps
 *
 * @internal
 */
final class Notes
{
    /** @var array<string, int> */
    private array $counts = [];

    public function add(string $key, int $count = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $count;
    }

    /**
     * @param array<string, int> $counts
     */
    public function addAll(array $counts): void
    {
        foreach ($counts as $key => $count) {
            $this->add($key, $count);
        }
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        ksort($this->counts);

        return $this->counts;
    }
}
