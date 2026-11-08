<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Media\Image\Metadata;
use Kestello\Cms\Media\Media;
use Kestello\Cms\Media\MediaModule;
use Kestello\Cms\Media\MediaSize;
use Kestello\Cms\Media\MediaVariants;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Settings\Settings;
use Kestello\Tests\Auth\AuthTestCase;

/** The image pipeline: engines, orientation and metadata, sizes, signed variants. */
final class ImageTest extends AuthTestCase
{
    private const string MARK = 'KESTELLO-GPS-MARK';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        Model::resetRegistry();
        $this->root = $this->tempDir();
        $this->cms->storage->configure($this->root, []);
        foreach ([new \Kestello\Cms\Storage\StorageModule(), new ContentModule(), new MediaModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    protected function tearDown(): void
    {
        Model::resetRegistry();
        foreach (['media_max_edge', 'media_avif', 'media_allow_svg'] as $key) {
            Settings::getInstance()->delete($key);
        }
        $this->cms->media->useEngine(null);
        parent::tearDown();
    }

    /** @return list<string> engines this machine has */
    private function engines(): array
    {
        return array_values(array_filter(['gd', 'imagick'], fn(string $n) => array_filter($this->cms->media->engines(), static fn($e) => $e->name() === $n && $e->available()) !== []));
    }

    /** @return array<string, mixed> */
    private function upload(string $filename, string $bytes): array
    {
        $up = $this->json($this->api('POST', '/media/uploads', ['filename' => $filename, 'size' => strlen($bytes)]))['data'];
        foreach (str_split($bytes, $up['chunk_size']) as $i => $chunk) {
            $this->assertSame(200, $this->api('PUT', "/media/uploads/{$up['id']}/chunks/{$i}", raw: $chunk)->statusCode());
        }
        $r = $this->api('POST', "/media/uploads/{$up['id']}/complete");
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    private function stored(int $id): string
    {
        return (string) file_get_contents($this->root . '/media/' . $this->db->table('media')->where('id', $id)->value('path'));
    }

    private function web(string $url): Response
    {
        $this->nextRequest();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $this->cms->routes->dispatch(new Request('GET', $url, $path, [], $query, [], [], [], ['REMOTE_ADDR' => '203.0.113.9'], ''));
    }

    /** Left half red, right half blue; EXIF with a camera "Make" marker, the orientation, and a comment. */
    private static function photo(int $w, int $h, int $orientation = 1): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);
        $jpeg = (string) ob_get_clean();
        $make = self::MARK . "\0";
        $tiff = "II*\0" . pack('V', 8) . pack('v', 2)
            . pack('vvVV', 0x010F, 2, strlen($make), 8 + 2 + 2 * 12 + 4)
            . pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
            . pack('V', 0) . $make;
        $app1 = "Exif\0\0" . $tiff;
        $com = 'secret comment';

        return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . "\xFF\xFE" . pack('n', strlen($com) + 2) . $com . substr($jpeg, 2);
    }

    private static function png(int $w, int $h, bool $alpha = false): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, $alpha ? imagecolorallocatealpha($img, 0, 128, 0, 100) : imagecolorallocate($img, 0, 128, 0));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** @return array{0: int, 1: int, 2: int} rgb at x, y */
    private static function rgb(string $bytes, int $x, int $y): array
    {
        $img = imagecreatefromstring($bytes);
        $c = imagecolorat($img, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    public function testMetadataIsReadAndRemovedLosslessly(): void
    {
        $photo = self::photo(40, 20, 6);
        $this->assertSame(6, Metadata::jpegOrientation($photo));
        $this->assertSame(1, Metadata::jpegOrientation(self::photo(40, 20)));
        $this->assertSame(1, Metadata::jpegOrientation('not a jpeg'));

        $clean = Metadata::stripJpeg($photo);
        $this->assertNotNull($clean);
        $this->assertStringContainsString(self::MARK, $photo);
        $this->assertNotContains(self::MARK, $clean);
        $this->assertNotContains('secret comment', $clean);
        $this->assertSame([40, 20], array_slice(getimagesizefromstring($clean), 0, 2), 'pixels untouched');
        $this->assertNull(Metadata::stripJpeg("\xFF\xD8\xFF\xE1\x00"), 'malformed input is refused, not guessed at');

        $text = 'Location' . "\0" . self::MARK;
        $png = self::png(4, 4);
        $tagged = substr($png, 0, 33) . pack('N', strlen($text)) . 'tEXt' . $text . pack('N', crc32('tEXt' . $text)) . substr($png, 33);
        $this->assertTrue(@imagecreatefromstring($tagged) !== false);
        $cleanPng = Metadata::stripPng($tagged);
        $this->assertSame($png, $cleanPng);

        ob_start();
        imagewebp(imagecreatetruecolor(8, 8));
        $webp = (string) ob_get_clean();
        $exif = self::MARK;
        $body = substr($webp, 12) . 'EXIF' . pack('V', strlen($exif)) . $exif . (strlen($exif) % 2 ? "\0" : '');
        $taggedWebp = 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body;
        $cleanWebp = Metadata::stripWebp($taggedWebp);
        $this->assertSame($webp, $cleanWebp);

        $this->assertFalse(Metadata::animated(self::png(4, 4), 'image/png'));
        if (extension_loaded('imagick')) {
            $gif = new \Imagick();
            foreach (['red', 'blue'] as $color) {
                $frame = new \Imagick();
                $frame->newImage(8, 8, new \ImagickPixel($color), 'gif');
                $gif->addImage($frame);
            }
            $this->assertTrue(Metadata::animated($gif->getImagesBlob(), 'image/gif'));
        }
    }

    public function testEnginesTurnCropResizeAndEncode(): void
    {
        $path = $this->tempDir() . '/photo.jpg';
        file_put_contents($path, self::photo(40, 20, 6));
        foreach ($this->engines() as $name) {
            $this->cms->media->useEngine($name);
            $engine = $this->cms->media->activeEngine();
            $this->assertSame($name, $engine->name());
            $img = $engine->open($path);
            $this->assertSame([20, 40], [$img->width(), $img->height()], "{$name}: EXIF orientation 6 is applied");
            $jpeg = $img->encode('jpeg', 90);
            [$top, $bottom] = [self::rgb($jpeg, 10, 5), self::rgb($jpeg, 10, 35)];
            $this->assertTrue($top[0] > 200 && $top[2] < 60, "{$name}: the left (red) half is now on top");
            $this->assertTrue($bottom[2] > 200 && $bottom[0] < 60, "{$name}: blue below");
            $this->assertNotContains(self::MARK, $jpeg, "{$name}: encoding writes no metadata");
            $this->assertFalse($img->hasAlpha());

            $img->crop(0, 0, 20, 20)->resize(10, 10);
            $this->assertSame([10, 10], [$img->width(), $img->height()]);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $img->averageColor());
            foreach (['jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif'] as $format => $mime) {
                if ($engine->writes($format)) {
                    $this->assertSame($mime, (new \finfo(FILEINFO_MIME_TYPE))->buffer($img->encode($format, 60)), "{$name} writes {$format}");
                }
            }
            $img->destroy();
        }
        $this->assertTrue($this->engines() !== [], 'at least one engine');
    }

    public function testUploadedPhotosAreTurnedStrippedAndMeasured(): void
    {
        foreach ($this->engines() as $name) {
            $this->cms->media->useEngine($name);
            $turned = $this->upload('turned.jpg', self::photo(40, 20, 6));
            $this->assertSame([20, 40], [$turned['width'], $turned['height']], "{$name}: stored upright");
            $bytes = $this->stored($turned['id']);
            $this->assertSame([20, 40], array_slice(getimagesizefromstring($bytes), 0, 2));
            $this->assertNotContains(self::MARK, $bytes, "{$name}: camera and location data are gone");
            $this->assertNotContains('secret comment', $bytes);
            $this->assertSame(strlen($bytes), $turned['size'], 'the size is what was stored');
            $this->assertMatchesRegularExpression('#^data:image/webp;base64,[A-Za-z0-9+/=]{20,800}$#', (string) $turned['placeholder']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', (string) $turned['color']);
            $this->assertSame([0.5, 0.5], $turned['focus']);

            $upright = self::photo(40, 20);
            $plain = $this->upload('plain.jpg', $upright);
            $this->assertSame(Metadata::stripJpeg($upright), $this->stored($plain['id']), "{$name}: upright photos are stripped without re-encoding");
        }

        Settings::getInstance()->set('media_max_edge', '100');
        $big = $this->upload('wide.png', self::png(300, 150));
        $this->assertSame([100, 50], [$big['width'], $big['height']], 'originals beyond the maximum edge are scaled down');
        $this->assertSame([100, 50], array_slice(getimagesizefromstring($this->stored($big['id'])), 0, 2));

        $clear = $this->upload('glass.png', self::png(8, 8, alpha: true));
        $this->assertSame(['png'], array_slice($this->cms->container->make(MediaVariants::class)->formats(Media::query()->find($clear['id'])), -1), 'transparent images fall back to PNG, not JPEG');
    }

    public function testSizesNeverUpscaleAndCropAroundTheFocus(): void
    {
        $large = new MediaSize('large', 1920);
        $this->assertSame([640, 960, 1280, 1920], $large->widthsFor(3000, 2000));
        $this->assertSame([640, 960, 1280, 1500], $large->widthsFor(1500, 1000), 'the original width is the last step');
        $this->assertSame([640, 960, 1280], $large->widthsFor(1300, 900), 'unless a step is within 10% of it');
        $this->assertSame(['width' => 960, 'height' => 640, 'crop' => null], $large->plan(960, 3000, 2000, [0.5, 0.5]));

        $card = new MediaSize('card', 640, 400, 'cover');
        $this->assertSame(['width' => 320, 'height' => 200, 'crop' => [0, 375, 1000, 625]], $card->plan(320, 1000, 1000, [0.5, 0.9]), 'the crop follows the focal point, inside the image');
        $this->assertSame([0, 0, 1000, 625], $card->plan(320, 1000, 1000, [0.5, 0.0])['crop']);
        $this->assertSame([80], (new MediaSize('thumb', 320, 320, 'cover', [320]))->widthsFor(100, 80), 'small originals make one small variant');

        $box = new MediaSize('box', 800, 400);
        $this->assertSame(400, $box->maxWidth(1000, 1000), 'contain fits inside the box');

        $this->assertThrows(\InvalidArgumentException::class, fn() => new MediaSize('Bad Name', 100));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new MediaSize('card', 640, null, 'cover'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new MediaSize('card', 640, widths: [9000]));
    }

    public function testVariantsAreMadeSignedServedAndPruned(): void
    {
        $this->cms->media->useEngine('gd');
        $media = $this->upload('harbour.jpg', self::photo(1200, 800));
        $id = $media['id'];
        $variants = $this->cms->container->make(MediaVariants::class);
        $formats = $variants->formats(Media::query()->find($id));
        $this->assertSame(['avif', 'webp', 'jpeg'], $formats, 'GD here writes all three');

        $rows = $this->db->table('media_variants')->where('media_id', $id)->get();
        $this->assertCount(3, $rows, 'the thumbnail is made on upload, in every format');
        foreach ($rows as $row) {
            $row = (array) $row;
            $this->assertSame([320, 320], [(int) $row['width'], (int) $row['height']]);
            $this->assertFileExists($this->root . '/media/' . $row['path']);
            $this->assertMatchesRegularExpression('#/v/thumb-320-[0-9a-f]{10}\.(avif|webp|jpeg)$#', $row['path']);
        }

        $thumb = $media['sizes']['thumb'];
        $this->assertSame(['avif', 'webp', 'jpeg'], array_keys($thumb['srcset']));
        $this->assertMatchesRegularExpression('#^/uploads/.+/v/thumb-320-[0-9a-f]{10}\.avif 320w$#', $thumb['srcset']['avif'], 'made variants link to storage');
        $this->assertSame([320, 320], [$thumb['width'], $thumb['height']]);

        $one = $this->json($this->api('GET', "/media/{$id}"))['data'];
        $medium = $one['sizes']['medium'];
        $this->assertSame([960, 640], [$medium['width'], $medium['height']]);
        $urls = array_map(static fn($s) => explode(' ', $s)[0], explode(', ', $medium['srcset']['webp']));
        $this->assertCount(3, $urls, '320, 640 and 960');
        $this->assertMatchesRegularExpression('#^/system/media/' . $id . '/medium-640-[0-9a-f]{10}\.webp\?s=[0-9a-f]{32}$#', $urls[1], 'missing ones link to the signed generator');

        $made = $this->web($urls[1]);
        $this->assertSame(200, $made->statusCode(), $made->content());
        $this->assertSame('image/webp', $made->headers()['Content-Type']);
        $this->assertStringContainsString('immutable', $made->headers()['Cache-Control']);
        $this->assertSame([640, 427], array_slice(getimagesizefromstring($made->content()), 0, 2));
        $again = $this->web($urls[1]);
        $this->assertSame(301, $again->statusCode(), 'made once; then storage serves it');
        $this->assertMatchesRegularExpression('#^/uploads/.+/v/medium-640-[0-9a-f]{10}\.webp$#', $again->headers()['Location']);
        $this->assertCount(4, $this->db->table('media_variants')->where('media_id', $id)->get());

        $this->assertSame(403, $this->web(substr($urls[0], 0, -1) . (str_ends_with($urls[0], '0') ? '1' : '0'))->statusCode(), 'a changed signature is refused');
        $this->assertSame(403, $this->web(preg_replace('/\?s=.*/', '', $urls[0]))->statusCode());
        $hash = $variants->hash(Media::query()->find($id), $this->cms->media->get('medium'));
        $huge = "medium-4000-{$hash}.webp";
        $this->assertSame(404, $this->web(MediaVariants::ROUTE . "/{$id}/{$huge}?s=" . $variants->signature($id, $huge))->statusCode(), 'even signed, only planned widths are made: images are never upscaled');
        $stale = 'medium-640-0000000000.webp';
        $redirect = $this->web(MediaVariants::ROUTE . "/{$id}/{$stale}?s=" . $variants->signature($id, $stale));
        $this->assertSame(302, $redirect->statusCode(), 'a cached page with an old size gets the current one');
        $this->assertStringContainsString("/medium-640-{$hash}.webp?s=", $redirect->headers()['Location']);

        $oldThumbs = array_map(static fn($r) => ((array) $r)['path'], $this->db->table('media_variants')->where('media_id', $id)->where('name', 'like', 'thumb-%')->get());
        $this->assertSame(422, $this->api('PATCH', "/media/{$id}", ['focus' => [1.5, 0]])->statusCode());
        $refocused = $this->json($this->api('PATCH', "/media/{$id}", ['focus' => [0.1, 0.5]]))['data'];
        $this->assertSame([0.1, 0.5], $refocused['focus']);
        foreach ($oldThumbs as $path) {
            $this->assertFileDoesNotExist($this->root . '/media/' . $path, 'moving the focal point replaces the cropped variants');
        }
        $newThumb = $this->db->table('media_variants')->where('media_id', $id)->where('format', 'jpeg')->where('name', 'like', 'thumb-%')->value('path');
        $this->assertNotNull($newThumb);
        $this->assertNotContains($newThumb, $oldThumbs);
        $px = self::rgb((string) file_get_contents($this->root . '/media/' . $newThumb), 200, 160);
        $this->assertTrue($px[0] > 200 && $px[2] < 60, 'focused on the left: the square starts at the edge (centred, this pixel would be blue)');
        $this->assertCount(4, $this->db->table('media_variants')->where('media_id', $id)->get(), 'uncropped sizes survive a focus change');

        $all = array_map(static fn($r) => ((array) $r)['path'], $this->db->table('media_variants')->where('media_id', $id)->get());
        $this->assertSame(204, $this->api('DELETE', "/media/{$id}")->statusCode());
        foreach ($all as $path) {
            $this->assertFileDoesNotExist($this->root . '/media/' . $path, 'deleting a file deletes its variants');
        }
    }

    public function testSizeChangesAndAnimationsAndCommand(): void
    {
        $this->cms->media->useEngine('gd');
        $media = Media::query()->find($this->upload('square.png', self::png(900, 900))['id']);
        $variants = $this->cms->container->make(MediaVariants::class);

        $this->cms->media->size('card', 640, 400, 'cover');
        $made = $variants->generate($media, ['card']);
        $this->assertSame(6, $made, '320 and 640, in three formats');
        $this->assertSame(0, $variants->generate($media, ['card']), 'nothing twice');

        $this->cms->media->size('card', 600, 400, 'cover');
        $this->assertSame(6, $variants->prune($media), 'a redefined size outdates its variants');
        $this->cms->media->forget('card');
        $this->assertSame(0, $variants->prune($media));

        $command = new \Kestello\Cms\Console\Media\MediaVariantsCommand();
        $command->setOutput(new \Kestello\Framework\Console\Output(fopen('php://memory', 'w'), false));
        $this->assertSame(0, $command->handle([], []), 'the command makes what is missing');
        $this->assertSame(count($variants->plan($media)), $this->db->table('media_variants')->where('media_id', $media->getKey())->count(), 'every planned variant exists');
        $this->assertSame(18, count($variants->plan($media)), 'thumb 320; medium 320, 640, 900; large 640, 900; three formats each');

        if (extension_loaded('imagick')) {
            $gif = new \Imagick();
            foreach (['red', 'blue'] as $color) {
                $frame = new \Imagick();
                $frame->newImage(16, 16, new \ImagickPixel($color), 'gif');
                $gif->addImage($frame);
            }
            $anim = $this->upload('spin.gif', $gif->getImagesBlob());
            $this->assertTrue($anim['animated']);
            $this->assertNull($anim['sizes'], 'animations are served as uploaded');
            $this->assertSame(0, $this->db->table('media_variants')->where('media_id', $anim['id'])->count());
        }
    }

    public function testImagickDecodesOnlyTheFirstFrameWithinLimits(): void
    {
        if (!extension_loaded('imagick')) {
            $this->assertSame([], array_intersect(['imagick'], $this->engines()), 'no Imagick here');
            return;
        }
        $gif = new \Imagick();
        foreach (['red', 'blue', 'green', 'black'] as $color) {
            $frame = new \Imagick();
            $frame->newImage(64, 48, new \ImagickPixel($color), 'gif');
            $gif->addImage($frame);
        }
        $path = $this->root . '/many-frames.gif';
        $gif->writeImages($path, true);

        $handle = (new \Kestello\Cms\Media\Image\ImagickEngine())->open($path);
        $this->assertSame([64, 48], [$handle->width(), $handle->height()]);
        $this->assertSame($path . '[0]', \Kestello\Cms\Media\Image\ImagickEngine::firstFrame($path), 'frames after the first are never decoded');
        foreach (\Kestello\Cms\Media\Image\ImagickEngine::limits() as $type => $limit) {
            $this->assertSame($limit, (int) \Imagick::getResourceLimit($type), "resource {$type} is limited");
        }
        @unlink($path);
    }

    public function testSvgIsSanitizedToDrawingOnly(): void
    {
        $evil = <<<'SVG'
<?xml version="1.0"?>
<?xml-stylesheet href="http://evil.test/x.css"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 120 60" onload="alert(1)">
  <!-- hello -->
  <script>alert(2)</script>
  <defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/></linearGradient></defs>
  <style>.a { fill: url(#g); }</style>
  <style>@import url(http://evil.test/x.css);</style>
  <rect class="a" width="10" height="10" fill="url(#g)" onclick="alert(3)" style="fill: url(http://evil.test/t.png)"/>
  <circle r="5" fill="url(http://evil.test/#x)"/>
  <a href="javascript:alert(4)"><path d="M0 0L10 10" stroke="red"/></a>
  <use xlink:href="http://evil.test/sprite.svg#icon"/>
  <use href="#g"/>
  <image href="data:image/png;base64,iVBORw0KGgo="/>
  <image href="https://evil.test/track.png"/>
  <foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><script>alert(5)</script></body></foreignObject>
  <animate attributeName="href" to="javascript:alert(6)"/>
  <set attributeName="onmouseover" to="alert(7)"/>
  <text x="1" y="50" font-family="sans-serif">Hi &amp; bye</text>
</svg>
SVG;
        $clean = \Kestello\Support\Svg::sanitize($evil);
        foreach (['alert', 'evil.test', 'script', 'onload', 'onclick', 'foreignObject', 'animate', '<set', 'javascript', '<a ', 'xml-stylesheet', 'hello', '@import', '<style', 'data:'] as $gone) {
            $this->assertNotContains($gone, $clean, "{$gone} is removed");
        }
        foreach (['fill="url(#g)"', '<use href="#g"/>', 'Hi &amp; bye', 'viewBox="0 0 120 60"'] as $kept) {
            $this->assertStringContainsString($kept, $clean, "{$kept} stays");
        }
        $this->assertSame(['width' => 120, 'height' => 60], \Kestello\Support\Svg::dimensions($clean), 'size from the viewBox');
        $this->assertSame(['width' => 300, 'height' => 150], \Kestello\Support\Svg::dimensions('<svg width="300" viewBox="0 0 60 30"/>'), 'one attribute plus the viewBox ratio');
        $this->assertTrue(simplexml_load_string($clean) !== false, 'still well-formed');

        $bomb = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>';
        $this->assertSame('', \Kestello\Support\Svg::sanitize($bomb));
        $this->assertNotContains('java', \Kestello\Support\Svg::sanitize("<svg xmlns=\"http://www.w3.org/2000/svg\"><rect fill=\" java\tscript:alert(1)\"/></svg>"), 'hidden whitespace does not sneak a scheme in');

        $broken = $this->json($this->api('POST', '/media/uploads', ['filename' => 'x.svg', 'size' => 10]));
        $this->assertSame(422, $broken['status'] ?? 422);

        $start = fn(string $name) => $this->api('POST', '/media/uploads', ['filename' => $name, 'size' => strlen($evil)]);
        $this->assertSame(422, $start('logo.svg')->statusCode(), 'SVG is off by default');
        Settings::getInstance()->set('media_allow_svg', '1');
        $this->assertSame(422, $start('logo.svg.php')->statusCode());
        $this->assertSame(422, $start('logo.svgz')->statusCode());
        $logo = $this->upload('logo.svg', $evil);
        $this->assertSame(['image', 'image/svg+xml', 120, 60], [$logo['kind'], $logo['mime'], $logo['width'], $logo['height']]);
        $this->assertNull($logo['sizes'], 'vectors are not rasterised');
        $stored = $this->stored($logo['id']);
        $this->assertNotContains('alert', $stored, 'what is stored is the cleaned file');
        $this->assertSame(strlen($stored), $logo['size']);

        $served = $this->web($logo['url']);
        $this->assertSame(200, $served->statusCode());
        $this->assertSame('image/svg+xml', $served->headers()['Content-Type'], 'the library may show its (sanitized) SVG');
        $this->assertStringContainsString('sandbox', $served->headers()['Content-Security-Policy'], 'and it still cannot run anything');
        $this->assertTrue(str_starts_with($served->headers()['Content-Disposition'], 'inline'));
    }
}
