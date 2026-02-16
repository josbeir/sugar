<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;
use Sugar\Extension\FragmentCache\FragmentCacheExtension;
use Sugar\Tests\Helper\Stub\ArraySimpleCache;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Integration test: Full component compilation pipeline
 * Parser → ComponentExpansionPass → DirectiveExtractionPass → ... → CodeGenerator
 */
final class ComponentIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use ExecuteTemplateTrait;
    use TempDirectoryTrait;
    use TemplateTestHelperTrait;

    private SugarConfig $config;

    protected function setUp(): void
    {
        $templatesPath = SUGAR_TEST_TEMPLATES_PATH;
        $this->config = new SugarConfig();

        $this->setUpCompiler(
            config: $this->config,
            withTemplateLoader: true,
            templatePaths: [$templatesPath],
            componentPaths: ['components'],
        );
    }

    public function testCompilesSimpleComponent(): void
    {
        $template = '<s-button>Click me</s-button>';

        $compiled = $this->compiler->compile($template);

        // Should use closure with ob_start/ob_get_clean pattern (same as main templates)
        $this->assertStringContainsString('(function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);', $compiled);
        $this->assertStringContainsString('return ob_get_clean();', $compiled);
        $this->assertStringContainsString("'slot' => 'Click me'", $compiled);

        // Should have button template HTML
        $this->assertStringContainsString('<button class="btn">', $compiled);
        // Slots are output with raw now
        $this->assertStringContainsString('echo $slot;', $compiled);
    }

    public function testCompilesComponentWithAttributes(): void
    {
        // Use s:bind to pass component variables
        $template = '<s-alert s:bind="[\'title\' => \'Important\', \'type\' => \'warning\']">Important message</s-alert>';

        $compiled = $this->compiler->compile($template);

        // Should use closure with extract and pass variables in array
        $this->assertStringContainsString('(function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);', $compiled);
        $this->assertStringContainsString('return ob_get_clean();', $compiled);
        $this->assertStringContainsString('->bindTo($this ?? null)', $compiled);
        $this->assertStringContainsString("'type' => 'warning'", $compiled);
        $this->assertStringContainsString("'title' => 'Important'", $compiled);
        $this->assertStringContainsString("'slot' => 'Important message'", $compiled);

        // Should have alert template structure
        $this->assertStringContainsString('<div class="alert alert-info">', $compiled);
        // Title uses the prop
        $this->assertStringContainsString(Escaper::class . '::html($title ?? \'Notice\')', $compiled);
    }

    public function testCompilesComponentWithNamedSlots(): void
    {
        $template = '<div class="bla"></div><s-card>' .
            '<div s:slot="header">Card Title</div>' .
            '<p>Card body content</p>' .
            '<span s:slot="footer">Footer text</span>' .
            '</s-card>';

        $compiled = $this->compiler->compile($template);

        // Should use closure with extract
        $this->assertStringContainsString('(function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);', $compiled);
        $this->assertStringContainsString('return ob_get_clean();', $compiled);
        // Should have named slots in array
        $this->assertStringContainsString("'header' =>", $compiled);
        $this->assertStringContainsString('Card Title', $compiled);
        $this->assertStringContainsString("'footer' =>", $compiled);
        $this->assertStringContainsString('Footer text', $compiled);

        // Should have default slot in array
        $this->assertStringContainsString("'slot' =>", $compiled);
        $this->assertStringContainsString('Card body content', $compiled);

        // Should have card template structure
        $this->assertStringContainsString('<div class="card">', $compiled);
        $this->assertStringContainsString('card-header', $compiled);
        $this->assertStringContainsString('card-body', $compiled);
    }

    public function testCompilesNestedComponents(): void
    {
        $template = '<s-card>' .
            '<div s:slot="header"><s-button>Action</s-button></div>' .
            '<p>Content</p>' .
            '</s-card>';

        $compiled = $this->compiler->compile($template);

        // Should have both components expanded
        $this->assertStringContainsString('<div class="card">', $compiled);
        $this->assertStringContainsString('<button class="btn">', $compiled);

        // Should use closure for isolation (nested components each get their own closure)
        $this->assertStringContainsString('(function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);', $compiled);
        $this->assertStringContainsString('return ob_get_clean();', $compiled);
        $this->assertStringContainsString("'slot' =>", $compiled);
    }

    public function testEngineResolvesComponentsWithAbsolutePathsOnlyAndInheritance(): void
    {
        $basePath = $this->createTempDir('sugar_templates_');
        $cachePath = $this->createTempDir('sugar_cache_');

        mkdir($basePath . '/layout', 0777, true);
        mkdir($basePath . '/Pages', 0777, true);
        mkdir($basePath . '/components', 0777, true);

        file_put_contents(
            $basePath . '/layout/default.sugar.php',
            '<html><body><main s:block="content">Default</main></body></html>',
        );

        file_put_contents(
            $basePath . '/components/s-button.sugar.php',
            '<button class="btn"><?= $slot ?></button>',
        );

        file_put_contents(
            $basePath . '/Pages/home.sugar.php',
            <<<'SUGAR'
<s-template s:extends="layout/default" />
<s-template s:extends="layout/default" />

<s-template s:block="content">
    <p>hello world</p>
    <h1>Cool title</h1>
    <div s:ifcontent>
        <?= 'bla' ?>
        <s-button s:class="['btn', 'btn-primary']">Click me!</s-button>
    </div>
    <s-button s:class="['btn', 'btn-primary']">Click me!</s-button>
</s-template>
SUGAR,
        );

        $loader = new FileTemplateLoader(
            config: new SugarConfig(),
            templatePaths: rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            absolutePathsOnly: true,
        );

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->withCache(new FileCache($cachePath))
            ->withTemplateContext($this)
            ->withPhpSyntaxValidation(true)
            ->withDebug(false)
            ->withExtension(new ComponentExtension())
            ->build();

        $output = $engine->render('Pages/home.sugar.php');

        $this->assertStringContainsString('Click me!', $output);
        $this->assertStringContainsString('<button class="btn btn-primary">', $output);
        $this->assertStringContainsString('<p>hello world</p>', $output);
        $this->assertStringContainsString('<h1>Cool title</h1>', $output);
    }

    public function testEnginePreservesClassAttributesForIfContentComponent(): void
    {
        $basePath = $this->createTempDir('sugar_templates_');
        $cachePath = $this->createTempDir('sugar_cache_');

        mkdir($basePath . '/Pages', 0777, true);
        mkdir($basePath . '/components', 0777, true);

        file_put_contents(
            $basePath . '/components/s-button.sugar.php',
            <<<'SUGAR'
<button class="button" s:class="['bla']" s:ifcontent>
    <?= 'bla' ?>
</button>
SUGAR,
        );

        file_put_contents(
            $basePath . '/Pages/home.sugar.php',
            <<<'SUGAR'
<s-button s:class="['btn', 'btn-primary']">Click me!</s-button>
SUGAR,
        );

        $loader = new FileTemplateLoader(
            config: new SugarConfig(),
            templatePaths: rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            absolutePathsOnly: true,
        );

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->withCache(new FileCache($cachePath))
            ->withTemplateContext($this)
            ->withPhpSyntaxValidation(true)
            ->withDebug(false)
            ->withExtension(new ComponentExtension())
            ->build();

        $output = $engine->render('Pages/home.sugar.php');

        $this->assertStringContainsString('<button class="', $output);
        $this->assertStringContainsString('button', $output);
        $this->assertStringContainsString('bla', $output);
        $this->assertStringContainsString('btn', $output);
        $this->assertStringContainsString('btn-primary', $output);
        $this->assertStringContainsString('>    bla</button>', $output);
    }

    public function testEngineResolvesComponentsWithFragmentCacheAndAbsolutePathsOnly(): void
    {
        $basePath = $this->createTempDir('sugar_templates_');
        $cachePath = $this->createTempDir('sugar_cache_');

        mkdir($basePath . '/layout', 0777, true);
        mkdir($basePath . '/Pages', 0777, true);
        mkdir($basePath . '/components', 0777, true);

        file_put_contents(
            $basePath . '/layout/default.sugar.php',
            '<html><body><main s:block="content">Default</main></body></html>',
        );

        file_put_contents(
            $basePath . '/components/s-button.sugar.php',
            '<button class="btn"><?= $slot ?></button>',
        );

        file_put_contents(
            $basePath . '/Pages/home.sugar.php',
            <<<'SUGAR'
<s-template s:extends="layout/default" />

<s-template s:block="content">
    <div s:cache>
        <s-button s:class="['btn', 'btn-primary']">Click me!</s-button>
    </div>
</s-template>
SUGAR,
        );

        $loader = new FileTemplateLoader(
            config: new SugarConfig(),
            templatePaths: rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            absolutePathsOnly: true,
        );

        $engine = Engine::builder(new SugarConfig())
            ->withTemplateLoader($loader)
            ->withCache(new FileCache($cachePath))
            ->withTemplateContext($this)
            ->withPhpSyntaxValidation(true)
            ->withDebug(false)
            ->withExtension(new FragmentCacheExtension(new ArraySimpleCache()))
            ->withExtension(new ComponentExtension())
            ->build();

        $firstOutput = $engine->render('Pages/home.sugar.php');
        $secondOutput = $engine->render('Pages/home.sugar.php');

        $this->assertStringContainsString('Click me!', $firstOutput);
        $this->assertStringContainsString('<button class="btn btn-primary">', $firstOutput);
        $this->assertStringContainsString('Click me!', $secondOutput);
        $this->assertStringContainsString('<button class="btn btn-primary">', $secondOutput);
    }

    public function testComponentsWorkWithDirectives(): void
    {
        // Test s:if directive on component
        $template = '<s-button s:if="$showButton">Save</s-button>';

        $compiled = $this->compiler->compile($template);

        // Should use closure with extract
        $this->assertStringContainsString('(function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);', $compiled);
        $this->assertStringContainsString('return ob_get_clean();', $compiled);
        $this->assertStringContainsString("'slot' => 'Save'", $compiled);
        $this->assertStringContainsString('<button class="btn">', $compiled);

        // Should compile s:if directive
        $this->assertStringContainsString('<?php if ($showButton): ?>', $compiled);
        $this->assertStringContainsString('<?php endif; ?>', $compiled);

        // Test execution
        $output = $this->executeTemplate($compiled, ['showButton' => true]);
        $this->assertStringContainsString('<button class="btn">Save</button>', $output);

        // Test s:if=false hides component
        $output = $this->executeTemplate($compiled, ['showButton' => false]);
        $this->assertStringNotContainsString('<button', $output);
    }

    public function testComponentExpansionBeforeContextAnalysis(): void
    {
        // Components should be expanded before context analysis so that
        // OutputNodes in component templates get proper escaping context
        // Note: slot content is passed as string data, not expanded inline
        $template = '<s-alert type="warning"><script>alert("XSS")</script></s-alert>';

        $compiled = $this->compiler->compile($template);

        // Should use closure with extract
        $this->assertStringContainsString('(function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);', $compiled);
        $this->assertStringContainsString('return ob_get_clean();', $compiled);
        // The slot content should be in the array as string
        $this->assertStringContainsString("'slot' =>", $compiled);
        $this->assertStringContainsString('alert("XSS")', $compiled);

        // Component template uses raw() for slots
        $this->assertStringContainsString('echo $slot;', $compiled);
    }

    public function testExecuteCompiledComponentTemplate(): void
    {
        $template = '<s-button>Submit</s-button>';

        $compiled = $this->compiler->compile($template);

        // Execute the compiled code
        $output = $this->executeTemplate($compiled);

        // Should render the button with content
        $this->assertStringContainsString('<button class="btn">', $output);
        $this->assertStringContainsString('Submit', $output);
        $this->assertStringContainsString('</button>', $output);
    }

    public function testExecuteComponentWithAttributesAndSlots(): void
    {
        $template = '<s-alert type="danger">This is a critical error!</s-alert>';

        $compiled = $this->compiler->compile($template);

        // Execute the compiled code
        $output = $this->executeTemplate($compiled);

        // Should render the alert with type
        // Note: The attribute contains OutputNode which compiles to Escaper::html
        // resulting in quoted output like alert-">danger">
        $this->assertStringContainsString('<div class="alert alert-', $output);
        $this->assertStringContainsString('danger', $output);
        $this->assertStringContainsString('<strong>Notice</strong>', $output); // Default title
        $this->assertStringContainsString('This is a critical error!', $output);
    }

    public function testComponentVariablesAreIsolated(): void
    {
        // Test that component variables don't leak into parent scope
        $template = '<?php $slot = "parent value"; ?>' .
            '<s-button>Button text</s-button>' .
            '<?= $slot ?>'; // Should still be "parent value"

        $compiled = $this->compiler->compile($template);

        // Component should use closure for isolation
        $this->assertStringContainsString('(function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);', $compiled);
        $this->assertStringContainsString('return ob_get_clean();', $compiled);

        // Execute and verify parent $slot is not overwritten
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('parent value', $output);
        $this->assertStringContainsString('<button class="btn">Button text</button>', $output);
    }

    public function testMergesClassToRootElement(): void
    {
        // HTML attributes should merge to root element
        $template = '<s-button class="btn-large shadow">Save</s-button>';

        $compiled = $this->compiler->compile($template);

        // Should merge class with component's class
        $this->assertStringContainsString('<button class="btn btn-large shadow">', $compiled);

        // Execute and verify
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<button class="btn btn-large shadow">Save</button>', $output);
    }

    public function testMergesMultipleAttributesToRootElement(): void
    {
        // Multiple HTML attributes should all merge
        $template = '<s-button id="save-btn" class="shadow" data-action="submit">Save</s-button>';

        $compiled = $this->compiler->compile($template);

        // Should have all merged attributes
        $this->assertStringContainsString('id="save-btn"', $compiled);
        $this->assertStringContainsString('class="btn shadow"', $compiled);
        $this->assertStringContainsString('data-action="submit"', $compiled);

        // Execute and verify
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<button', $output);
        $this->assertStringContainsString('id="save-btn"', $output);
        $this->assertStringContainsString('class="btn shadow"', $output);
        $this->assertStringContainsString('data-action="submit"', $output);
    }

    public function testMergesAlpineDirectives(): void
    {
        // Alpine.js directives should merge to root element
        $template = '<s-button @click="save()" x-data="{ loading: false }">Save</s-button>';

        $compiled = $this->compiler->compile($template);

        // Should have Alpine directives on button
        $this->assertStringContainsString('@click="save()"', $compiled);
        $this->assertStringContainsString('x-data="{ loading: false }"', $compiled);
    }

    public function testBindAttributesBecomeVariables(): void
    {
        // s:bind attributes should become component variables
        $template = '<s-alert s:bind="[\'type\' => \'warning\', \'title\' => \'Attention\']" class="mb-4">Check this</s-alert>';

        $compiled = $this->compiler->compile($template);

        // Should pass type and title as variables
        $this->assertStringContainsString("'type' => 'warning'", $compiled);
        $this->assertStringContainsString("'title' => 'Attention'", $compiled);

        // class should merge to root element
        $this->assertStringContainsString('class="alert alert-', $compiled);
        $this->assertStringContainsString('mb-4', $compiled);
    }

    public function testMixedBindingsAttributesAndDirectives(): void
    {
        // Test everything together
        $template = '<s-button ' .
            's:bind="[\'variant\' => \'primary\']" ' .
            'class="btn-lg shadow" ' .
            'id="save-btn" ' .
            '@click="save()" ' .
            's:if="$canSave">' .
            'Save Changes' .
            '</s-button>';

        $compiled = $this->compiler->compile($template);

        // s:bind variant becomes variable
        $this->assertStringContainsString("'variant' => 'primary'", $compiled);

        // HTML attributes merge to root
        $this->assertStringContainsString('class="btn btn-lg shadow"', $compiled);
        $this->assertStringContainsString('id="save-btn"', $compiled);
        $this->assertStringContainsString('@click="save()"', $compiled);

        // s:if wraps the component
        $this->assertStringContainsString('<?php if ($canSave): ?>', $compiled);
    }

    public function testNamedSlotsWithMultipleElements(): void
    {
        // Multiple elements can be in a named slot
        $template = '<s-card>' .
            '<div s:slot="header">' .
            '<h1>Title</h1>' .
            '<p>Subtitle</p>' .
            '</div>' .
            '<p>Main content here</p>' .
            '</s-card>';

        $compiled = $this->compiler->compile($template);

        // Should have header slot with both elements
        $this->assertStringContainsString("'header' =>", $compiled);
        $this->assertStringContainsString('Title', $compiled);
        $this->assertStringContainsString('Subtitle', $compiled);

        // Execute and verify structure
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<h1>Title</h1>', $output);
        $this->assertStringContainsString('<p>Subtitle</p>', $output);
        $this->assertStringContainsString('Main content here', $output);
    }

    public function testOptionalNamedSlots(): void
    {
        // Component can check if named slot is set
        $template = '<s-card>' .
            '<div s:slot="header">Header Content</div>' .
            '<p>Body content</p>' .
            '</s-card>';

        $compiled = $this->compiler->compile($template);

        // Execute and verify - footer should not appear since we didn't provide it
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('Header Content', $output);
        $this->assertStringContainsString('Body content', $output);
        // Card component shows empty footer when not provided
        $this->assertStringContainsString('card-footer', $output);
    }

    public function testNamedSlotWithComponentInside(): void
    {
        // Named slots can contain component tags, but they won't be expanded within the slot
        // This is a limitation of the current string-based slot implementation
        $template = '<s-card>' .
            '<h3 s:slot="header">Static Header</h3>' .
            '<p>Card body</p>' .
            '</s-card>';

        $compiled = $this->compiler->compile($template);

        // Should have named slot
        $this->assertStringContainsString("'header' =>", $compiled);

        // Execute and verify
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<h3>Static Header</h3>', $output);
        $this->assertStringContainsString('Card body', $output);
    }

    public function testComplexModalComponentWithMultipleSlots(): void
    {
        $template = '<s-modal s:bind="[\'title\' => \'Confirm Action\', \'onClose\' => \'closeModal()\']">' .
            '<p>Are you sure you want to continue?</p>' .
            '<div s:slot="footer">' .
            '<button class="btn-secondary">Cancel</button>' .
            '<button class="btn-primary">Confirm</button>' .
            '</div>' .
            '</s-modal>';

        $compiled = $this->compiler->compile($template);

        // Should pass title and onClose as variables
        $this->assertStringContainsString("'title' => 'Confirm Action'", $compiled);
        $this->assertStringContainsString("'onClose' => 'closeModal()'", $compiled);

        // Should have footer slot
        $this->assertStringContainsString("'footer' =>", $compiled);

        // Should have default slot
        $this->assertStringContainsString("'slot' =>", $compiled);
        $this->assertStringContainsString('Are you sure', $compiled);

        // Execute and verify structure
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<div class="modal">', $output);
        $this->assertStringContainsString('<h2>Confirm Action</h2>', $output);
        $this->assertStringContainsString('Are you sure you want to continue?', $output);
        $this->assertStringContainsString('<button class="btn-secondary">Cancel</button>', $output);
        $this->assertStringContainsString('<button class="btn-primary">Confirm</button>', $output);
    }

    public function testDropdownComponentWithItemsRenderedOutside(): void
    {
        // Static HTML items work in slots
        $template = '<s-dropdown s:bind="[\'trigger\' => \'Select Option\']"><li>Item 1</li><li>Item 2</li></s-dropdown>';

        $compiled = $this->compiler->compile($template);

        // Should pass trigger as variable
        $this->assertStringContainsString("'trigger' => 'Select Option'", $compiled);

        // Execute and verify
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<div class="dropdown">', $output);
        $this->assertStringContainsString('Select Option', $output);
        $this->assertStringContainsString('<li>Item 1</li>', $output);
        $this->assertStringContainsString('<li>Item 2</li>', $output);
    }

    public function testComponentsInLoop(): void
    {
        // Components can be used in loops
        $template = '<div s:foreach="$items as $item">' .
            '<s-button><?= $item ?></s-button>' .
            '</div>';

        $compiled = $this->compiler->compile($template);

        // Should have foreach
        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $compiled);

        // Should have button component
        $this->assertStringContainsString('<button class="btn', $compiled);

        // Execute with test data
        $items = ['First', 'Second', 'Third'];
        $output = $this->executeTemplate($compiled, ['items' => $items]);

        // Should render multiple buttons
        $this->assertStringContainsString('First', $output);
        $this->assertStringContainsString('Second', $output);
        $this->assertStringContainsString('Third', $output);
        $this->assertSame(3, substr_count($output, '<button class="btn'));
    }

    public function testComponentWithVariablesAndExpressions(): void
    {
        // Test passing variables as props
        // Note: Props are passed but component templates cannot use dynamic values in HTML attributes currently
        $template = '<s-alert s:bind="[\'title\' => $userName]">Hello everyone!</s-alert>';

        $compiled = $this->compiler->compile($template);

        // Should pass variables
        $this->assertStringContainsString("'title' => \$userName", $compiled);

        // Execute with test data
        $userName = 'John';
        $output = $this->executeTemplate($compiled, ['userName' => $userName]);

        $this->assertStringContainsString('John', $output);
        $this->assertStringContainsString('Hello everyone!', $output);
    }

    public function testAllSlotsFilled(): void
    {
        // Test card with all possible slots filled
        $template = '<s-card>' .
            '<h2 s:slot="header">Complete Card</h2>' .
            '<p>This is the main content in the default slot.</p>' .
            '<p>Multiple paragraphs work too.</p>' .
            '<div s:slot="footer">' .
            '<button>Action 1</button>' .
            '<button>Action 2</button>' .
            '</div>' .
            '</s-card>';

        $compiled = $this->compiler->compile($template);

        // Should have all three slots
        $this->assertStringContainsString("'header' =>", $compiled);
        $this->assertStringContainsString("'slot' =>", $compiled);
        $this->assertStringContainsString("'footer' =>", $compiled);

        // Execute and verify all content is present
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<h2>Complete Card</h2>', $output);
        $this->assertStringContainsString('This is the main content', $output);
        $this->assertStringContainsString('Multiple paragraphs', $output);
        $this->assertStringContainsString('<button>Action 1</button>', $output);
        $this->assertStringContainsString('<button>Action 2</button>', $output);
    }

    public function testComponentPropsWithDefaultValues(): void
    {
        // Component should render with defaults
        $template = '<s-alert>Simple message</s-alert>';

        $compiled = $this->compiler->compile($template);

        // Execute and verify
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('alert-info', $output);
        $this->assertStringContainsString('Simple message', $output);
    }

    public function testComponentWithSClassDirective(): void
    {
        // Test s:class directive inside component template
        $template = '<s-badge s:bind="[\'variant\' => \'primary\']">Admin</s-badge>';

        $compiled = $this->compiler->compile($template);

        // Should pass variant as variable
        $this->assertStringContainsString("'variant' => 'primary'", $compiled);

        // Should compile s:class directive
        $this->assertStringContainsString('HtmlAttributeHelper::classNames', $compiled);

        // Execute and verify correct class is applied
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<span class="badge badge-primary">', $output);
        $this->assertStringContainsString('Admin', $output);

        // Test with different variant
        $template2 = '<s-badge s:bind="[\'variant\' => \'danger\']">Error</s-badge>';
        $compiled2 = $this->compiler->compile($template2);
        $output2 = $this->executeTemplate($compiled2);
        $this->assertStringContainsString('badge-danger', $output2);

        // Test with no variant (should only have base class)
        $template3 = '<s-badge>Default</s-badge>';
        $compiled3 = $this->compiler->compile($template3);
        $output3 = $this->executeTemplate($compiled3);
        $this->assertStringContainsString('<span class="badge">', $output3);
        $this->assertStringNotContainsString('badge-primary', $output3);
        $this->assertStringNotContainsString('badge-danger', $output3);
    }

    public function testComponentWithSSpreadDirective(): void
    {
        // Test s:spread directive inside component template
        // Now works without needing s:text since we allow attribute-only directives
        $componentTemplate = '<div class="container" s:spread="$attrs ?? []"><?= $slot ?></div>';

        $this->setUpCompilerWithStringLoader(
            components: [
                'container' => $componentTemplate,
            ],
            config: $this->config,
        );

        // Use component with spread attributes
        $template = '<s-container s:bind="[\'attrs\' => [\'data-id\' => \'123\', \'aria-label\' => \'Container\']]">Content</s-container>';

        $compiled = $this->compiler->compile($template);

        // Should pass attrs as variable
        $this->assertStringContainsString("'attrs' => ['data-id' => '123', 'aria-label' => 'Container']", $compiled);

        // Should compile s:spread directive
        $this->assertStringContainsString('HtmlAttributeHelper::spreadAttrs', $compiled);

        // Execute and verify attributes are spread
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('data-id="123"', $output);
        $this->assertStringContainsString('aria-label="Container"', $output);
        $this->assertStringContainsString('Content', $output);
    }

    public function testFragmentElementWithNamedSlot(): void
    {
        // Test that <s-template s:slot="name"> works - allows multiple elements in a named slot without wrapper
        $template = '<s-card>' .
            '<s-template s:slot="header">' .
            '<h3>Product Title</h3>' .
            '<p class="subtitle">Premium Edition</p>' .
            '</s-template>' .
            '<p>Main content here</p>' .
            '</s-card>';

        $compiled = $this->compiler->compile($template);

        // Should have header slot with both elements
        $this->assertStringContainsString("'header' =>", $compiled);
        $this->assertStringContainsString('Product Title', $compiled);
        $this->assertStringContainsString('Premium Edition', $compiled);

        // Should have default slot
        $this->assertStringContainsString("'slot' =>", $compiled);
        $this->assertStringContainsString('Main content here', $compiled);

        // Execute and verify both header elements render without wrapper
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<h3>Product Title</h3>', $output);
        $this->assertStringContainsString('<p class="subtitle">Premium Edition</p>', $output);
        $this->assertStringContainsString('Main content here', $output);

        // Verify no s-template element in output
        $this->assertStringNotContainsString('<s-template', $output);
        $this->assertStringNotContainsString('s:slot', $output);
    }

    public function testComponentWithSIncludeDirective(): void
    {
        // Component and partial already exist in fixtures
        $template = '<s-widget>Widget Content</s-widget>';
        $compiled = $this->compiler->compile($template);

        // Should include the partial content
        $this->assertStringContainsString('Powered by Sugar', $compiled);
        $this->assertStringContainsString('text-muted', $compiled);

        // Execute and verify
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<div class="widget">', $output);
        $this->assertStringContainsString('Widget Content', $output);
        $this->assertStringContainsString('<small class="text-muted">Powered by Sugar</small>', $output);
    }

    public function testComponentWithSExtendsDirective(): void
    {
        // Components already exist in fixtures
        $template = '<s-custom-panel s:bind="[\'title\' => \'My Panel\']">Panel Content</s-custom-panel>';
        $compiled = $this->compiler->compile($template);

        // Should have base structure with overridden blocks
        $this->assertStringContainsString('base-panel', $compiled);
        $this->assertStringContainsString('panel-header', $compiled);
        $this->assertStringContainsString('panel-body', $compiled);

        // Should NOT have default content
        $this->assertStringNotContainsString('Default Header', $compiled);
        $this->assertStringNotContainsString('Default Body', $compiled);

        // Execute and verify
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<div class="base-panel">', $output);
        $this->assertStringContainsString('<h3>My Panel</h3>', $output);
        $this->assertStringContainsString('Panel Content', $output);
        $this->assertStringNotContainsString('Default Header', $output);
        $this->assertStringNotContainsString('Default Body', $output);
    }

    public function testComponentWithMultiLevelInheritance(): void
    {
        // Components already exist in fixtures
        $template = '<s-custom-layout s:bind="[\'title\' => \'Page Title\']">Page Content</s-custom-layout>';
        $compiled = $this->compiler->compile($template);

        // Should have base structure
        $this->assertStringContainsString('class="container"', $compiled);

        // Should have both blocks overridden
        $this->assertStringContainsString('<header', $compiled);
        $this->assertStringContainsString('<main', $compiled);

        // Should NOT have default content
        $this->assertStringNotContainsString('Default Header', $compiled);
        $this->assertStringNotContainsString('Default Main', $compiled);

        // Execute and verify
        $output = $this->executeTemplate($compiled);
        $this->assertStringContainsString('<div class="container">', $output);
        $this->assertStringContainsString('<h1>Page Title</h1>', $output);
        $this->assertStringContainsString('Page Content', $output);
        $this->assertStringNotContainsString('Default Header', $output);
        $this->assertStringNotContainsString('Default Main', $output);
    }

    public function testDynamicComponentWithBindingsAndSlots(): void
    {
        $engine = $this->createEngineWithTemplate(
            '<div s:component="$component" s:bind="[\'header\' => \'Dynamic Header\']">'
            . '<p>Body</p>'
            . '<div s:slot="footer">Footer</div>'
            . '</div>',
        );

        $output = $engine->render('dynamic', ['component' => 'card']);

        $this->assertStringContainsString('Dynamic Header', $output);
        $this->assertStringContainsString('Body', $output);
        $this->assertStringContainsString('Footer', $output);
        $this->assertStringContainsString('class="card"', $output);
    }

    public function testDynamicComponentRespectsControlFlow(): void
    {
        $engine = $this->createEngineWithTemplate(
            '<div s:if="$show">'
            . '<div s:component="$component">Save</div>'
            . '</div>',
        );

        $output = $engine->render('dynamic', ['component' => 'button', 'show' => true]);
        $this->assertStringContainsString('<button class="btn">Save</button>', $output);

        $output = $engine->render('dynamic', ['component' => 'button', 'show' => false]);
        $this->assertStringNotContainsString('<button', $output);
    }

    public function testDynamicComponentWithTemplateInheritance(): void
    {
        $engine = $this->createEngineWithTemplate(
            '<div s:component="$component" s:bind="[\'title\' => \'My Panel\']">Panel Content</div>',
        );

        $output = $engine->render('dynamic', ['component' => 'custom-panel']);

        $this->assertStringContainsString('base-panel', $output);
        $this->assertStringContainsString('My Panel', $output);
        $this->assertStringContainsString('Panel Content', $output);
    }

    public function testDynamicComponentWithComplexExpression(): void
    {
        $engine = $this->createEngineWithTemplate(
            '<div s:component="$componentName ?? \'button\'">Default</div>'
            . '<div s:component="$useAlert ? \'alert\' : \'button\'">Conditional</div>',
        );

        $output = $engine->render('dynamic', ['componentName' => null, 'useAlert' => false]);

        $this->assertStringContainsString('<button class="btn">Default</button>', $output);
        $this->assertStringContainsString('<button class="btn">Conditional</button>', $output);

        $output = $engine->render('dynamic', ['componentName' => 'alert', 'useAlert' => true]);

        $this->assertStringContainsString('class="alert alert-info"', $output);
        $this->assertStringContainsString('Default', $output);
        $this->assertStringContainsString('Conditional', $output);
    }

    private function createEngineWithTemplate(string $template): Engine
    {
        $components = $this->loadComponentSources([
            'alert',
            'button',
            'card',
            'custom-panel',
            'base-panel',
        ]);

        $templates = [
            'dynamic' => $template,
            's-base-panel.sugar.php' => $components['base-panel'],
            'components/s-base-panel.sugar.php' => $components['base-panel'],
        ];

        foreach ($components as $name => $source) {
            $templates['components/s-' . $name . '.sugar.php'] = $source;
        }

        $loader = new StringTemplateLoader(
            config: $this->config,
            templates: $templates,
        );

        $cacheDir = $this->createTempDir('sugar_cache_');
        $cache = new FileCache($cacheDir);

        return Engine::builder($this->config)
            ->withTemplateLoader($loader)
            ->withCache($cache)
            ->withExtension(new ComponentExtension())
            ->build();
    }

    /**
     * @param array<string> $names
     * @return array<string, string>
     */
    private function loadComponentSources(array $names): array
    {
        $sources = [];

        foreach ($names as $name) {
            $sources[$name] = $this->loadTemplate('components/s-' . $name . '.sugar.php');
        }

        return $sources;
    }
}
