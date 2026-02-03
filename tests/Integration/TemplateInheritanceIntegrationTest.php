<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Compiler;
use Sugar\Escape\Escaper;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\TemplateInheritance\FileTemplateLoader;
use Sugar\Tests\TemplateTestHelperTrait;

final class TemplateInheritanceIntegrationTest extends TestCase
{
    use TemplateTestHelperTrait;

    private Compiler $compiler;

    private string $templatesPath;

    protected function setUp(): void
    {
        $this->templatesPath = __DIR__ . '/../fixtures/templates/template-inheritance';
        $loader = new FileTemplateLoader($this->templatesPath);

        $this->compiler = new Compiler(
            parser: new Parser(),
            contextPass: new ContextAnalysisPass(),
            escaper: new Escaper(),
            templateLoader: $loader,
        );
    }

    public function testSimpleInheritanceWithBlockReplacement(): void
    {
        $template = $this->loadTemplate('template-inheritance/simple-child.sugar.php');
        $compiled = $this->compiler->compile($template, 'pages/home.sugar.php');

        // Verify compiled output contains the block content from child
        $this->assertStringContainsString('My Page Title', $compiled);
        $this->assertStringContainsString('Welcome', $compiled);
        $this->assertStringContainsString('This is the page content', $compiled);
    }

    public function testMultiLevelInheritance(): void
    {
        $template = $this->loadTemplate('template-inheritance/multilevel-child.sugar.php');
        $compiled = $this->compiler->compile($template, 'pages/home.sugar.php');

        // Should have grandparent structure with child's block content
        $this->assertStringContainsString('<html>', $compiled);
        $this->assertStringContainsString('Final Page', $compiled);
        $this->assertStringContainsString('Master Body', $compiled); // Body block not overridden, so keeps grandparent content
        $this->assertStringNotContainsString('App Layout', $compiled); // Parent's title was overridden
    }

    public function testIncludeWithOpenScope(): void
    {
        $template = $this->loadTemplate('template-inheritance/include-test.sugar.php');
        $compiled = $this->compiler->compile($template, 'home.sugar.php');

        // Variable should be accessible in included template (check for escaping, not specific format)
        $this->assertStringContainsString('$title', $compiled);
        $this->assertStringContainsString('htmlspecialchars', $compiled);
    }

    public function testCombiningInheritanceWithDirectives(): void
    {
        $template = $this->loadTemplate('template-inheritance/directive-combo.sugar.php');
        $compiled = $this->compiler->compile($template, 'pages/list.sugar.php');

        // Should have foreach directive compiled
        $this->assertStringContainsString('foreach', $compiled);
        $this->assertStringContainsString('$items as $item', $compiled);
    }

    public function testNestedIncludes(): void
    {
        $template = $this->loadTemplate('template-inheritance/nested-include.sugar.php');
        $compiled = $this->compiler->compile($template, 'home.sugar.php');

        // Both templates should be included
        $this->assertStringContainsString('<nav>', $compiled);
        $this->assertStringContainsString('$url', $compiled);
        $this->assertStringContainsString('$label', $compiled);
    }

    public function testInheritancePreservesContextAwareEscaping(): void
    {
        $template = $this->loadTemplate('template-inheritance/escaping-test.sugar.php');
        $compiled = $this->compiler->compile($template, 'pages/home.sugar.php');

        // HTML context escaping should be applied
        $this->assertStringContainsString('htmlspecialchars', $compiled);
        $this->assertStringContainsString('$pageTitle', $compiled);
        $this->assertStringContainsString('$heading', $compiled);
    }

    public function testExecuteCompiledInheritedTemplate(): void
    {
        $template = $this->loadTemplate('template-inheritance/execution-test.sugar.php');
        $compiled = $this->compiler->compile($template, 'pages/home.sugar.php');

        // Execute the compiled template
        $variables = ['name' => 'World'];
        extract($variables);
        ob_start();
        // phpcs:ignore Squiz.PHP.Eval.Discouraged
        eval('?>' . $compiled);
        $output = ob_get_clean();

        $this->assertIsString($output);

        // Verify rendered output
        $this->assertStringContainsString('<title>Test Page</title>', $output);
        $this->assertStringContainsString('<h1>Hello World</h1>', $output);
    }

    public function testSupportsExtensionlessTemplatePaths(): void
    {
        $template = $this->loadTemplate('template-inheritance/extensionless-test.sugar.php');
        $compiled = $this->compiler->compile($template, 'pages/test.sugar.php');

        // Should successfully resolve "base" to "base.sugar.php" and "partials/header" to "partials/header.sugar.php"
        $this->assertStringContainsString('Extension-less Test', $compiled);
        $this->assertStringContainsString('This template uses extension-less includes', $compiled);
    }
}
