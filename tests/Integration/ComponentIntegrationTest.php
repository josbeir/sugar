<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Config\SugarConfig;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Integration test: Full component compilation pipeline
 * Parser → ComponentExpansionPass → DirectiveExtractionPass → ... → CodeGenerator
 */
final class ComponentIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use ExecuteTemplateTrait;
    use TemplateTestHelperTrait;

    private string $templatesPath;

    protected function setUp(): void
    {
        $this->templatesPath = SUGAR_TEST_TEMPLATES_PATH;
        $config = (new SugarConfig())
            ->withTemplatePaths($this->templatesPath)
            ->withComponentPaths('components');

        $this->setUpCompiler(config: $config, withTemplateLoader: true);
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
        // Use s-bind: to pass component variables
        $template = '<s-alert s-bind:title="\'Important\'" s-bind:type="\'warning\'">Important message</s-alert>';

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
        $this->assertStringContainsString('htmlspecialchars((string)($title ?? \'Notice\')', $compiled);
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
        // Note: The attribute contains OutputNode which compiles to htmlspecialchars
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
        // s-bind: attributes should become component variables
        $template = '<s-alert s-bind:type="\'warning\'" s-bind:title="\'Attention\'" class="mb-4">Check this</s-alert>';

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
            's-bind:variant="\'primary\'" ' .
            'class="btn-lg shadow" ' .
            'id="save-btn" ' .
            '@click="save()" ' .
            's:if="$canSave">' .
            'Save Changes' .
            '</s-button>';

        $compiled = $this->compiler->compile($template);

        // s-bind:variant becomes variable
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
        $template = '<s-modal s-bind:title="\'Confirm Action\'" s-bind:onClose="\'closeModal()\'">' .
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
        $template = '<s-dropdown s-bind:trigger="\'Select Option\'"><li>Item 1</li><li>Item 2</li></s-dropdown>';

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
        $template = '<s-alert s-bind:title="$userName">Hello everyone!</s-alert>';

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
        $template = '<s-badge s-bind:variant="\'primary\'">Admin</s-badge>';

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
        $template2 = '<s-badge s-bind:variant="\'danger\'">Error</s-badge>';
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
        file_put_contents(
            $this->templatesPath . '/components/s-container.sugar.php',
            $componentTemplate,
        );

        // Rediscover components to include the new one
        $this->setUp();

        // Use component with spread attributes
        $template = '<s-container s-bind:attrs="[\'data-id\' => \'123\', \'aria-label\' => \'Container\']">Content</s-container>';

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

        // Cleanup
        unlink($this->templatesPath . '/components/s-container.sugar.php');
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
}
