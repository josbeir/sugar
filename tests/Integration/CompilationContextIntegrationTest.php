<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;

/**
 * Integration test: Verify CompilationContext creates exceptions with template metadata
 */
final class CompilationContextIntegrationTest extends TestCase
{
    use CompilerTestTrait;

    protected function setUp(): void
    {
        $this->setUpCompiler();
    }

    public function testExceptionIncludesLocationOnSyntaxError(): void
    {
        $template = '<s-template s:class="\'invalid\'">Fragment with directive</s-template>';

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/cannot have attribute directives/i');

        try {
            $this->compiler->compile($template, 'test.sugar.php');
        } catch (SyntaxException $syntaxException) {
            // Assert exception includes location metadata
            $exceptionString = (string)$syntaxException;
            $this->assertStringContainsString('template: test.sugar.php', $exceptionString);

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

    public function testIncludeExceptionUsesIncludedTemplatePath(): void
    {
        $this->setUpCompilerWithStringLoader(
            templates: [
                'pages/home.sugar.php' => '<div s:include="../partials/bad.sugar.php"></div>',
                'partials/bad.sugar.php' => '<s-template s:class="\'oops\'"></s-template>',
            ],
        );

        $this->expectException(SyntaxException::class);

        try {
            $this->compiler->compile(
                $this->templateLoader->load('pages/home.sugar.php'),
                'pages/home.sugar.php',
            );
        } catch (SyntaxException $syntaxException) {
            $exceptionString = (string)$syntaxException;
            $this->assertStringContainsString('template: partials/bad.sugar.php', $exceptionString);

            throw $syntaxException;
        }
    }

    public function testComponentExceptionUsesComponentTemplatePath(): void
    {
        $this->setUpCompilerWithStringLoader(
            components: [
                'widget' => '<s-template s:class="\'oops\'"></s-template>',
            ],
        );

        $this->expectException(SyntaxException::class);

        try {
            $this->compiler->compile('<s-widget></s-widget>', 'pages/home.sugar.php');
        } catch (SyntaxException $syntaxException) {
            $exceptionString = (string)$syntaxException;
            $this->assertStringContainsString('template: components/s-widget.sugar.php', $exceptionString);

            throw $syntaxException;
        }
    }
}
