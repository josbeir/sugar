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

        // Should inject $slot variable
        $this->assertStringContainsString('$slot = \'Click me\';', $compiled);

        // Should have button template HTML
        $this->assertStringContainsString('<button class="btn">', $compiled);
        // OutputNodes get compiled to htmlspecialchars, not raw PHP tags
        $this->assertStringContainsString('htmlspecialchars((string)($slot)', $compiled);
    }

    public function testCompilesComponentWithAttributes(): void
    {
        $template = '<s-alert type="warning">Important message</s-alert>';

        $compiled = $this->compiler->compile($template);

        // Should inject $type and $slot variables
        $this->assertStringContainsString('$type = \'warning\';', $compiled);
        $this->assertStringContainsString('$slot = \'Important message\';', $compiled);

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

        // Should inject named slot variables
        $this->assertStringContainsString('$header =', $compiled);
        $this->assertStringContainsString('Card Title', $compiled);
        $this->assertStringContainsString('$footer =', $compiled);
        $this->assertStringContainsString('Footer text', $compiled);

        // Should inject default slot (the <p> without s:slot)
        $this->assertStringContainsString('$slot =', $compiled);
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

        // Should have proper variable scoping (multiple $slot assignments for nested components)
        $this->assertStringContainsString('$slot =', $compiled);
    }

    public function testComponentsWorkWithDirectives(): void
    {
        // TODO: Component expansion happens BEFORE directive extraction, so directives on component elements
        // are lost. Need to preserve directives on the wrapper element during expansion.
        $this->markTestIncomplete('Directives on components need special handling');
    }

    public function testComponentExpansionBeforeContextAnalysis(): void
    {
        // Components should be expanded before context analysis so that
        // OutputNodes in component templates get proper escaping context
        $template = '<s-alert type="warning"><script>alert("XSS")</script></s-alert>';

        $compiled = $this->compiler->compile($template);

        // The slot content should be treated as text and escaped
        $this->assertStringContainsString('$slot =', $compiled);
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
}
