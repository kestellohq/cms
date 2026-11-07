<?php

declare(strict_types=1);

namespace Kestello\Tests\I18n;

use Kestello\Framework\Testing\TestCase;

/**
 * What a save is refused for reaches people in the admin's language: every
 * problem core records goes through Translator::get with a literal message,
 * so `bin/cms i18n:extract` finds it.
 */
final class MessagesTranslatedTest extends TestCase
{
    public function testCoreRecordsProblemsThroughTheTranslator(): void
    {
        $untranslated = [];
        $root = dirname(__DIR__, 2) . '/core/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                if (preg_match('/\$errors\[[^\]]*\](?:\[[^\]]*\])*\[\] = [\'"]/', $line) === 1) {
                    $untranslated[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($n + 1) . ' ' . trim($line);
                }
            }
        }
        $this->assertSame([], $untranslated);
    }
}
