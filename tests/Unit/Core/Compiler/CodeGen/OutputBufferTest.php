<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Compiler\CodeGen;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Compiler\CodeGen\OutputBuffer;

final class OutputBufferTest extends TestCase
{
    public function testWriteAppendsContent(): void
    {
        $buffer = new OutputBuffer();
        $buffer->write('Hello');
        $buffer->write(' World');

        $this->assertSame('Hello World', $buffer->getContent());
    }

    public function testWritelnAppendsWithNewline(): void
    {
        $buffer = new OutputBuffer();
        $buffer->writeln('Line 1');
        $buffer->writeln('Line 2');

        $this->assertSame("Line 1\nLine 2\n", $buffer->getContent());
    }

    public function testMixedWriteAndWriteln(): void
    {
        $buffer = new OutputBuffer();
        $buffer->write('Start ');
        $buffer->writeln('of line');
        $buffer->write('End');

        $this->assertSame("Start of line\nEnd", $buffer->getContent());
    }

    public function testClearResetsBuffer(): void
    {
        $buffer = new OutputBuffer();
        $buffer->write('Some content');

        $this->assertSame('Some content', $buffer->getContent());

        $buffer->clear();

        $this->assertSame('', $buffer->getContent());
    }

    public function testEmptyBuffer(): void
    {
        $buffer = new OutputBuffer();

        $this->assertSame('', $buffer->getContent());
    }

    public function testMultipleClearOperations(): void
    {
        $buffer = new OutputBuffer();
        $buffer->write('Content 1');
        $buffer->clear();
        $buffer->write('Content 2');
        $buffer->clear();

        $this->assertSame('', $buffer->getContent());

        $buffer->write('Content 3');
        $this->assertSame('Content 3', $buffer->getContent());
    }
}
