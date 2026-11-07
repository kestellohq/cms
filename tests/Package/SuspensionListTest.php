<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Extension\SuspensionList;
use Kestello\Framework\Testing\TestCase;

final class SuspensionListTest extends TestCase
{
    public function testARolledBackUpdateUndoesOnlyItsOwnSuspensions(): void
    {
        $file = $this->tempDir() . '/suspended.json';
        $updater = new SuspensionList($file);
        $admin = new SuspensionList($file);       // another process
        $updater->suspend('plugin:old-trouble', 'crashed last week');
        $updater->suspend('plugin:lifted', 'crashed yesterday');

        $before = $updater->all();
        $updater->suspend('plugin:acme', 'the new version failed its check');   // caused by the update
        $admin->clear('plugin:lifted');                                         // meanwhile, in the admin

        $updater->restore($before);
        $this->assertSame(['plugin:old-trouble'], array_keys((new SuspensionList($file))->all()), 'the update\'s suspension goes, the admin\'s change stays');
    }
}
