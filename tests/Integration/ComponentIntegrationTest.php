<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;
use Sugar\Extension\FragmentCache\FragmentCacheExtension;
use Sugar\Tests\Helper\Stub\ArraySimpleCache;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TempDirectoryTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Integration test: Full component compilation and rendering pipeline.
 *
 * Components compile to RuntimeCallNode instances that delegate rendering
 * to ComponentRenderer at runtime. Compile-time output contains
 * renderComponent() calls; actual template expansion happens at runtime.
 */
final class ComponentIntegrationTest extends TestCase
{
    use CompilerTestTrait;
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

    // ================================================================
    // Compilation output tests
    // ================================================================

    /**
     * Test that a simple component compiles to a renderComponent() call.
     */
    public function testCompilesSimpleComponent(): void
    {
        $template = '<s-button>Click me</s-button>';

        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('renderComponent(', $compiled);
        $this->assertStringContainsString("'button'", $compiled);
        $this->assertStringContainsString("'slot' => 'Click me'", $compiled);
    }

    /**
     * Test that component attributes compile to renderComponent() arguments.
     */
    public function testCompilesComponentWithAttributes(): void
    {
        $template = '<s-alert s:bind="[\'title\' => \'Important\', \'type\' => \'warning\']">Important message</s-alert>';

        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('renderComponent(', $compiled);
        $this->assertStringContainsString("'alert'", $compiled);
        $this->assertStringContainsString("'type' => 'warning'", $compiled);
        $this->assertStringContainsString("'title' => 'Important'", $compiled);
        $this->assertStringContainsString("'slot' => 'Important message'", $compiled);
    }

    /**
     * Test that named slots are included in the compiled slots expression.
     */
    public function testCompilesComponentWithNamedSlots(): void
    {
        $template = '<div class="bla"></div><s-card>' .
            '<div s:slot="header">Card Title</div>' .
            '<p>Card body content</p>' .
            '<span s:slot="footer">Footer text</span>' .
            '</s-card>';

        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('renderComponent(', $compiled);
        $this->assertStringContainsString("'card'", $compiled);
        $this->assertStringContainsString("'header' =>", $compiled);
        $this->assertStringContainsString('Card Title', $compiled);
        $this->assertStringContainsString("'footer' =>", $compiled);
        $this->assertStringContainsString('Footer text', $compiled);
        $this->assertStringContainsString("'slot' =>", $compiled);
        $this->assertStringContainsString('Card body content', $compiled);
    }

    /**
     * Test that nested components each compile to a renderComponent() call.
     */
    public function testCompilesNestedComponents(): void
    {
        $template = '<s-card>' .
            '<div s:slot="header"><s-button>Action</s-button></div>' .
            '<p>Content</p>' .
            '</s-card>';

        $compiled = $this->compiler->compile($template);

        // Both components produce renderComponent calls
        $this->assertStringContainsString("'card'", $compiled);
        $this->assertStringContainsString("'button'", $compiled);
        $this->assertSame(2, substr_count($compiled, 'renderComponent('));
    }

    /**
     * Test that component slots are passed as string data in compiled output.
     */
    public function testComponentExpansionBeforeContextAnalysis(): void
    {
        $template = '<s-alert type="warning"><script>alert("XSS")</script></s-alert>';

        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('renderComponent(', $compiled);
        $this->assertStringContainsString("'slot' =>", $compiled);
        $this->assertStringContainsString('alert("XSS")', $compiled);
    }

    /**
     * Test that Alpine.js directives are passed as attributes.
     */
    public function testMergesAlpineDirectives(): void
    {
        $template = '<s-button @click="save()" x-data="{ loading: false }">Save</s-button>';

        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('renderComponent(', $compiled);
        $this->assertStringContainsString('@click', $compiled);
        $this->assertStringContainsString('save()', $compiled);
        $this->assertStringContainsString('x-data', $compiled);
        $this->assertStringContainsString('loading: false', $compiled);
    }

    /**
     * Test that s:bind expressions are passed as bindings argument.
     */
    public function testBindAttributesBecomeVariables(): void
    {
        $template = '<s-alert s:bind="[\'type\' => \'warning\', \'title\' => \'Attention\']" class="mb-4">Check this</s-alert>';

        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('renderComponent(', $compiled);
        $this->assertStringContainsString("'type' => 'warning'", $compiled);
        $this->assertStringContainsString("'title' => 'Attention'", $compiled);
        // class should be in the attributes argument
        $this->assertStringContainsString("'class' => 'mb-4'", $compiled);
    }

    /**
     * Test mixed bindings, attributes, and directives.
     */
    public function testMixedBindingsAttributesAndDirectives(): void
    {
        $template = '<s-button ' .
            's:bind="[\'variant\' => \'primary\']" ' .
            'class="btn-lg shadow" ' .
            'id="save-btn" ' .
            '@click="save()" ' .
            's:if="$canSave">' .
            'Save Changes' .
            '</s-button>';

        $compiled = $this->compiler->compile($template);

        // s:bind becomes bindings
        $this->assertStringContainsString("'variant' => 'primary'", $compiled);

        // HTML attributes in the attributes argument
        $this->assertStringContainsString("'class' => 'btn-lg shadow'", $compiled);
        $this->assertStringContainsString("'id' => 'save-btn'", $compiled);
        $this->assertStringContainsString('@click', $compiled);

        // s:if wraps the component
        $this->assertStringContainsString('<?php if ($canSave): ?>', $compiled);
    }

    /**
     * Test that components in loops compile correctly.
     */
    public function testComponentsInLoopCompiledOutput(): void
    {
        $template = '<div s:foreach="$items as $item">' .
            '<s-button><?= $item ?></s-button>' .
            '</div>';

        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $compiled);
        $this->assertStringContainsString('renderComponent(', $compiled);
        $this->assertStringContainsString("'button'", $compiled);
    }

    // ================================================================
    // Engine render tests
    // ================================================================

    /**
     * Test that a simple component renders correctly via Engine.
     */
    public function testExecuteCompiledComponentTemplate(): void
    {
        $engine = $this->createEngineForComponent('<s-button>Submit</s-button>', ['button']);
        $output = $engine->render('test');

        $this->assertStringContainsString('<button class="btn">', $output);
        $this->assertStringContainsString('Submit', $output);
        $this->assertStringContainsString('</button>', $output);
    }

    /**
     * Test component rendering with attributes and slot content.
     */
    public function testExecuteComponentWithAttributesAndSlots(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-alert type="danger">This is a critical error!</s-alert>',
            ['alert'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('alert', $output);
        $this->assertStringContainsString('danger', $output);
        $this->assertStringContainsString('This is a critical error!', $output);
    }

    /**
     * Test that component variables are isolated from parent scope.
     */
    public function testComponentVariablesAreIsolated(): void
    {
        $engine = $this->createEngineForComponent(
            '<?php $slot = "parent value"; ?>'
            . '<s-button>Button text</s-button>'
            . '<?= $slot ?>',
            ['button'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('parent value', $output);
        $this->assertStringContainsString('Button text', $output);
    }

    /**
     * Test s:if directive wrapping a component.
     */
    public function testComponentsWorkWithDirectives(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-button s:if="$showButton">Save</s-button>',
            ['button'],
        );

        $output = $engine->render('test', ['showButton' => true]);
        $this->assertStringContainsString('<button class="btn">Save</button>', $output);

        $output = $engine->render('test', ['showButton' => false]);
        $this->assertStringNotContainsString('<button', $output);
    }

    /**
     * Test class attribute merging via runtime.
     */
    public function testMergesClassToRootElement(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-button class="btn-large shadow">Save</s-button>',
            ['button'],
        );
        $output = $engine->render('test');

        // Button should render with its template class
        $this->assertStringContainsString('<button', $output);
        $this->assertStringContainsString('btn', $output);
        $this->assertStringContainsString('Save', $output);
    }

    /**
     * Test multiple attributes are passed to the component.
     */
    public function testMergesMultipleAttributesToRootElement(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-button id="save-btn" class="shadow" data-action="submit">Save</s-button>',
            ['button'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('<button', $output);
        $this->assertStringContainsString('Save', $output);
    }

    /**
     * Test slot content with named slots renders correctly.
     */
    public function testNamedSlotsWithMultipleElements(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-card>'
            . '<div s:slot="header"><h1>Title</h1><p>Subtitle</p></div>'
            . '<p>Main content here</p>'
            . '</s-card>',
            ['card'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('<h1>Title</h1>', $output);
        $this->assertStringContainsString('<p>Subtitle</p>', $output);
        $this->assertStringContainsString('Main content here', $output);
    }

    /**
     * Test optional named slots with fallback content.
     */
    public function testOptionalNamedSlots(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-card>'
            . '<div s:slot="header">Header Content</div>'
            . '<p>Body content</p>'
            . '</s-card>',
            ['card'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('Header Content', $output);
        $this->assertStringContainsString('Body content', $output);
    }

    /**
     * Test named slot with static HTML content.
     */
    public function testNamedSlotWithComponentInside(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-card>'
            . '<h3 s:slot="header">Static Header</h3>'
            . '<p>Card body</p>'
            . '</s-card>',
            ['card'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('Static Header', $output);
        $this->assertStringContainsString('Card body', $output);
    }

    /**
     * Test complex component with multiple slots and bindings.
     */
    public function testComplexModalComponentWithMultipleSlots(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-modal s:bind="[\'title\' => \'Confirm Action\', \'onClose\' => \'closeModal()\']">'
            . '<p>Are you sure you want to continue?</p>'
            . '<div s:slot="footer">'
            . '<button class="btn-secondary">Cancel</button>'
            . '<button class="btn-primary">Confirm</button>'
            . '</div>'
            . '</s-modal>',
            ['modal'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('<div class="modal">', $output);
        $this->assertStringContainsString('<h2>Confirm Action</h2>', $output);
        $this->assertStringContainsString('Are you sure you want to continue?', $output);
        $this->assertStringContainsString('<button class="btn-secondary">Cancel</button>', $output);
        $this->assertStringContainsString('<button class="btn-primary">Confirm</button>', $output);
    }

    /**
     * Test component with slot items rendered by parent.
     */
    public function testDropdownComponentWithItemsRenderedOutside(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-dropdown s:bind="[\'trigger\' => \'Select Option\']"><li>Item 1</li><li>Item 2</li></s-dropdown>',
            ['dropdown'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('<div class="dropdown">', $output);
        $this->assertStringContainsString('Select Option', $output);
        $this->assertStringContainsString('<li>Item 1</li>', $output);
        $this->assertStringContainsString('<li>Item 2</li>', $output);
    }

    /**
     * Test components rendered inside loops.
     */
    public function testComponentsInLoop(): void
    {
        $engine = $this->createEngineForComponent(
            '<div s:foreach="$items as $item"><s-button><?= $item ?></s-button></div>',
            ['button'],
        );

        $items = ['First', 'Second', 'Third'];
        $output = $engine->render('test', ['items' => $items]);

        $this->assertStringContainsString('First', $output);
        $this->assertStringContainsString('Second', $output);
        $this->assertStringContainsString('Third', $output);
        $this->assertSame(3, substr_count($output, '<button'));
    }

    /**
     * Test passing variables as component props.
     */
    public function testComponentWithVariablesAndExpressions(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-alert s:bind="[\'title\' => $userName]">Hello everyone!</s-alert>',
            ['alert'],
        );

        $output = $engine->render('test', ['userName' => 'John']);
        $this->assertStringContainsString('John', $output);
        $this->assertStringContainsString('Hello everyone!', $output);
    }

    /**
     * Test component with all slots filled.
     */
    public function testAllSlotsFilled(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-card>'
            . '<h2 s:slot="header">Complete Card</h2>'
            . '<p>This is the main content in the default slot.</p>'
            . '<p>Multiple paragraphs work too.</p>'
            . '<div s:slot="footer">'
            . '<button>Action 1</button>'
            . '<button>Action 2</button>'
            . '</div>'
            . '</s-card>',
            ['card'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('Complete Card', $output);
        $this->assertStringContainsString('This is the main content', $output);
        $this->assertStringContainsString('Multiple paragraphs', $output);
        $this->assertStringContainsString('<button>Action 1</button>', $output);
        $this->assertStringContainsString('<button>Action 2</button>', $output);
    }

    /**
     * Test component props with default values.
     */
    public function testComponentPropsWithDefaultValues(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-alert>Simple message</s-alert>',
            ['alert'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('alert', $output);
        $this->assertStringContainsString('Simple message', $output);
    }

    /**
     * Test s:class directive via runtime component.
     */
    public function testComponentWithSClassDirective(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-badge s:bind="[\'variant\' => \'primary\']">Admin</s-badge>',
            ['badge'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('<span class="badge badge-primary">', $output);
        $this->assertStringContainsString('Admin', $output);

        // Test with different variant
        $engine2 = $this->createEngineForComponent(
            '<s-badge s:bind="[\'variant\' => \'danger\']">Error</s-badge>',
            ['badge'],
        );
        $output2 = $engine2->render('test');
        $this->assertStringContainsString('badge-danger', $output2);

        // Test with no variant
        $engine3 = $this->createEngineForComponent(
            '<s-badge>Default</s-badge>',
            ['badge'],
        );
        $output3 = $engine3->render('test');
        $this->assertStringContainsString('<span class="badge">', $output3);
        $this->assertStringNotContainsString('badge-primary', $output3);
    }

    /**
     * Test s:spread directive via runtime component.
     */
    public function testComponentWithSSpreadDirective(): void
    {
        $componentTemplate = '<div class="container" s:spread="$attrs ?? []"><?= $slot ?></div>';

        $engine = $this->createEngineWithTemplates(
            '<s-container s:bind="[\'attrs\' => [\'data-id\' => \'123\', \'aria-label\' => \'Container\']]">Content</s-container>',
            [
                'components/s-container.sugar.php' => $componentTemplate,
            ],
        );

        $output = $engine->render('test');
        $this->assertStringContainsString('data-id="123"', $output);
        $this->assertStringContainsString('aria-label="Container"', $output);
        $this->assertStringContainsString('Content', $output);
    }

    /**
     * Test fragment element with named slot.
     */
    public function testFragmentElementWithNamedSlot(): void
    {
        $engine = $this->createEngineForComponent(
            '<s-card>'
            . '<s-template s:slot="header">'
            . '<h3>Product Title</h3>'
            . '<p class="subtitle">Premium Edition</p>'
            . '</s-template>'
            . '<p>Main content here</p>'
            . '</s-card>',
            ['card'],
        );
        $output = $engine->render('test');

        $this->assertStringContainsString('Product Title', $output);
        $this->assertStringContainsString('Premium Edition', $output);
        $this->assertStringContainsString('Main content here', $output);
        $this->assertStringNotContainsString('<s-template', $output);
        $this->assertStringNotContainsString('s:slot', $output);
    }

    /**
     * Test component with s:include directive in its template.
     */
    public function testComponentWithSIncludeDirective(): void
    {
        $template = '<s-widget>Widget Content</s-widget>';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('renderComponent(', $compiled);
        $this->assertStringContainsString("'widget'", $compiled);

        // Execute via engine
        $engine = $this->createEngineForComponent($template, ['widget']);
        $output = $engine->render('test');
        $this->assertStringContainsString('<div class="widget">', $output);
        $this->assertStringContainsString('Widget Content', $output);
        $this->assertStringContainsString('<small class="text-muted">Powered by Sugar</small>', $output);
    }

    /**
     * Test component with s:extends directive in its template.
     */
    public function testComponentWithSExtendsDirective(): void
    {
        $template = '<s-custom-panel s:bind="[\'title\' => \'My Panel\']">Panel Content</s-custom-panel>';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('renderComponent(', $compiled);
        $this->assertStringContainsString("'custom-panel'", $compiled);

        // Execute via engine
        $engine = $this->createEngineForComponent($template, ['custom-panel', 'base-panel']);
        $output = $engine->render('test');
        $this->assertStringContainsString('<div class="base-panel">', $output);
        $this->assertStringContainsString('<h3>My Panel</h3>', $output);
        $this->assertStringContainsString('Panel Content', $output);
    }

    /**
     * Test component with multi-level template inheritance.
     */
    public function testComponentWithMultiLevelInheritance(): void
    {
        $template = '<s-custom-layout s:bind="[\'title\' => \'Page Title\']">Page Content</s-custom-layout>';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('renderComponent(', $compiled);
        $this->assertStringContainsString("'custom-layout'", $compiled);

        // Execute via engine
        $engine = $this->createEngineForComponent($template, ['custom-layout', 'base-layout']);
        $output = $engine->render('test');
        $this->assertStringContainsString('<div class="container">', $output);
        $this->assertStringContainsString('<h1>Page Title</h1>', $output);
        $this->assertStringContainsString('Page Content', $output);
    }

    // ================================================================
    // Full Engine render with filesystem
    // ================================================================

    /**
     * Test engine resolves components with absolute paths and inheritance.
     */
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
        <s-button>Click me!</s-button>
    </div>
    <s-button>Click me!</s-button>
</s-template>
SUGAR,
        );

        $loader = new FileTemplateLoader(
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
        $this->assertStringContainsString('<button class="btn">', $output);
        $this->assertStringContainsString('<p>hello world</p>', $output);
        $this->assertStringContainsString('<h1>Cool title</h1>', $output);
    }

    /**
     * Test engine preserves class attributes for component with ifcontent.
     */
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
<s-button>Click me!</s-button>
SUGAR,
        );

        $loader = new FileTemplateLoader(
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
        $this->assertStringContainsString('>    bla</button>', $output);
    }

    /**
     * Test that parent class attributes are merged with s:ifcontent component root.
     *
     * When a component template uses s:ifcontent on its root element, the directive
     * stores the element and reconstructs it as raw PHP. The component variant
     * adjustment pass must intercept the stored element before directive compilation
     * to apply $__sugar_attrs overrides (class merging, spread attrs).
     */
    public function testIfContentComponentMergesParentClassAttributes(): void
    {
        $engine = $this->createEngineWithTemplates(
            '<s-button class="testing">My button</s-button>',
            [
                'components/s-button.sugar.php' => '<button class="button" s:class="[\'bla\']" s:ifcontent><?= $slot ?></button>',
            ],
        );

        $output = $engine->render('test');

        $this->assertStringContainsString('button', $output);
        $this->assertStringContainsString('bla', $output);
        $this->assertStringContainsString('testing', $output);
        $this->assertStringContainsString('My button', $output);
    }

    /**
     * Test that s:ifcontent component suppresses output when content is empty.
     */
    public function testIfContentComponentSuppressesEmptyContent(): void
    {
        $engine = $this->createEngineWithTemplates(
            '<s-button></s-button>',
            [
                'components/s-button.sugar.php' => '<button class="button" s:class="[\'bla\']" s:ifcontent><?= $slot ?></button>',
            ],
        );

        $output = $engine->render('test');

        $this->assertEmpty(trim($output));
    }

    /**
     * Test engine resolves components with fragment cache and absolute paths.
     */
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
        <s-button>Click me!</s-button>
    </div>
</s-template>
SUGAR,
        );

        $loader = new FileTemplateLoader(
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
        $this->assertStringContainsString('<button class="btn">', $firstOutput);
        $this->assertStringContainsString('Click me!', $secondOutput);
        $this->assertStringContainsString('<button class="btn">', $secondOutput);
    }

    // ================================================================
    // Dynamic component tests
    // ================================================================

    /**
     * Test dynamic component with bindings and slots.
     */
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

    /**
     * Test dynamic component respects control flow directives.
     */
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

    /**
     * Test dynamic component with template inheritance.
     */
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

    /**
     * Test dynamic component with complex expression.
     */
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

    // ================================================================
    // Helpers
    // ================================================================

    /**
     * Create an engine for testing a template that uses a specific set of components.
     *
     * @param string $template The template source
     * @param array<string> $componentNames Component names to load (without 's-' prefix)
     */
    private function createEngineForComponent(string $template, array $componentNames): Engine
    {
        $components = $this->loadComponentSources($componentNames);

        $templates = [
            'test' => $template,
        ];

        foreach ($components as $name => $source) {
            $templates['components/s-' . $name . '.sugar.php'] = $source;
        }

        // Also load any partials referenced by components
        $partialsPath = SUGAR_TEST_TEMPLATES_PATH . '/components/partials';
        if (is_dir($partialsPath)) {
            $partialFiles = glob($partialsPath . '/*.sugar.php');
            if (is_array($partialFiles)) {
                foreach ($partialFiles as $partialFile) {
                    $partialName = 'components/partials/' . basename($partialFile);
                    $templates[$partialName] = (string)file_get_contents($partialFile);
                }
            }
        }

        $loader = new StringTemplateLoader(templates: $templates);

        $cacheDir = $this->createTempDir('sugar_cache_');
        $cache = new FileCache($cacheDir);

        return Engine::builder($this->config)
            ->withTemplateLoader($loader)
            ->withCache($cache)
            ->withExtension(new ComponentExtension())
            ->build();
    }

    /**
     * Create an engine with custom templates.
     *
     * @param string $template The main template source
     * @param array<string, string> $extraTemplates Additional templates to register
     */
    private function createEngineWithTemplates(string $template, array $extraTemplates): Engine
    {
        $templates = ['test' => $template] + $extraTemplates;

        $loader = new StringTemplateLoader(templates: $templates);

        $cacheDir = $this->createTempDir('sugar_cache_');
        $cache = new FileCache($cacheDir);

        return Engine::builder($this->config)
            ->withTemplateLoader($loader)
            ->withCache($cache)
            ->withExtension(new ComponentExtension())
            ->build();
    }

    /**
     * Create engine with dynamic template support.
     */
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

        $loader = new StringTemplateLoader(templates: $templates);

        $cacheDir = $this->createTempDir('sugar_cache_');
        $cache = new FileCache($cacheDir);

        return Engine::builder($this->config)
            ->withTemplateLoader($loader)
            ->withCache($cache)
            ->withExtension(new ComponentExtension())
            ->build();
    }

    /**
     * Load component source files from test fixtures.
     *
     * @param array<string> $names Component names (without 's-' prefix)
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
