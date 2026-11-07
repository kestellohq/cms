<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Testing\TestCase;
use Kestello\Support\TextDiff;

final class TextDiffTest extends TestCase
{
    public function testLinesAndWords(): void
    {
        $this->assertSame([
            ['op' => 'equal', 'text' => 'Intro'],
            ['op' => 'change', 'parts' => [['equal', 'Open '], ['delete', 'Monday'], ['insert', 'Tuesday'], ['equal', ' to Friday']]],
            ['op' => 'delete', 'text' => 'Closed in August'],
            ['op' => 'equal', 'text' => 'Bye'],
            ['op' => 'insert', 'text' => 'PS'],
        ], TextDiff::lines("Intro\nOpen Monday to Friday\nClosed in August\nBye", "Intro\nOpen Tuesday to Friday\nBye\nPS"));

        $this->assertSame([['op' => 'insert', 'text' => 'New']], TextDiff::lines('', 'New'));
        $this->assertSame([['op' => 'delete', 'text' => 'Old']], TextDiff::lines('Old', ''));
        $this->assertTrue(TextDiff::same(TextDiff::lines("a\nb", "a\nb")));
        $this->assertSame([['equal', 'the '], ['insert', 'very '], ['equal', 'end']], TextDiff::words('the end', 'the very end'));
    }

    public function testHugeInputsFallBackToReplaceAll(): void
    {
        $a = implode("\n", array_map(static fn($i) => "a{$i}", range(1, 600)));
        $b = implode("\n", array_map(static fn($i) => "b{$i}", range(1, 600)));
        $lines = TextDiff::lines($a, $b);
        $this->assertCount(600, $lines, 'paired into changes');
        $this->assertSame('change', $lines[0]['op']);
    }
}
