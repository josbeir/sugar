<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Compiler;
use Sugar\Escape\Escaper;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\TemplateInheritance\FileTemplateLoader;
use Sugar\Tests\ExecuteTemplateTrait;
use Sugar\Tests\TemplateTestHelperTrait;

/**
 * Integration test: Full component compilation pipeline
 * Parser → ComponentExpansionPass → DirectiveExtractionPass → ... → CodeGenerator
 */
final class ComponentIntegrationTest extends TestCase
{
    use ExecuteTemplateTrait;
    use TemplateTestHelperTrait;

    private Compiler $compiler;

    private string $templatesPath;

    protected function setUp(): void
    {
        $this->templatesPath = __DIR__ . '/../fixtures/templates';
        $loader = new FileTemplateLoader($this->templatesPath);

        // Discover components in the components subdirectory
        $loader->discoverComponents('components');

        $this->compiler = new Compiler(
            parser: new Parser(),
            contextPass: new ContextAnalysisPass(),
            escaper: new Escaper(),
            templateLoader: $loader,
        );
    }

    public function testCompilesSimpleComponent(): void
    {
        $template = '<s-button>Click me</s-button>';

        $compiled = $this->compiler->compile($template);

        // Should use closure with extract
        $this->assertStringContainsString('(function($__vars) { extract($__vars);', $compiled);
        $this->assertStringContainsString("'slot' => 'Click me'", $compiled);

        // Should have button template HTML
        $this->assertStringContainsString('<button class="btn">', $compiled);
        // OutputNodes get compiled to htmlspecialchars, not raw PHP tags
        $this->assertStringContainsString('htmlspecialchars((string)($slot)', $compiled);
    }

    public function testCompilesComponentWithAttributes(): void
    {
        // Use s-bind: to pass component variables
        $template = '<s-alert s-bind:type="\'warning\'">Important message</s-alert>';

        $compiled = $this->compiler->compile($template);

        // Should use closure with extract and pass variables in array
        $this->assertStringContainsString('(function($__vars) { extract($__vars);', $compiled);
        $this->assertStringContainsString("'type' => 'warning'", $compiled);
        $this->assertStringContainsString("'slot' => 'Important message'", $compiled);

        // Should have alert template structure
        $this->assertStringContainsString('<div class="alert alert-', $compiled);
        // OutputNodes get compiled, not raw PHP tags
        $this->assertStringContainsString('htmlspecialchars((string)($type ?? \'info\')', $compiled);
    }

    public function testCompilesComponentWithNamedSlots(): void
    {
        $template = '<s-card>' .
            '<div s:slot="header">Card Title</div>' .
            '<p>Card body content</p>' .
            '<span s:slot="footer">Footer text</span>' .
            '</s-card>';

        $compiled = $this->compiler->compile($template);

        // Should use closure with extract
        $this->assertStringContainsString('(function($__vars) { extract($__vars);', $compiled);
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
        $this->assertStringContainsString('(function($__vars) { extract($__vars);', $compiled);
        $this->assertStringContainsString("'slot' =>", $compiled);
    }

    public function testComponentsWorkWithDirectives(): void
    {
        // Test s:if directive on component
        $template = '<s-button s:if="$showButton">Save</s-button>';

        $compiled = $this->compiler->compile($template);

        // Should use closure with extract
        $this->assertStringContainsString('(function($__vars) { extract($__vars);', $compiled);
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
        $template = '<s-alert type="warning"><script>alert("XSS")</script></s-alert>';

        $compiled = $this->compiler->compile($template);

        // Should use closure with extract
        $this->assertStringContainsString('(function($__vars) { extract($__vars);', $compiled);
        // The slot content should be in the array
        $this->assertStringContainsString("'slot' =>", $compiled);
        $this->assertStringContainsString('alert("XSS")', $compiled);

        // Component template should have proper escaping applied
        $this->assertStringContainsString('htmlspecialchars((string)($slot)', $compiled);
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
        $this->assertStringContainsString('(function($__vars) { extract($__vars);', $compiled);

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
}
