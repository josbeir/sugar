<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Parser\Helper\HtmlScanHelper;

final class HtmlScanHelperTest extends TestCase
{
    public function testBuildLineStartsAndFindLineIndex(): void
    {
        $helper = new HtmlScanHelper();
        $source = "one\ntwo\nthree";
        $lineStarts = $helper->buildLineStarts($source);

        $this->assertSame([0, 4, 8], $lineStarts);
        $this->assertSame(0, $helper->findLineIndexFromStarts($lineStarts, 0));
        $this->assertSame(0, $helper->findLineIndexFromStarts($lineStarts, 3));
        $this->assertSame(1, $helper->findLineIndexFromStarts($lineStarts, 4));
        $this->assertSame(2, $helper->findLineIndexFromStarts($lineStarts, 9));
    }

    public function testFindLineNumberFromStartsReturnsOneBasedLine(): void
    {
        $helper = new HtmlScanHelper();
        $lineStarts = [0, 6, 10];

        $this->assertSame(1, $helper->findLineNumberFromStarts($lineStarts, 0));
        $this->assertSame(2, $helper->findLineNumberFromStarts($lineStarts, 6));
        $this->assertSame(3, $helper->findLineNumberFromStarts($lineStarts, 11));
    }

    public function testReadTagNameEndHandlesHyphens(): void
    {
        $helper = new HtmlScanHelper();
        $tag = '<s-template class="x">';

        $nameEnd = $helper->readTagNameEnd($tag, 1);

        $this->assertSame(11, $nameEnd);
        $this->assertSame('s-template', substr($tag, 1, $nameEnd - 1));
    }

    public function testFindTagEndHonorsQuotedGreaterThan(): void
    {
        $helper = new HtmlScanHelper();
        $tag = '<div data-a=">" data-b="x">';

        $end = $helper->findTagEnd($tag, 1);

        $this->assertSame(strlen($tag), $end);
    }

    public function testFindTagEndIgnoresEscapedQuotesInsideAttributes(): void
    {
        $helper = new HtmlScanHelper();
        $tag = "<div data-a=\"foo\\\"bar\" data-b='baz\\'qux'>";

        $end = $helper->findTagEnd($tag, 1);

        $this->assertSame(strlen($tag), $end);
    }

    public function testFindTagEndReturnsNullWhenTagIsUnterminated(): void
    {
        $helper = new HtmlScanHelper();
        $this->assertNull($helper->findTagEnd('<div class="x"', 1));
    }

    public function testResolvePositionHandlesEmptySourceAndLargeOffset(): void
    {
        $helper = new HtmlScanHelper();
        $emptyResult = $helper->resolvePosition('', 10, 3, 4);
        $this->assertSame([3, 4], $emptyResult);

        $clampedResult = $helper->resolvePosition('abc', 10, 1, 1);
        $this->assertSame([1, 4], $clampedResult);
    }
}
