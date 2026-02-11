<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Exception\Renderer;

use PHPUnit\Framework\TestCase;
use Sugar\Exception\Renderer\TemplateHighlightFormatter;

final class TemplateHighlightFormatterTest extends TestCase
{
    public function testFormatsFullTemplateWithCaret(): void
    {
        $formatter = new TemplateHighlightFormatter();
        $result = $formatter->format("first\nsecond\nthird", 2, 3);

        $this->assertCount(4, $result->lines);
        $this->assertSame('1 | first', $result->lines[0]->text);
        $this->assertSame('2 | second', $result->lines[1]->text);
        $this->assertSame('  |   ^', $result->lines[2]->text);
        $this->assertSame('3 | third', $result->lines[3]->text);
        $this->assertTrue($result->lines[1]->isErrorLine);
        $this->assertTrue($result->lines[2]->isCaretLine);
    }

    public function testReturnsEmptyResultForEmptySource(): void
    {
        $formatter = new TemplateHighlightFormatter();
        $result = $formatter->format('', 1, 1);

        $this->assertSame([], $result->lines);
    }
}
