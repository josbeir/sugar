<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Parser\Helper\HtmlScanHelper;

final class HtmlScanHelperTest extends TestCase
{
    public function testBuildLineStartsAndFindLineIndex(): void
    {
        $source = "one\ntwo\nthree";
        $lineStarts = HtmlScanHelper::buildLineStarts($source);

        $this->assertSame([0, 4, 8], $lineStarts);
        $this->assertSame(0, HtmlScanHelper::findLineIndexFromStarts($lineStarts, 0));
        $this->assertSame(0, HtmlScanHelper::findLineIndexFromStarts($lineStarts, 3));
        $this->assertSame(1, HtmlScanHelper::findLineIndexFromStarts($lineStarts, 4));
        $this->assertSame(2, HtmlScanHelper::findLineIndexFromStarts($lineStarts, 9));
    }

    public function testFindLineNumberFromStartsReturnsOneBasedLine(): void
    {
        $lineStarts = [0, 6, 10];

        $this->assertSame(1, HtmlScanHelper::findLineNumberFromStarts($lineStarts, 0));
        $this->assertSame(2, HtmlScanHelper::findLineNumberFromStarts($lineStarts, 6));
        $this->assertSame(3, HtmlScanHelper::findLineNumberFromStarts($lineStarts, 11));
    }

    public function testReadTagNameEndHandlesHyphens(): void
    {
        $tag = '<s-template class="x">';

        $nameEnd = HtmlScanHelper::readTagNameEnd($tag, 1);

        $this->assertSame(11, $nameEnd);
        $this->assertSame('s-template', substr($tag, 1, $nameEnd - 1));
    }

    public function testFindTagEndHonorsQuotedGreaterThan(): void
    {
        $tag = '<div data-a=">" data-b="x">';

        $end = HtmlScanHelper::findTagEnd($tag, 1);

        $this->assertSame(strlen($tag), $end);
    }

    public function testFindTagEndIgnoresEscapedQuotesInsideAttributes(): void
    {
        $tag = "<div data-a=\"foo\\\"bar\" data-b='baz\\'qux'>";

        $end = HtmlScanHelper::findTagEnd($tag, 1);

        $this->assertSame(strlen($tag), $end);
    }

    public function testFindTagEndReturnsNullWhenTagIsUnterminated(): void
    {
        $this->assertNull(HtmlScanHelper::findTagEnd('<div class="x"', 1));
    }
}
