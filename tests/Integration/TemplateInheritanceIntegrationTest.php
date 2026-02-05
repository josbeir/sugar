<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Compiler;
use Sugar\Config\SugarConfig;
use Sugar\Escape\Escaper;
use Sugar\Parser\Parser;
use Sugar\TemplateInheritance\FileTemplateLoader;
use Sugar\Tests\ExecuteTemplateTrait;
use Sugar\Tests\TemplateTestHelperTrait;

final class TemplateInheritanceIntegrationTest extends TestCase
{
    use ExecuteTemplateTrait;
    use TemplateTestHelperTrait;

    private Compiler $compiler;

    private string $templatesPath;

    protected function setUp(): void
    {
        $this->templatesPath = SUGAR_TEST_TEMPLATE_INHERITANCE_PATH;
        $loader = new FileTemplateLoader((new SugarConfig())->withTemplatePaths($this->templatesPath));

        $this->compiler = new Compiler(
            parser: new Parser(),
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
        $output = $this->executeTemplate($compiled, ['name' => 'World']);

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

    public function testIncludeWithWithHasIsolatedScope(): void
    {
        // Create temporary included template
        $includePath = $this->templatesPath . '/partials/temp-isolated.sugar.php';
        file_put_contents($includePath, '<div class="alert"><?= $message ?></div>');

        try {
            $template = '<?php $message = "parent message"; ?>' .
                '<div s:include="partials/temp-isolated.sugar.php" s:with="[\'message\' => \'included message\']"></div>' .
                '<p><?= $message ?></p>';

            $compiled = $this->compiler->compile($template, 'home.sugar.php');

            // Should contain closure for isolation
            $this->assertStringContainsString('(function($__vars) { extract($__vars);', $compiled);
            $this->assertStringContainsString("})(['message' => 'included message']);", $compiled);

            // Execute to verify isolation
            $output = $this->executeTemplate($compiled, ['message' => 'parent message']);

            // Verify included template got isolated variable
            $this->assertStringContainsString('<div class="alert">included message</div>', $output);
            // Verify parent variable not overwritten
            $this->assertStringContainsString('<p>parent message</p>', $output);
        } finally {
            if (file_exists($includePath)) {
                unlink($includePath);
            }
        }
    }
}
