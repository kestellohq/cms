<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Media\Image\ImageEdit;
use Kestello\Cms\Media\MediaException;
use Kestello\Cms\Media\MediaModule;
use Kestello\Cms\Storage\SignedFileUrl;
use Kestello\Contracts\Media\TransformableImage;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Settings\Settings;
use Kestello\Tests\Auth\AuthTestCase;

/** Crop, turn and flip images in the media library, always from the untouched original. */
final class ImageEditTest extends AuthTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        Model::resetRegistry();
        $this->root = $this->tempDir();
        $this->cms->storage->configure($this->root, [], signer: static fn(string $disk, string $path, int $seconds, array $options): string => SignedFileUrl::make('', $disk, $path, $seconds, $options));
        foreach ([new \Kestello\Cms\Storage\StorageModule(), new ContentModule(), new MediaModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->createUser(['username' => 'aria', 'email' => 'aria@example.test', 'role' => 'author']);
        $this->signIn();
        $this->cms->media->useEngine('gd');
    }

    protected function tearDown(): void
    {
        Model::resetRegistry();
        Settings::getInstance()->delete('media_allow_svg');
        $this->cms->media->useEngine(null);
        parent::tearDown();
    }

    private function signIn(string $login = 'ana'): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    /** @return array<string, mixed> */
    private function upload(string $filename, string $bytes): array
    {
        $up = $this->json($this->api('POST', '/media/uploads', ['filename' => $filename, 'size' => strlen($bytes)]))['data'];
        foreach (str_split($bytes, $up['chunk_size']) as $i => $chunk) {
            $this->api('PUT', "/media/uploads/{$up['id']}/chunks/{$i}", raw: $chunk);
        }
        $r = $this->api('POST', "/media/uploads/{$up['id']}/complete");
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    private function path(int $id): string
    {
        return (string) $this->db->table('media')->where('id', $id)->value('path');
    }

    private function file(string $path): string
    {
        return $this->root . '/media/' . $path;
    }

    /** Left half red, right half blue. */
    private static function photo(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);

        return (string) ob_get_clean();
    }

    /** @return 'red'|'blue'|'other' the colour at x, y */
    private static function color(string $bytes, int $x, int $y): string
    {
        $c = imagecolorat(imagecreatefromstring($bytes), $x, $y);
        [$r, $b] = [($c >> 16) & 0xFF, $c & 0xFF];

        return $r > 200 && $b < 60 ? 'red' : ($b > 200 && $r < 60 ? 'blue' : 'other');
    }

    /** @param array<string, mixed> $body */
    private static function invalid(array $body): array
    {
        try {
            ImageEdit::fromInput($body);
        } catch (MediaException $e) {
            return array_keys($e->errors);
        }

        return [];
    }

    public function testEditsAreCheckedAndMapPointsBothWays(): void
    {
        $edit = ImageEdit::fromInput(['rotate' => -90, 'flip_horizontal' => true, 'crop' => ['x' => 0.25, 'y' => 0, 'width' => 0.5, 'height' => 1]]);
        $this->assertSame(['rotate' => 270, 'flip_horizontal' => true, 'flip_vertical' => false, 'crop' => ['x' => 0.25, 'y' => 0.0, 'width' => 0.5, 'height' => 1.0]], $edit->toArray(), 'a quarter turn back is three forward');
        $this->assertSame([800, 1200], $edit->orientedSize(1200, 800), 'a quarter turn swaps the sides');
        $this->assertSame([200, 0, 400, 1200], $edit->cropBox(800, 1200), 'the crop is in pixels of the turned image');

        foreach ([[0.1, 0.3], [0.5, 0.5], [0.9, 0.7]] as $point) {    // inside the crop
            $back = $edit->unmapPoint($edit->mapPoint($point));
            $this->assertTrue(abs($point[0] - $back[0]) < 1e-9 && abs($point[1] - $back[1]) < 1e-9, 'mapping a point into the edit and back returns it');
        }
        $turned = ImageEdit::fromInput(['rotate' => 90]);
        $this->assertSame([0.75, 0.1], $turned->mapPoint([0.1, 0.25]), 'turned clockwise, the left edge becomes the top');
        $this->assertSame([0.0, 1.0], ImageEdit::fromInput(['crop' => ['x' => 0.5, 'y' => 0, 'width' => 0.5, 'height' => 0.5]])->mapPoint([0.1, 0.9]), 'a point outside the crop moves to its nearest edge');

        $this->assertTrue(ImageEdit::fromInput([])->isIdentity());
        $this->assertTrue(ImageEdit::fromInput(['rotate' => 360, 'crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]])->isIdentity(), 'a full turn and a full crop change nothing');
        $this->assertSame(['rotate'], self::invalid(['rotate' => 45]));
        $this->assertSame(['crop'], self::invalid(['crop' => ['x' => 0.6, 'y' => 0, 'width' => 0.5, 'height' => 1]]), 'the crop must fit inside the image');
        $this->assertSame(['crop'], self::invalid(['crop' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 1]]));
        $this->assertSame(['flip_vertical'], self::invalid(['flip_vertical' => 'yes']));
    }

    public function testEnginesTurnAndFlip(): void
    {
        $path = $this->tempDir() . '/photo.jpg';
        file_put_contents($path, self::photo(40, 20));
        $engines = array_filter($this->cms->media->engines(), static fn($e) => $e->available());
        $this->assertNotSame([], $engines);
        foreach ($engines as $engine) {
            $img = $engine->open($path);
            $this->assertInstanceOf(TransformableImage::class, $img, "{$engine->name()} can turn and flip");
            $img->rotate(90);
            $this->assertSame([20, 40], [$img->width(), $img->height()]);
            $jpeg = $img->encode('jpeg', 90);
            $this->assertSame(['red', 'blue'], [self::color($jpeg, 10, 5), self::color($jpeg, 10, 35)], "{$engine->name()}: clockwise, the left half goes to the top");
            $img->flip(horizontal: false);
            $jpeg = $img->encode('jpeg', 90);
            $this->assertSame(['blue', 'red'], [self::color($jpeg, 10, 5), self::color($jpeg, 10, 35)], "{$engine->name()}: flipped upside down");
            $img->rotate(270)->flip(horizontal: true);
            $jpeg = $img->encode('jpeg', 90);
            $this->assertSame([40, 20], [$img->width(), $img->height()]);
            $this->assertSame(['red', 'blue'], [self::color($jpeg, 5, 10), self::color($jpeg, 35, 10)], "{$engine->name()}: a three-quarter turn puts blue on the left; mirrored, red is back");
            $img->destroy();
        }
    }

    public function testEditingCropsFromTheOriginalWhichStaysPrivateUntilRestored(): void
    {
        $media = $this->upload('harbour.jpg', self::photo(1200, 800));
        $id = $media['id'];
        $original = $this->path($id);
        $this->assertTrue($media['can']['edit_image']);
        $this->assertNull($media['edit']);
        $oldThumbs = array_map(static fn($r) => ((array) $r)['path'], $this->db->table('media_variants')->where('media_id', $id)->get());
        $this->assertNotSame([], $oldThumbs);

        // Turn a quarter clockwise (red on top), keep the top half: all red.
        $r = $this->api('POST', "/media/{$id}/edit", ['rotate' => 90, 'crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 0.5]]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $edited = $this->json($r)['data'];
        $this->assertSame([800, 600], [$edited['width'], $edited['height']]);
        $this->assertEquals(['rotate' => 90, 'flip_horizontal' => false, 'flip_vertical' => false, 'crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 0.5]], $edited['edit']);
        $this->assertEquals([0.5, 1], $edited['focus'], 'the focal point follows the turn and stays inside the crop');
        $this->assertSame([1200, 800], [$edited['original']['width'], $edited['original']['height']]);
        $this->assertStringStartsWith('/system/files?', $edited['original']['url'], 'the editor opens the original through an expiring link');
        $first = $this->path($id);
        $this->assertNotSame($original, $first, 'the edit is a new file, so caches never serve the old picture');
        $this->assertSame(dirname($original), dirname($first), "a file's versions share its folder");
        $bytes = (string) file_get_contents($this->file($first));
        $this->assertSame(['red', 'red'], [self::color($bytes, 10, 10), self::color($bytes, 790, 590)]);
        $this->assertSame(hash('sha256', $bytes), $this->db->table('media')->where('id', $id)->value('checksum'));
        $this->assertSame(0600, fileperms($this->file($original)) & 0777, 'the uncropped original is no longer public');
        $this->assertSame(0644, fileperms($this->file($first)) & 0777);
        foreach ($oldThumbs as $path) {
            $this->assertFileDoesNotExist($this->file($path), 'sizes of the old picture are replaced');
        }
        $audit = json_decode((string) $this->db->table('audit_log')->where('event', 'media.updated')->orderByDesc('id')->value('data'), true);
        $this->assertEquals(['fields' => ['image'], 'edit' => $edited['edit']], $audit, 'recorded, which also refreshes cached pages');

        // A second edit starts again from the original: the bottom half, all blue.
        $again = $this->json($this->api('POST', "/media/{$id}/edit", ['rotate' => 90, 'crop' => ['x' => 0, 'y' => 0.5, 'width' => 1, 'height' => 0.5]]))['data'];
        $this->assertSame([800, 600], [$again['width'], $again['height']]);
        $second = $this->path($id);
        $this->assertFileDoesNotExist($this->file($first), 'the previous edit is deleted');
        $this->assertSame('blue', self::color((string) file_get_contents($this->file($second)), 400, 300));

        // Another person's file, or a file that isn't a picture the engine writes, can't be edited.
        $this->signIn('aria');
        $this->assertSame(403, $this->api('POST', "/media/{$id}/edit", ['rotate' => 90])->statusCode());
        $this->signIn();

        // Restore: the original is the file again, public, and the edit is gone.
        $restored = $this->json($this->api('POST', "/media/{$id}/restore"))['data'];
        $this->assertSame([1200, 800, null, null], [$restored['width'], $restored['height'], $restored['edit'], $restored['original']]);
        $this->assertSame($original, $this->path($id));
        $this->assertSame(0644, fileperms($this->file($original)) & 0777);
        $this->assertFileDoesNotExist($this->file($second));
        $this->assertSame(409, $this->api('POST', "/media/{$id}/restore")->statusCode(), 'nothing to restore');

        // An edit that changes nothing is a restore; deleting an edited file deletes its original too.
        $this->api('POST', "/media/{$id}/edit", ['flip_horizontal' => true]);
        $this->assertNull($this->json($this->api('POST', "/media/{$id}/edit", ['rotate' => 0]))['data']['edit']);
        $this->api('POST', "/media/{$id}/edit", ['flip_vertical' => true]);
        $flipped = $this->path($id);
        $this->assertSame(204, $this->api('DELETE', "/media/{$id}")->statusCode());
        $this->assertFileDoesNotExist($this->file($flipped));
        $this->assertFileDoesNotExist($this->file($original));
    }

    public function testOnlyStillPicturesCanBeEdited(): void
    {
        Settings::getInstance()->set('media_allow_svg', '1');
        $svg = $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');
        $this->assertFalse($svg['can']['edit_image']);
        $r = $this->api('POST', "/media/{$svg['id']}/edit", ['rotate' => 90]);
        $this->assertSame(422, $r->statusCode());
        $this->assertStringContainsString('Drawings (SVG)', $r->content(), 'the answer says why');

        $this->cms->media->useEngine(null);
        $this->cms->media->engine(new class implements \Kestello\Contracts\Media\ImageEngine {
            public function name(): string
            {
                return 'none';
            }
            public function available(): bool
            {
                return true;
            }
            public function reads(string $mime): bool
            {
                return false;
            }
            public function writes(string $format): bool
            {
                return false;
            }
            public function open(string $path): \Kestello\Contracts\Media\EditableImage
            {
                throw new \Kestello\Contracts\Media\ImageException('no');
            }
        });
        $this->cms->media->useEngine('none');
        $photo = $this->upload('harbour.jpg', self::photo(40, 20));
        $this->assertFalse($photo['can']['edit_image']);
        $this->assertStringContainsString('image engine', $this->api('POST', "/media/{$photo['id']}/edit", ['rotate' => 90])->content());
    }
}
