<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

final class TemplateInheritanceIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use ExecuteTemplateTrait;
    use TemplateTestHelperTrait;

    private string $templatesPath;

    protected function setUp(): void
    {
        $this->templatesPath = SUGAR_TEST_TEMPLATE_INHERITANCE_PATH;
        $config = new SugarConfig();

        $this->setUpCompiler(
            config: $config,
            withTemplateLoader: true,
            templatePaths: [$this->templatesPath],
        );
    }

    public function testSimpleInheritanceWithBlockReplacement(): void
    {
        $template = $this->loadTemplate('template-inheritance/simple-child.sugar.php');
        $compiled = $this->compiler->compile($template, 'simple-child.sugar.php');

        // Verify compiled output contains the block content from child
        $this->assertStringContainsString('My Page Title', $compiled);
        $this->assertStringContainsString('Welcome', $compiled);
        $this->assertStringContainsString('This is the page content', $compiled);
    }

    public function testAbsoluteOnlyResolutionUsesTemplateRoot(): void
    {
        $config = new SugarConfig();
        $this->setUpCompiler(
            config: $config,
            withTemplateLoader: true,
            templatePaths: [$this->templatesPath],
            absolutePathsOnly: true,
        );

        $template = $this->loadTemplate('template-inheritance/absolute-only-child.sugar.php');
        $compiled = $this->compiler->compile($template, 'absolute-only-child.sugar.php');

        $this->assertStringContainsString('My Absolute Page Title', $compiled);
        $this->assertStringContainsString('Absolute Welcome', $compiled);
        $this->assertStringContainsString('Absolute content.', $compiled);
    }

    public function testMultiLevelInheritance(): void
    {
        $template = $this->loadTemplate('template-inheritance/multilevel-child.sugar.php');
        $compiled = $this->compiler->compile($template, 'multilevel-child.sugar.php');

        // Should have grandparent structure with child's block content
        $this->assertStringContainsString('<html>', $compiled);
        $this->assertStringContainsString('Final Page', $compiled);
        $this->assertStringContainsString('Master Body', $compiled); // Body block not overridden, so keeps grandparent content
        $this->assertStringNotContainsString('App Layout', $compiled); // Parent's title was overridden
    }

    public function testAppendBlockContent(): void
    {
        $template = $this->loadTemplate('template-inheritance/append-child.sugar.php');
        $compiled = $this->compiler->compile($template, 'append-child.sugar.php');

        $basePos = strpos($compiled, 'Base content');
        $extraPos = strpos($compiled, 'Extra');

        $this->assertNotFalse($basePos);
        $this->assertNotFalse($extraPos);
        $this->assertLessThan($extraPos, $basePos);
    }

    public function testPrependBlockContent(): void
    {
        $template = $this->loadTemplate('template-inheritance/prepend-child.sugar.php');
        $compiled = $this->compiler->compile($template, 'prepend-child.sugar.php');

        $basePos = strpos($compiled, 'Base content');
        $extraPos = strpos($compiled, 'Extra');

        $this->assertNotFalse($basePos);
        $this->assertNotFalse($extraPos);
        $this->assertGreaterThan($extraPos, $basePos);
    }

    public function testIncludeWithOpenScope(): void
    {
        $template = $this->loadTemplate('template-inheritance/include-test.sugar.php');
        $compiled = $this->compiler->compile($template, 'home.sugar.php');

        // Variable should be accessible in included template (check for escaping, not specific format)
        $this->assertStringContainsString('$title', $compiled);
        $this->assertStringContainsString('Escaper::html', $compiled);
    }

    public function testCombiningInheritanceWithDirectives(): void
    {
        $template = $this->loadTemplate('template-inheritance/directive-combo.sugar.php');
        $compiled = $this->compiler->compile($template, 'directive-combo.sugar.php');

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
        $compiled = $this->compiler->compile($template, 'escaping-test.sugar.php');

        // HTML context escaping should be applied
        $this->assertStringContainsString('Escaper::html', $compiled);
        $this->assertStringContainsString('$pageTitle', $compiled);
        $this->assertStringContainsString('$heading', $compiled);
    }

    public function testExecuteCompiledInheritedTemplate(): void
    {
        $template = $this->loadTemplate('template-inheritance/execution-test.sugar.php');
        $compiled = $this->compiler->compile($template, 'execution-test.sugar.php');

        // Execute the compiled template
        $output = $this->executeTemplate($compiled, ['name' => 'World']);

        // Verify rendered output
        $this->assertStringContainsString('<title>Test Page</title>', $output);
        $this->assertStringContainsString('<h1>Hello World</h1>', $output);
    }

    public function testSupportsExtensionlessTemplatePaths(): void
    {
        $template = $this->loadTemplate('template-inheritance/extensionless-test.sugar.php');
        $compiled = $this->compiler->compile($template, 'extensionless-test.sugar.php');

        // Should successfully resolve "base" to "base.sugar.php" and "partials/header" to "partials/header.sugar.php"
        $this->assertStringContainsString('Extension-less Test', $compiled);
        $this->assertStringContainsString('This template uses extension-less includes', $compiled);
    }

    public function testIncludeWithWithHasIsolatedScope(): void
    {
        $this->setUpCompilerWithStringLoader(
            templates: [
                'partials/temp-isolated.sugar.php' => '<div class="alert"><?= $message ?></div>',
            ],
            config: new SugarConfig(),
        );

        $template = '<?php $message = "parent message"; ?>' .
            '<div s:include="partials/temp-isolated.sugar.php" s:with="[\'message\' => \'included message\']"></div>' .
            '<p><?= $message ?></p>';

        $compiled = $this->compiler->compile($template, 'home.sugar.php');

        // Should contain closure for isolation with bindTo and type hints
        $this->assertStringContainsString('(function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);', $compiled);
        $this->assertStringContainsString('return ob_get_clean(); })->bindTo($this ?? null)', $compiled);
        $this->assertStringContainsString("(['message' => 'included message']);", $compiled);

        // Execute to verify isolation
        $output = $this->executeTemplate($compiled, ['message' => 'parent message']);

        // Verify included template got isolated variable
        $this->assertStringContainsString('<div class="alert">included message</div>', $output);
        // Verify parent variable not overwritten
        $this->assertStringContainsString('<p>parent message</p>', $output);
    }

    public function testThrowsExceptionWhenTemplateHasMultipleExtendsDirectives(): void
    {
        $this->setUpCompilerWithStringLoader(
            templates: [
                'layout/default.sugar.php' => '<html><body><main s:block="content">Default</main></body></html>',
                'pages/home.sugar.php' => <<<'SUGAR'
<s-template s:extends="layout/default" />
<s-template s:extends="layout/default" />

<s-template s:block="content">
    <p>hello world</p>
</s-template>
SUGAR,
            ],
            config: new SugarConfig(),
        );

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Only one s:extends directive is allowed per template.');

        $this->compiler->compile(
            $this->templateLoader->load('pages/home.sugar.php'),
            'pages/home.sugar.php',
        );
    }
}
