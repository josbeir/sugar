<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Sugar\Context\CompilationContext;
use Sugar\Exception\SyntaxException;

/**
 * Test CompilationContext for template metadata and exception creation
 */
final class CompilationContextTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $context = new CompilationContext(
            templatePath: 'views/profile.sugar.php',
            source: '<div>test</div>',
            debug: true,
        );

        $this->assertSame('views/profile.sugar.php', $context->templatePath);
        $this->assertSame('<div>test</div>', $context->source);
        $this->assertTrue($context->debug);
    }

    public function testDebugDefaultsToFalse(): void
    {
        $context = new CompilationContext(
            templatePath: 'test.sugar.php',
            source: '<div>test</div>',
        );

        $this->assertFalse($context->debug);
    }

    public function testCreateExceptionWithSnippet(): void
    {
        $source = <<<'PHP'
<div s:if="$user">
    <p s:forech="$items">
        <?= $item ?>
    </p>
</div>
PHP;

        $context = new CompilationContext('test.sugar.php', $source);

        $exception = $context->createException(
            SyntaxException::class,
            'Invalid directive syntax',
            line: 2,
            column: 8,
        );

        $this->assertInstanceOf(SyntaxException::class, $exception);
        $this->assertSame('test.sugar.php', $exception->templatePath);
        $this->assertSame(2, $exception->templateLine);
        $this->assertSame(8, $exception->templateColumn);
        $this->assertNotNull($exception->snippet);

        // Verify snippet shows context
        $message = $exception->getMessage();
        $this->assertStringContainsString(' 1 | <div s:if="$user">', $message);
        $this->assertStringContainsString(' 2 |     <p s:forech="$items">', $message);
        $this->assertStringContainsString('^', $message); // Error pointer
        $this->assertStringContainsString(' 3 |         <?= $item ?>', $message);
    }

    public function testCreateExceptionWithoutLineOrColumn(): void
    {
        $context = new CompilationContext('test.sugar.php', '<div>test</div>');

        $exception = $context->createException(
            SyntaxException::class,
            'General syntax error',
        );

        $this->assertInstanceOf(SyntaxException::class, $exception);
        $this->assertSame('test.sugar.php', $exception->templatePath);
        $this->assertNull($exception->templateLine);
        $this->assertNull($exception->templateColumn);
        $this->assertNull($exception->snippet);
    }

    public function testCreateExceptionWithLineButNoColumn(): void
    {
        $source = '<div s:if="$test">content</div>';
        $context = new CompilationContext('test.sugar.php', $source);

        $exception = $context->createException(
            SyntaxException::class,
            'Error on line',
            line: 1,
        );

        $this->assertNull($exception->snippet); // No snippet without column
    }

    public function testCreateExceptionWithDifferentExceptionTypes(): void
    {
        $source = '<div>test</div>';
        $context = new CompilationContext('test.sugar.php', $source);

        $syntax = $context->createException(
            SyntaxException::class,
            'Syntax error',
            1,
            5,
        );
        $this->assertInstanceOf(SyntaxException::class, $syntax);
        $this->assertStringContainsString('Syntax error', $syntax->getMessage());
    }

    public function testGenerateSnippet(): void
    {
        $source = <<<'PHP'
line 1
line 2 error here
line 3
line 4
PHP;

        $context = new CompilationContext('test.sugar.php', $source);

        $snippet = $context->generateSnippet(line: 2, column: 8);

        $this->assertStringContainsString(' 1 | line 1', $snippet);
        $this->assertStringContainsString(' 2 | line 2 error here', $snippet);
        $this->assertStringContainsString('^', $snippet);
        $this->assertStringContainsString(' 3 | line 3', $snippet);
        $this->assertStringContainsString(' 4 | line 4', $snippet);
    }

    public function testGenerateSnippetWithCustomContextLines(): void
    {
        $source = implode("\n", [
            'line 1',
            'line 2',
            'line 3',
            'line 4 error',
            'line 5',
            'line 6',
            'line 7',
        ]);

        $context = new CompilationContext('test.sugar.php', $source);

        $snippet = $context->generateSnippet(line: 4, column: 8, contextLines: 1);

        // Should show only 1 line before and after
        $this->assertStringContainsString(' 3 | line 3', $snippet);
        $this->assertStringContainsString(' 4 | line 4 error', $snippet);
        $this->assertStringContainsString(' 5 | line 5', $snippet);

        // Should NOT show lines 2 and 6
        $this->assertStringNotContainsString(' 2 | line 2', $snippet);
        $this->assertStringNotContainsString(' 6 | line 6', $snippet);
    }

    public function testCreateExceptionPreservesExceptionMessage(): void
    {
        $context = new CompilationContext('test.sugar.php', '<div>test</div>');

        $exception = $context->createException(
            SyntaxException::class,
            'Custom error message',
            1,
            5,
        );

        $this->assertStringContainsString('Custom error message', $exception->getMessage());
    }

    public function testMultipleExceptionsFromSameContext(): void
    {
        $source = '<div s:if="$test">content</div>';
        $context = new CompilationContext('views/test.sugar.php', $source);

        $exception1 = $context->createException(
            SyntaxException::class,
            'First error',
            1,
            5,
        );

        $exception2 = $context->createException(
            SyntaxException::class,
            'Second error',
            1,
            10,
        );

        // Both should reference same template
        $this->assertSame('views/test.sugar.php', $exception1->templatePath);
        $this->assertSame('views/test.sugar.php', $exception2->templatePath);

        // But different column positions
        $this->assertSame(5, $exception1->templateColumn);
        $this->assertSame(10, $exception2->templateColumn);
    }
}
