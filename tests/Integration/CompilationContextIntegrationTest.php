<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\EngineTestTrait;

/**
 * Integration test: Verify CompilationContext creates exceptions with template metadata
 */
final class CompilationContextIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use EngineTestTrait;

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

    /**
     * Verify that exceptions from included templates reference the included template path.
     *
     * With runtime includes, the included template is compiled at render time.
     * The SyntaxException should still reference the included template's path.
     */
    public function testIncludeExceptionUsesIncludedTemplatePath(): void
    {
        $engine = $this->createStringEngine([
            'pages/home.sugar.php' => '<div s:include="../partials/bad.sugar.php"></div>',
            'partials/bad.sugar.php' => '<s-template s:class="\'oops\'"></s-template>',
        ]);

        $this->expectException(SyntaxException::class);

        try {
            $engine->render('pages/home.sugar.php');
        } catch (SyntaxException $syntaxException) {
            $exceptionString = (string)$syntaxException;
            $this->assertStringContainsString('partials/bad.sugar.php', $exceptionString);

            throw $syntaxException;
        }
    }

    public function testComponentExceptionUsesComponentTemplatePath(): void
    {
        $this->setUpCompilerWithStringLoader(
            templates: [
                'components/s-widget.sugar.php' => '<s-template s:class="\'oops\'"></s-template>',
            ],
        );

        $this->expectException(SyntaxException::class);

        try {
            $this->compiler->compile('<s-widget></s-widget>', 'pages/home.sugar.php');
        } catch (SyntaxException $syntaxException) {
            $exceptionString = (string)$syntaxException;
            $this->assertStringContainsString('template: @app/components/s-widget', $exceptionString);

            throw $syntaxException;
        }
    }
}
