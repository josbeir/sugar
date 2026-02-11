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
        $this->assertSame('Something went wrong', $exception->getRawMessage());
        $this->assertNull($exception->templatePath);
        $this->assertNull($exception->templateLine);
        $this->assertNull($exception->templateColumn);
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
        $this->assertSame('Syntax error', $exception->getRawMessage());

        // Message should be formatted with location
        $message = $exception->getMessage();
        $this->assertStringContainsString('Syntax error', $message);
        $this->assertStringContainsString('template: views/profile.sugar.php line:42', $message);
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

        // Message should include line and column
        $message = $exception->getMessage();
        $this->assertStringContainsString('template: components/button.sugar.php line:15 column:8', $message);
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
        );

        $message = $exception->getMessage();

        // Should have format: Message (template: path line:x column:y)
        $this->assertMatchesRegularExpression(
            '/Test error \(template: test\.sugar\.php line:10 column:5\)/',
            $message,
        );
    }
}
