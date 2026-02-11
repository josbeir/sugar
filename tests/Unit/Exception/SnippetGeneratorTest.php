<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Sugar\Exception\SnippetGenerator;

/**
 * Test SnippetGenerator for showing template context around errors
 */
final class SnippetGeneratorTest extends TestCase
{
    public function testGeneratesSnippetWithSingleContextLine(): void
    {
        $source = <<<'PHP'
<div>
    <p s:forech="$items">
        <?= $item ?>
    </p>
</div>
PHP;

        $snippet = SnippetGenerator::generate($source, line: 2, column: 8, contextLines: 1);

        $expected = implode("\n", [
            '1 | <div>',
            '2 |     <p s:forech="$items">',
            '0 | _______^',
            '3 |         <?= $item ?>',
        ]);

        $this->assertSame($expected, $snippet);
    }

    public function testGeneratesSnippetWithMultipleContextLines(): void
    {
        $source = <<<'PHP'
<html>
<head>
    <title>Test</title>
</head>
<body>
    <div s:if="$invalid">
        Content
    </div>
</body>
</html>
PHP;

        $snippet = SnippetGenerator::generate($source, line: 6, column: 12, contextLines: 2);

        // Should show 2 lines before and 2 lines after
        $this->assertStringContainsString('4 | </head>', $snippet);
        $this->assertStringContainsString('5 | <body>', $snippet);
        $this->assertStringContainsString('6 |     <div s:if="$invalid">', $snippet);
        $this->assertStringContainsString('0 | ___________^', $snippet); // Error pointer
        $this->assertStringContainsString('7 |         Content', $snippet);
        $this->assertStringContainsString('8 |     </div>', $snippet);
    }

    public function testHandlesFirstLine(): void
    {
        $source = <<<'PHP'
<div s:unknown="value">
    Content
</div>
PHP;

        $snippet = SnippetGenerator::generate($source, line: 1, column: 5);

        // Should not show lines before line 1
        $this->assertStringContainsString('1 | <div s:unknown="value">', $snippet);
        $this->assertStringContainsString('0 | ____^', $snippet);
        $this->assertStringContainsString('2 |     Content', $snippet);
        $this->assertStringNotContainsString(' 0 |', $snippet);
    }

    public function testHandlesLastLine(): void
    {
        $source = <<<'PHP'
<div>
    Content
</div invalid>
PHP;

        $snippet = SnippetGenerator::generate($source, line: 3, column: 5);

        // Should not show lines after last line
        $this->assertStringContainsString('2 |     Content', $snippet);
        $this->assertStringContainsString('3 | </div invalid>', $snippet);
        $this->assertStringContainsString('0 | ____^', $snippet);
        $this->assertStringNotContainsString(' 4 |', $snippet);
    }

    public function testHandlesShortFile(): void
    {
        $source = '<div s:error="value">';

        $snippet = SnippetGenerator::generate($source, line: 1, column: 5, contextLines: 2);

        // Should work with single line file
        $this->assertStringContainsString('1 | <div s:error="value">', $snippet);
        $this->assertStringContainsString('0 | ____^', $snippet);
    }

    public function testLineNumberPadding(): void
    {
        $source = implode("\n", array_fill(0, 101, 'line')); // 101 lines so we can show line 101

        $snippet = SnippetGenerator::generate($source, line: 99, column: 1, contextLines: 2);

        // Should pad line numbers correctly for 3-digit numbers
        $this->assertStringContainsString('097 | line', $snippet);
        $this->assertStringContainsString('098 | line', $snippet);
        $this->assertStringContainsString('099 | line', $snippet);
        $this->assertStringContainsString('100 | line', $snippet);
        $this->assertStringContainsString('101 | line', $snippet);
    }

    public function testPointerAlignment(): void
    {
        $source = 'Error at column 20';

        $snippet = SnippetGenerator::generate($source, line: 1, column: 20);

        // Pointer should align under column 20
        $lines = explode("\n", $snippet);
        $pointerLine = array_filter($lines, fn($line): bool => str_contains($line, '^'));
        $this->assertCount(1, $pointerLine);

        $pointerLine = array_values($pointerLine)[0];
        $pointerPosition = strpos($pointerLine, '^');

        // Calculate expected position: "1 | " = 4 chars + column 20 - 1 = 23
        $this->assertSame(23, $pointerPosition);
    }

    public function testHandlesTabCharacters(): void
    {
        $source = "\t<div s:if=\"\$test\">";

        $snippet = SnippetGenerator::generate($source, line: 1, column: 7);

        // Tabs should be preserved or converted consistently
        $this->assertStringContainsString('1 |', $snippet);
        $this->assertStringContainsString('<div', $snippet);
    }

    public function testDefaultContextLines(): void
    {
        $source = implode("\n", [
            'line 1',
            'line 2',
            'line 3 error',
            'line 4',
            'line 5',
            'line 6',
        ]);

        // Default should be 2 context lines
        $snippet = SnippetGenerator::generate($source, line: 3, column: 8);

        $this->assertStringContainsString('1 | line 1', $snippet);
        $this->assertStringContainsString('2 | line 2', $snippet);
        $this->assertStringContainsString('3 | line 3 error', $snippet);
        $this->assertStringContainsString('4 | line 4', $snippet);
        $this->assertStringContainsString('5 | line 5', $snippet);
        $this->assertStringNotContainsString('6 | line 6', $snippet);
    }

    public function testReturnsEmptySnippetForEmptySource(): void
    {
        $this->assertSame('', SnippetGenerator::generate('', line: 1, column: 1));
        $this->assertSame('', SnippetGenerator::generate("\n\n", line: 1, column: 1));
    }

    public function testReturnsEmptySnippetForOutOfRangeLine(): void
    {
        $source = "line 1\nline 2";

        $this->assertSame('', SnippetGenerator::generate($source, line: 3, column: 1));
    }

    public function testReturnsEmptySnippetForEmptyLine(): void
    {
        $source = "line 1\n\nline 3";

        $this->assertSame('', SnippetGenerator::generate($source, line: 2, column: 1));
    }

    public function testDoesNotRenderPointerWhenColumnIsZero(): void
    {
        $source = "line 1\nline 2";

        $snippet = SnippetGenerator::generate($source, line: 2, column: 0, contextLines: 0);

        $this->assertStringContainsString('2 | line 2', $snippet);
        $this->assertStringNotContainsString('^', $snippet);
    }
}
