<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Pass;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\Directive\ForeachCompiler;
use Sugar\Directive\IfCompiler;
use Sugar\Directive\WhileCompiler;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Pass\ComponentExpansionPass;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

final class ComponentExpansionPassTest extends TestCase
{
    use CompilerTestTrait;
    use TemplateTestHelperTrait;

    private FileTemplateLoader $loader;

    private ComponentExpansionPass $pass;

    protected function setUp(): void
    {
        $this->loader = $this->createComponentLoader();
        $this->loader->discoverComponents('.');

        $this->parser = $this->createParser();
        $registry = $this->createRegistry();

        // Register standard directives for testing
        $registry->registerDirective('if', IfCompiler::class);
        $registry->registerDirective('foreach', ForeachCompiler::class);
        $registry->registerDirective('while', WhileCompiler::class);

        $this->pass = new ComponentExpansionPass($this->loader, $this->parser, $registry, new SugarConfig());
    }

    public function testExpandsSimpleComponent(): void
    {
        $ast = new DocumentNode([
            new ComponentNode(
                name: 'button',
                children: [new TextNode('Click me', 1, 0)],
                line: 1,
                column: 0,
            ),
        ]);

        $result = $this->pass->execute($ast, $this->createContext());

        // Component should be expanded to multiple nodes (RawPhpNode for variables + template content)
        $this->assertGreaterThan(0, count($result->children));
        // No ComponentNode should remain
        foreach ($result->children as $child) {
            $this->assertNotInstanceOf(ComponentNode::class, $child);
        }

        // The expanded output should contain the button element
        $output = $this->astToString($result);
        $this->assertStringContainsString('<button class="btn">', $output);
    }

    public function testInjectsDefaultSlotContent(): void
    {
        $template = '<s-button>Save</s-button>';
        $ast = $this->parser->parse($template);

        $result = $this->pass->execute($ast, $this->createContext());

        $code = $this->astToString($result);

        // The button component template wraps content in <button class="btn">
        $this->assertStringContainsString('<button class="btn">', $code);
        $this->assertStringContainsString('Save', $code);
    }

    public function testHandlesComponentWithoutChildren(): void
    {
        $template = '<s-button></s-button>';
        $ast = $this->parser->parse($template);

        $result = $this->pass->execute($ast, $this->createContext());

        $code = $this->astToString($result);
        $this->assertStringContainsString('<button class="btn">', $code);
    }

    public function testExpandsNestedComponents(): void
    {
        // Create a temporary component that uses another component
        $nestedComponentPath = __DIR__ . '/../../fixtures/templates/components/s-panel.sugar.php';
        file_put_contents($nestedComponentPath, '<div class="panel"><s-button><?= $slot ?></s-button></div>');

        $this->loader->discoverComponents('.');

        $template = '<s-panel>Submit</s-panel>';
        $ast = $this->parser->parse($template);

        $result = $this->pass->execute($ast, $this->createContext());

        $code = $this->astToString($result);
        $this->assertStringContainsString('<div class="panel">', $code);
        $this->assertStringContainsString('<button class="btn">', $code);
        $this->assertStringContainsString('Submit', $code);

        unlink($nestedComponentPath);
    }

    public function testThrowsExceptionForUndiscoveredComponent(): void
    {
        $ast = new DocumentNode([
            new ComponentNode(
                name: 'nonexistent',
                children: [],
                line: 1,
                column: 0,
            ),
        ]);

        $this->expectException(ComponentNotFoundException::class);
        $this->expectExceptionMessage('Component "nonexistent" not found');

        $this->pass->execute($ast, $this->createContext());
    }

    public function testPreservesComponentAttributes(): void
    {
        // Component with s-bind: attributes should pass them as variables via closure
        $template = '<s-alert s-bind:type="\'warning\'">Important message</s-alert>';
        $ast = $this->parser->parse($template);

        $result = $this->pass->execute($ast, $this->createContext());

        $code = $this->astToString($result);

        // Should use closure with ob_start/ob_get_clean pattern (same as main templates)
        $this->assertStringContainsString('echo (function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);', $code);
        $this->assertStringContainsString('return ob_get_clean();', $code);
        $this->assertStringContainsString("'type' => 'warning'", $code);
        $this->assertStringContainsString("'slot' => 'Important message'", $code);
        // s-alert component has static "alert alert-info" class (parser limitation with dynamic attributes)
        $this->assertStringContainsString('class="alert alert-info"', $code);
        // Should have the message content
        $this->assertStringContainsString('Important message', $code);
    }

    public function testSupportsNamedSlots(): void
    {
        // Component with named slots
        $template = '<s-card>' .
            '<div s:slot="header">Custom Header</div>' .
            '<p>Body content</p>' .
            '<div s:slot="footer">Custom Footer</div>' .
            '</s-card>';
        $ast = $this->parser->parse($template);

        $result = $this->pass->execute($ast, $this->createContext());

        $code = $this->astToString($result);

        // Should use closure with ob_start/ob_get_clean pattern (same as main templates)
        $this->assertStringContainsString('echo (function(array $__vars): string { ob_start(); extract($__vars, EXTR_SKIP);', $code);
        $this->assertStringContainsString('return ob_get_clean();', $code);
        // Should have named slots in array
        $this->assertStringContainsString("'header' =>", $code);
        $this->assertStringContainsString("'footer' =>", $code);
        $this->assertStringContainsString("'slot' =>", $code); // Default slot

        // Should contain the slot content
        $this->assertStringContainsString('Custom Header', $code);
        $this->assertStringContainsString('Custom Footer', $code);
        $this->assertStringContainsString('Body content', $code);
    }

    /**
     * Helper to convert AST back to string for assertions
     */
    private function astToString(DocumentNode $ast): string
    {
        $output = '';
        foreach ($ast->children as $child) {
            $output .= $this->nodeToString($child);
        }

        return $output;
    }

    /**
     * Helper to convert a single node to string for assertions
     *
     * @param \Sugar\Ast\Node $node Node to convert
     * @return string String representation
     */
    private function nodeToString(Node $node): string
    {
        if ($node instanceof TextNode) {
            return $node->content;
        }

        if ($node instanceof RawPhpNode) {
            return $node->code;
        }

        if ($node instanceof ElementNode) {
            $html = '<' . $node->tag;
            foreach ($node->attributes as $attr) {
                $html .= ' ' . $attr->name;
                if ($attr->value !== null) {
                    if ($attr->value instanceof OutputNode) {
                        $html .= '="<?= ' . $attr->value->expression . ' ?>"';
                    } else {
                        $html .= '="' . $attr->value . '"';
                    }
                }
            }

            $html .= '>';
            foreach ($node->children as $child) {
                $html .= $this->nodeToString($child);
            }

            if (!$node->selfClosing) {
                $html .= '</' . $node->tag . '>';
            }

            return $html;
        }

        if ($node instanceof OutputNode) {
            return '<?= ' . $node->expression . ' ?>';
        }

        return '';
    }

    protected function createContext(
        string $source = '',
        string $templatePath = 'test.sugar.php',
        bool $debug = false,
    ): CompilationContext {
        return new CompilationContext($templatePath, $source, $debug);
    }
}
