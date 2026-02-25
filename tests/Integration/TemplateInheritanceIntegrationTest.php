<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\EngineTestTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Integration tests for template inheritance via the runtime rendering system.
 *
 * Tests verify that s:extends, s:block, s:append, s:prepend, s:parent, and s:include
 * produce the correct rendered output via BlockManager and TemplateRenderer.
 */
final class TemplateInheritanceIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use EngineTestTrait;
    use TemplateTestHelperTrait;

    private string $templatesPath;

    protected function setUp(): void
    {
        $this->templatesPath = SUGAR_TEST_TEMPLATE_INHERITANCE_PATH;
    }

    public function testSimpleInheritanceWithBlockReplacement(): void
    {
        $engine = $this->createEngine($this->templatesPath);
        $result = $engine->render('simple-child.sugar.php');

        // Child blocks should replace parent blocks
        $this->assertStringContainsString('My Page Title', $result);
        $this->assertStringContainsString('Welcome', $result);
        $this->assertStringContainsString('This is the page content', $result);
        // Parent structure preserved
        $this->assertStringContainsString('<html>', $result);
        $this->assertStringContainsString('</html>', $result);
    }

    public function testAbsoluteOnlyResolutionUsesTemplateRoot(): void
    {
        $engine = $this->createEngine($this->templatesPath);
        $result = $engine->render('absolute-only-child.sugar.php');

        $this->assertStringContainsString('My Absolute Page Title', $result);
        $this->assertStringContainsString('Absolute Welcome', $result);
        $this->assertStringContainsString('Absolute content.', $result);
    }

    public function testMultiLevelInheritance(): void
    {
        $engine = $this->createEngine($this->templatesPath);
        $result = $engine->render('multilevel-child.sugar.php');

        // Should have grandparent structure with child's block content
        $this->assertStringContainsString('<html>', $result);
        $this->assertStringContainsString('Final Page', $result);
        $this->assertStringContainsString('Master Body', $result);
        $this->assertStringNotContainsString('App Layout', $result);
    }

    public function testAppendBlockContent(): void
    {
        $engine = $this->createEngine($this->templatesPath);
        $result = $engine->render('append-child.sugar.php');

        $basePos = strpos($result, 'Base content');
        $extraPos = strpos($result, 'Extra');

        $this->assertNotFalse($basePos);
        $this->assertNotFalse($extraPos);
        $this->assertLessThan($extraPos, $basePos);
    }

    public function testPrependBlockContent(): void
    {
        $engine = $this->createEngine($this->templatesPath);
        $result = $engine->render('prepend-child.sugar.php');

        $basePos = strpos($result, 'Base content');
        $extraPos = strpos($result, 'Extra');

        $this->assertNotFalse($basePos);
        $this->assertNotFalse($extraPos);
        $this->assertGreaterThan($extraPos, $basePos);
    }

    public function testBlockWithParentPlaceholderAppendsParentContent(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'base.sugar.php' => '<main s:block="content"><p>Base content</p></main>',
                'child.sugar.php' => '<s-template s:extends="base.sugar.php"></s-template>' .
                    '<s-template s:block="content"><s-template s:parent /><p>Child content</p></s-template>',
            ],
        );

        $result = $engine->render('child.sugar.php');

        $basePos = strpos($result, 'Base content');
        $childPos = strpos($result, 'Child content');

        $this->assertNotFalse($basePos);
        $this->assertNotFalse($childPos);
        $this->assertLessThan($childPos, $basePos);
    }

    public function testBlockWithParentPlaceholderPrependsParentContent(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'base.sugar.php' => '<main s:block="content"><p>Base content</p></main>',
                'child.sugar.php' => '<s-template s:extends="base.sugar.php"></s-template>' .
                    '<s-template s:block="content"><p>Child content</p><s-template s:parent /></s-template>',
            ],
        );

        $result = $engine->render('child.sugar.php');

        $basePos = strpos($result, 'Base content');
        $childPos = strpos($result, 'Child content');

        $this->assertNotFalse($basePos);
        $this->assertNotFalse($childPos);
        $this->assertLessThan($basePos, $childPos);
    }

    public function testBlockWithParentPlaceholderCanPlaceParentContentInMiddle(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'base.sugar.php' => '<main s:block="content"><p>Base content</p></main>',
                'child.sugar.php' => '<s-template s:extends="base.sugar.php"></s-template>' .
                    '<s-template s:block="content"><p>Before</p><s-template s:parent /><p>After</p></s-template>',
            ],
        );

        $result = $engine->render('child.sugar.php');

        $beforePos = strpos($result, 'Before');
        $basePos = strpos($result, 'Base content');
        $afterPos = strpos($result, 'After');

        $this->assertNotFalse($beforePos);
        $this->assertNotFalse($basePos);
        $this->assertNotFalse($afterPos);
        $this->assertLessThan($basePos, $beforePos);
        $this->assertLessThan($afterPos, $basePos);
    }

    public function testDuplicateChildBlockDefinitionsThrowSyntaxError(): void
    {
        $config = new SugarConfig();
        $this->setUpCompilerWithStringLoader(
            templates: [
                'base.sugar.php' => '<main s:block="content"><p>Base content</p></main>',
            ],
            config: $config,
        );

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Block "content" is defined multiple times in the same child template.');

        $this->compiler->compile(
            '<s-template s:extends="base.sugar.php"></s-template>' .
            '<s-template s:block="content"><p>Child content</p></s-template>' .
            '<s-template s:append="content"><p>Extra content</p></s-template>',
            'child.sugar.php',
        );
    }

    public function testParentPlaceholderOutsideBlockThrowsSyntaxError(): void
    {
        $config = new SugarConfig();
        $this->setUpCompilerWithStringLoader(
            templates: [
                'base.sugar.php' => '<main s:block="content"><p>Base content</p></main>',
            ],
            config: $config,
        );

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:parent is only allowed inside s:block.');

        $this->compiler->compile(
            '<s-template s:extends="base.sugar.php"></s-template>' .
            '<s-template s:parent />' .
            '<s-template s:block="content"><p>Child content</p></s-template>',
            'child.sugar.php',
        );
    }

    public function testIncludeWithOpenScope(): void
    {
        $engine = $this->createEngine($this->templatesPath);
        $result = $engine->render('include-test.sugar.php');

        // Included partial should render with parent scope variables
        $this->assertStringContainsString('<header>', $result);
        $this->assertStringContainsString('My Site', $result);
    }

    public function testCombiningInheritanceWithDirectives(): void
    {
        $engine = $this->createEngine($this->templatesPath);
        $result = $engine->render('directive-combo.sugar.php', ['items' => ['foo', 'bar', 'baz']]);

        // Should render foreach inside inherited block
        $this->assertStringContainsString('<li>foo</li>', $result);
        $this->assertStringContainsString('<li>bar</li>', $result);
        $this->assertStringContainsString('<li>baz</li>', $result);
    }

    public function testNestedIncludes(): void
    {
        $engine = $this->createEngine($this->templatesPath);
        $result = $engine->render('nested-include.sugar.php', ['url' => '/home', 'label' => 'Home']);

        // Navigation should include the nav-item partial
        $this->assertStringContainsString('<nav>', $result);
        $this->assertStringContainsString('/home', $result);
        $this->assertStringContainsString('Home', $result);
    }

    public function testInheritancePreservesContextAwareEscaping(): void
    {
        $engine = $this->createEngine($this->templatesPath);
        $result = $engine->render('escaping-test.sugar.php', [
            'pageTitle' => '<script>alert("XSS")</script>',
            'heading' => '<b>Hello</b>',
            'content' => 'Safe content',
        ]);

        // HTML escaping should be applied in the rendered output
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('&lt;b&gt;Hello&lt;/b&gt;', $result);
        $this->assertStringContainsString('Safe content', $result);
    }

    public function testInheritancePreservesAttributeEscapingInBlockContent(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'base.sugar.php' => '<main s:block="content"></main>',
                'child.sugar.php' => '<s-template s:extends="base.sugar.php"></s-template>' .
                    '<s-template s:block="content">' .
                    '<img class="home-logo" src="/images/logo.svg" alt="<?= $siteName ?>" />' .
                    '</s-template>',
            ],
        );

        $result = $engine->render('child.sugar.php', ['siteName' => 'My "Site" <script>']);

        $this->assertStringContainsString('alt="My &quot;Site&quot; &lt;script&gt;"', $result);
    }

    public function testExecuteCompiledInheritedTemplate(): void
    {
        $engine = $this->createEngine($this->templatesPath);
        $output = $engine->render('execution-test.sugar.php', ['name' => 'World']);

        // Verify rendered output
        $this->assertStringContainsString('<title>Test Page</title>', $output);
        $this->assertStringContainsString('<h1>Hello World</h1>', $output);
    }

    public function testSupportsExtensionlessTemplatePaths(): void
    {
        $engine = $this->createEngine($this->templatesPath);
        $result = $engine->render('extensionless-test.sugar.php', ['title' => 'Site Title']);

        // Should resolve extension-less paths and render correctly
        $this->assertStringContainsString('Extension-less Test', $result);
        $this->assertStringContainsString('This template uses extension-less includes', $result);
    }

    public function testIncludeWithWithHasIsolatedScope(): void
    {
        $engine = $this->createStringEngine(
            templates: [
                'partials/temp-isolated.sugar.php' => '<div class="alert"><?= $message ?></div>',
                'home.sugar.php' => '<?php $message = "parent message"; ?>' .
                    '<div s:include="partials/temp-isolated.sugar.php" s:with="[\'message\' => \'included message\']"></div>' .
                    '<p><?= $message ?></p>',
            ],
        );

        $result = $engine->render('home.sugar.php');

        // Verify included template got isolated variable
        $this->assertStringContainsString('included message', $result);
        // Verify parent variable not overwritten
        $this->assertStringContainsString('<p>parent message</p>', $result);
    }
}
