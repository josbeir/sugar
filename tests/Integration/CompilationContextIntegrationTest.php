<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Exception\SyntaxException;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;

/**
 * Integration test: Verify CompilationContext creates exceptions with automatic snippets
 */
final class CompilationContextIntegrationTest extends TestCase
{
    use CompilerTestTrait;

    protected function setUp(): void
    {
        $this->setUpCompiler();
    }

    public function testExceptionIncludesSnippetOnSyntaxError(): void
    {
        $template = '<s-template s:class="\'invalid\'">Fragment with directive</s-template>';

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/cannot have attribute directives/i');

        try {
            $this->compiler->compile($template, 'test.sugar.php');
        } catch (SyntaxException $syntaxException) {
            // Assert exception includes snippet
            $exceptionString = (string)$syntaxException;
            $this->assertStringContainsString('s-template s:class', $exceptionString);
            $this->assertStringContainsString(' 1 |', $exceptionString); // Snippet line number format

            throw $syntaxException; // Re-throw for expectException
        }
    }

    public function testExceptionIncludesTemplatePathInMessage(): void
    {
        $template = '<s-template s:class="\'active\'">Fragment with directive</s-template>';

        $this->expectException(SyntaxException::class);

        try {
            $this->compiler->compile($template, 'layouts/sidebar.sugar.php');
        } catch (SyntaxException $syntaxException) {
            // Assert template path is in the exception message
            $exceptionString = (string)$syntaxException;
            $this->assertStringContainsString('layouts/sidebar.sugar.php', $exceptionString);
            $this->assertStringContainsString(' 1 |', $exceptionString); // Snippet line number format

            throw $syntaxException; // Re-throw for expectException
        }
    }

    public function testInlineTemplateUsesDefaultPath(): void
    {
        $template = '<s-template s:class="\'test\'">Fragment</s-template>';

        $this->expectException(SyntaxException::class);

        try {
            $this->compiler->compile($template); // No path provided
        } catch (SyntaxException $syntaxException) {
            // Should use default 'inline-template' path
            $exceptionString = (string)$syntaxException;
            $this->assertStringContainsString('inline-template', $exceptionString);

            throw $syntaxException; // Re-throw for expectException
        }
    }
}
