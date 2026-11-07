<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Console\Package\CoreVerifyCommand;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\Update\CoreRelease;
use Kestello\Framework\Console\Output;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Testing\TestCase;

/** core:verify, which the release workflow runs before publishing: signed by a key sites trust? */
final class CoreVerifyCommandTest extends TestCase
{
    private string $root;
    private string $zip;

    protected function setUp(): void
    {
        $this->root = $this->tempDir();
        $core = $this->root . '/core';
        mkdir($core . '/src', 0775, true);
        mkdir($core . '/config');
        file_put_contents($core . '/VERSION', '1.0.0');
        file_put_contents($core . '/core.json', '{"requires":{"php":">=8.4"}}');
        file_put_contents($core . '/bootstrap.php', "<?php\n");
        file_put_contents($core . '/src/Kernel.php', "<?php\n");
        $this->zip = CoreRelease::build($core, $this->root . '/dist')['zip'];
    }

    /** @param list<array{name: string, public_key: string}> $keys */
    private function verify(array $keys): array
    {
        file_put_contents($this->root . '/core/config/release.php', '<?php return ' . var_export(['keys' => $keys], true) . ';');
        $command = new CoreVerifyCommand(new Paths($this->root, $this->root . '/core'));
        $out = fopen('php://memory', 'w+');
        $command->setOutput(new Output($out, false));
        $code = $command->handle([$this->zip], []);
        rewind($out);

        return [$code, (string) stream_get_contents($out)];
    }

    public function testASignedReleaseWithItsKeyListedPasses(): void
    {
        $keys = PackageSignature::generateKeys();
        PackageSignature::sign($this->zip, $keys['secret_key']);
        file_put_contents($this->zip . '.sha256', hash_file('sha256', $this->zip) . "  x.zip\n");

        [$code, $out] = $this->verify([['name' => 'Kestello releases', 'public_key' => $keys['public_key']]]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('signed by Kestello releases', $out);
    }

    public function testNoListedKeysUnsignedOtherKeysAndStaleChecksumsFail(): void
    {
        $listed = PackageSignature::generateKeys();
        $entry = [['name' => 'Kestello releases', 'public_key' => $listed['public_key']]];

        @unlink($this->zip . '.sha256');   // the build's checksum, before signing
        [$code, $out] = $this->verify([]);
        $this->assertSame([1, true], [$code, str_contains($out, 'lists no release keys')], $out);

        [$code, $out] = $this->verify($entry);
        $this->assertSame([1, true], [$code, str_contains($out, 'not signed')], $out);

        PackageSignature::sign($this->zip, PackageSignature::generateKeys()['secret_key']);
        file_put_contents($this->zip . '.sha256', hash_file('sha256', $this->zip) . "  x.zip\n");
        [$code, $out] = $this->verify($entry);
        $this->assertSame([1, true], [$code, str_contains($out, 'not in core/config/release.php')], $out);

        file_put_contents($this->zip . '.sha256', str_repeat('0', 64) . "  x.zip\n");
        [$code, $out] = $this->verify($entry);
        $this->assertSame([1, true], [$code, str_contains($out, 'does not match the zip')], $out);
    }
}
