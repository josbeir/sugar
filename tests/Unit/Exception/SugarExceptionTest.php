<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test SugarException base class formatting and location tracking
 */
final class SugarExceptionTest extends TestCase
{
    public function testExceptionWithMessageOnly(): void
    {
        $exception = new TestSugarException('Something went wrong');

        $this->assertSame('Something went wrong', $exception->getMessage());
        $this->assertNull($exception->templatePath);
        $this->assertNull($exception->templateLine);
        $this->assertNull($exception->templateColumn);
        $this->assertNull($exception->snippet);
    }

    public function testExceptionWithTemplatePathAndLine(): void
    {
        $exception = new TestSugarException(
            message: 'Syntax error',
            templatePath: 'views/profile.sugar.php',
            templateLine: 42,
        );

        $this->assertSame('views/profile.sugar.php', $exception->templatePath);
        $this->assertSame(42, $exception->templateLine);
        $this->assertNull($exception->templateColumn);

        // Message should be formatted with location
        $message = $exception->getMessage();
        $this->assertStringContainsString('views/profile.sugar.php:42', $message);
        $this->assertStringContainsString('Syntax error', $message);
    }

    public function testExceptionWithFullLocation(): void
    {
        $exception = new TestSugarException(
            message: 'Unexpected token',
            templatePath: 'components/button.sugar.php',
            templateLine: 15,
            templateColumn: 8,
        );

        $this->assertSame('components/button.sugar.php', $exception->templatePath);
        $this->assertSame(15, $exception->templateLine);
        $this->assertSame(8, $exception->templateColumn);

        // Message should include line:column
        $message = $exception->getMessage();
        $this->assertStringContainsString('components/button.sugar.php:15:8', $message);
    }

    public function testExceptionWithSnippet(): void
    {
        $snippet = <<<'SNIPPET'
 12 | <div s:if="$user">
 13 |     <p s:forech="$items">
    |        ^
 14 |         <?= $item ?>
SNIPPET;

        $exception = new TestSugarException(
            message: 'Unknown directive',
            templatePath: 'test.sugar.php',
            templateLine: 13,
            templateColumn: 8,
            snippet: $snippet,
        );

        $this->assertSame($snippet, $exception->snippet);

        // Message should include snippet
        $message = $exception->getMessage();
        $this->assertStringContainsString($snippet, $message);
        $this->assertStringContainsString('Unknown directive', $message);
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new RuntimeException('Original error');
        $exception = new TestSugarException(
            message: 'Compilation failed',
            previous: $previous,
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionFormatsMessageCorrectly(): void
    {
        $exception = new TestSugarException(
            message: 'Test error',
            templatePath: 'test.sugar.php',
            templateLine: 10,
            templateColumn: 5,
            snippet: ' 10 | test line',
        );

        $message = $exception->getMessage();

        // Should have format: Template: path:line:column\nMessage\n\nSnippet
        $this->assertMatchesRegularExpression(
            '/Template: test\.sugar\.php:10:5\s+Test error\s+10 \| test line/',
            $message,
        );
    }
}
