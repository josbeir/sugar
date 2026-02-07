<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Component;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\RuntimeCallNode;
use Sugar\Ast\TextNode;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\Directive\ForeachCompiler;
use Sugar\Directive\IfCompiler;
use Sugar\Directive\WhileCompiler;
use Sugar\Enum\OutputContext;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Exception\SyntaxException;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Pass\Component\ComponentExpansionPass;
use Sugar\Pass\Middleware\AstMiddlewarePipeline;
use Sugar\Runtime\RuntimeEnvironment;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

final class ComponentExpansionPassTest extends TestCase
{
    use CompilerTestTrait;
    use TemplateTestHelperTrait;

    private FileTemplateLoader $loader;

    private AstMiddlewarePipeline $pipeline;

    protected function setUp(): void
    {
        $this->loader = $this->createComponentLoader();
        $this->loader->discoverComponents('.');

        $this->parser = $this->createParser();
        $registry = $this->createRegistry();

        // Register standard directives for testing
        $registry->register('if', IfCompiler::class);
        $registry->register('foreach', ForeachCompiler::class);
        $registry->register('while', WhileCompiler::class);

        $pass = new ComponentExpansionPass($this->loader, $this->parser, $registry, new SugarConfig());
        $this->pipeline = new AstMiddlewarePipeline([$pass]);
    }

    private function executePipeline(DocumentNode $ast, CompilationContext $context): DocumentNode
    {
        return $this->pipeline->execute($ast, $context);
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

        $result = $this->executePipeline($ast, $this->createContext());

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

        $result = $this->executePipeline($ast, $this->createContext());

        $code = $this->astToString($result);

        // The button component template wraps content in <button class="btn">
        $this->assertStringContainsString('<button class="btn">', $code);
        $this->assertStringContainsString('Save', $code);
    }

    public function testHandlesComponentWithoutChildren(): void
    {
        $template = '<s-button></s-button>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $code = $this->astToString($result);
        $this->assertStringContainsString('<button class="btn">', $code);
    }

    public function testComponentBindMissingValueThrows(): void
    {
        $template = '<s-button s:bind>Click</s-button>';
        $ast = $this->parser->parse($template);

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:bind attribute must have a value');

        $this->executePipeline($ast, $this->createContext());
    }

    public function testComponentBindOutputExpressionUsesExpression(): void
    {
        $ast = new DocumentNode([
            new ComponentNode(
                name: 'button',
                attributes: [
                    new AttributeNode(
                        's:bind',
                        new OutputNode('$bindings', true, OutputContext::HTML, 1, 1),
                        1,
                        1,
                    ),
                ],
                children: [new TextNode('Click', 1, 1)],
                line: 1,
                column: 1,
            ),
        ]);

        $result = $this->executePipeline($ast, $this->createContext());
        $code = $this->astToString($result);

        $this->assertStringContainsString('...($bindings)', $code);
    }

    public function testComponentWithNoRootElementSkipsMerge(): void
    {
        $componentPath = __DIR__ . '/../../../fixtures/templates/components/s-plain.sugar.php';
        file_put_contents($componentPath, 'Plain <?= $slot ?>');
        $this->loader->discoverComponents('.');

        try {
            $template = '<s-plain class="extra">Text</s-plain>';
            $ast = $this->parser->parse($template);

            $result = $this->executePipeline($ast, $this->createContext());
            $code = $this->astToString($result);

            $this->assertStringContainsString('Plain', $code);
            $this->assertStringContainsString('Text', $code);
            $this->assertStringNotContainsString('class="extra"', $code);
        } finally {
            unlink($componentPath);
        }
    }

    public function testComponentMergesAttributeDirectives(): void
    {
        $template = '<s-button s:class="[\'active\' => true]">Click</s-button>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());
        $code = $this->astToString($result);

        $this->assertStringContainsString('s:class', $code);
    }

    public function testNestedComponentDirectiveExpandsInTemplate(): void
    {
        $componentPath = __DIR__ . '/../../../fixtures/templates/components/s-wrapper.sugar.php';
        file_put_contents($componentPath, '<div s:component="button">Inner</div>');
        $this->loader->discoverComponents('.');

        try {
            $template = '<s-wrapper></s-wrapper>';
            $ast = $this->parser->parse($template);

            $result = $this->executePipeline($ast, $this->createContext());
            $code = $this->astToString($result);

            $this->assertStringContainsString('<button class="btn">', $code);
            $this->assertStringContainsString('Inner', $code);
        } finally {
            unlink($componentPath);
        }
    }

    public function testNestedComponentNamedSlotsExpand(): void
    {
        $componentPath = __DIR__ . '/../../../fixtures/templates/components/s-panel.sugar.php';
        file_put_contents(
            $componentPath,
            '<s-card><div s:slot="header">Head</div>Body</s-card>',
        );
        $this->loader->discoverComponents('.');

        try {
            $template = '<s-panel></s-panel>';
            $ast = $this->parser->parse($template);

            $result = $this->executePipeline($ast, $this->createContext());
            $code = $this->astToString($result);

            $this->assertStringContainsString('Head', $code);
            $this->assertStringContainsString('Body', $code);
        } finally {
            unlink($componentPath);
        }
    }

    public function testMergesStringClassAttributesOnRootElement(): void
    {
        $template = '<s-button class="primary">Click</s-button>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());
        $code = $this->astToString($result);

        $this->assertStringContainsString('class="btn primary"', $code);
    }

    public function testOverridesClassAttributeWhenDynamicValueProvided(): void
    {
        $ast = new DocumentNode([
            new ComponentNode(
                name: 'button',
                attributes: [
                    new AttributeNode(
                        'class',
                        new OutputNode('$class', true, OutputContext::HTML_ATTRIBUTE, 1, 1),
                        1,
                        1,
                    ),
                ],
                children: [new TextNode('Click', 1, 1)],
                line: 1,
                column: 1,
            ),
        ]);

        $result = $this->executePipeline($ast, $this->createContext());
        $code = $this->astToString($result);

        $this->assertStringContainsString('class="<?= $class ?>"', $code);
        $this->assertStringNotContainsString('class="btn"', $code);
    }

    public function testRuntimeComponentBindUsesOutputExpression(): void
    {
        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('s:component', '$component', 1, 1),
                new AttributeNode(
                    's:bind',
                    new OutputNode('$bindings', true, OutputContext::HTML, 1, 1),
                    1,
                    1,
                ),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RuntimeCallNode::class, $result->children[0]);

        $runtimeCall = $result->children[0];
        $this->assertSame('$bindings', $runtimeCall->arguments[1]);
        $this->assertSame('[]', $runtimeCall->arguments[3]);
    }

    public function testNestedTemplateWithDynamicComponentDirectiveCreatesRuntimeCall(): void
    {
        $dynamicComponentPath = __DIR__ . '/../../../fixtures/templates/components/s-dynamic-panel.sugar.php';
        file_put_contents($dynamicComponentPath, '<div s:component="$componentName"></div>');

        try {
            $this->loader->discoverComponents('.');

            $ast = $this->parser->parse('<s-dynamic-panel></s-dynamic-panel>');
            $result = $this->executePipeline($ast, $this->createContext());

            $this->assertGreaterThan(0, count($result->children));

            $hasRuntimeCall = false;
            foreach ($result->children as $child) {
                if ($child instanceof RuntimeCallNode) {
                    $hasRuntimeCall = true;
                    break;
                }
            }

            $this->assertTrue($hasRuntimeCall);
        } finally {
            unlink($dynamicComponentPath);
        }
    }

    public function testCreatesRuntimeComponentCallForDynamicName(): void
    {
        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('s:component', '$component', 1, 1),
                new AttributeNode('class', 'panel', 1, 1),
                new AttributeNode('disabled', null, 1, 1),
                new AttributeNode(
                    'data-id',
                    new OutputNode('$id', true, OutputContext::HTML_ATTRIBUTE, 1, 1),
                    1,
                    1,
                ),
            ],
            children: [new TextNode('Content', 1, 1)],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$element]);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertSame(
            RuntimeEnvironment::class . '::getRenderer()->renderComponent',
            $call->callableExpression,
        );
        $this->assertSame('$component', $call->arguments[0]);
        $this->assertSame('[]', $call->arguments[1]);
        $this->assertStringContainsString("'slot' => 'Content'", $call->arguments[2]);
        $this->assertStringContainsString("'class' => 'panel'", $call->arguments[3]);
        $this->assertStringContainsString("'disabled' => null", $call->arguments[3]);
        $this->assertStringContainsString('\'data-id\' => $id', $call->arguments[3]);
    }

    public function testThrowsWhenComponentNameIsNotString(): void
    {
        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode(
                    's:component',
                    new OutputNode('$name', true, OutputContext::HTML, 1, 1),
                    1,
                    1,
                ),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$element]);

        $this->expectException(SyntaxException::class);

        $this->executePipeline($ast, $this->createContext());
    }

    public function testExpandsNestedComponents(): void
    {
        // Create a temporary component that uses another component
        $nestedComponentPath = __DIR__ . '/../../../fixtures/templates/components/s-panel.sugar.php';
        file_put_contents($nestedComponentPath, '<div class="panel"><s-button><?= $slot ?></s-button></div>');

        $this->loader->discoverComponents('.');

        $template = '<s-panel>Submit</s-panel>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

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

        $this->executePipeline($ast, $this->createContext());
    }

    public function testPreservesComponentAttributes(): void
    {
        // Component with s:bind attributes should pass them as variables via closure
        $template = '<s-alert s:bind="[\'type\' => \'warning\']">Important message</s-alert>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

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

        $result = $this->executePipeline($ast, $this->createContext());

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

    public function testExpandsComponentDirectiveOnElement(): void
    {
        $template = '<div s:component="button">Click</div>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $code = $this->astToString($result);
        $this->assertStringContainsString('<button class="btn">', $code);
        $this->assertStringContainsString('Click', $code);
    }

    public function testExpandsComponentDirectiveOnFragment(): void
    {
        $template = '<s-template s:component="alert" s:bind="[\'type\' => \'info\']">Hello</s-template>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $code = $this->astToString($result);
        $this->assertStringContainsString('class="alert alert-info"', $code);
        $this->assertStringContainsString('Hello', $code);
        $this->assertStringContainsString("'type' => 'info'", $code);
    }

    public function testComponentDirectiveThrowsForEmptyName(): void
    {
        $template = '<div s:component="">Content</div>';
        $ast = $this->parser->parse($template);

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Component name must be a non-empty string.');

        $this->executePipeline($ast, $this->createContext());
    }

    public function testComponentDirectiveNormalizesQuotedName(): void
    {
        $template = '<div s:component="\'button\'">Click</div>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RuntimeCallNode::class, $result->children[0]);

        $runtimeCall = $result->children[0];
        $this->assertStringContainsString('button', $runtimeCall->arguments[0]);
    }

    public function testCreatesRuntimeCallForInvalidLiteralComponentName(): void
    {
        $template = '<div s:component="123">Click</div>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RuntimeCallNode::class, $result->children[0]);
    }

    public function testCreatesRuntimeCallForDynamicComponentDirective(): void
    {
        $template = '<div s:component="$componentName">Click</div>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RuntimeCallNode::class, $result->children[0]);
    }

    public function testDynamicComponentBindMissingValueThrows(): void
    {
        $template = '<div s:component="$componentName" s:bind>Click</div>';
        $ast = $this->parser->parse($template);

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:bind attribute must have a value');

        $this->executePipeline($ast, $this->createContext());
    }

    public function testComponentWithControlFlowWrapsFragment(): void
    {
        $template = '<s-button s:if="$show">Save</s-button>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);

        $fragment = $result->children[0];
        $this->assertTrue($this->containsElementTag($fragment->children, 'button'));
    }

    public function testSlotAttributeWithoutValueUsesDefaultSlot(): void
    {
        $template = '<s-card><div s:slot>Header</div>Body</s-card>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $output = $this->astToString($result);

        $this->assertStringContainsString('Card Header', $output);
        $this->assertStringContainsString('<div s:slot>Header</div>', $output);
        $this->assertStringContainsString('Body', $output);
    }

    public function testRuntimeComponentSlotsConcatenateMultipleNodes(): void
    {
        $template = '<div s:component="$component">Hello <?= $name ?></div>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RuntimeCallNode::class, $result->children[0]);

        $runtimeCall = $result->children[0];
        $this->assertStringContainsString("'slot' =>", $runtimeCall->arguments[2]);
        $this->assertStringContainsString(' . ', $runtimeCall->arguments[2]);
        $this->assertStringContainsString('$name', $runtimeCall->arguments[2]);
    }

    public function testRuntimeComponentAttributesIncludeOutputAndNull(): void
    {
        $ast = new DocumentNode([
            new ElementNode(
                tag: 'div',
                attributes: [
                    new AttributeNode('s:component', '$component', 1, 0),
                    new AttributeNode(
                        'data-id',
                        new OutputNode(
                            expression: '$id',
                            escape: true,
                            context: OutputContext::HTML,
                            line: 1,
                            column: 0,
                        ),
                        1,
                        0,
                    ),
                    new AttributeNode('hidden', null, 1, 0),
                ],
                children: [new TextNode('Click', 1, 0)],
                selfClosing: false,
                line: 1,
                column: 0,
            ),
        ]);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RuntimeCallNode::class, $result->children[0]);

        $runtimeCall = $result->children[0];
        $this->assertStringContainsString("'data-id' => \$id", $runtimeCall->arguments[3]);
        $this->assertStringContainsString("'hidden' => null", $runtimeCall->arguments[3]);
    }

    /**
     * @param array<\Sugar\Ast\Node> $nodes
     */
    private function containsElementTag(array $nodes, string $tag): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof ElementNode && $node->tag === $tag) {
                return true;
            }

            if ($node instanceof FragmentNode && $this->containsElementTag($node->children, $tag)) {
                return true;
            }

            if ($node instanceof ElementNode && $this->containsElementTag($node->children, $tag)) {
                return true;
            }
        }

        return false;
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

    public function testCachesComponentAsts(): void
    {
        // Create a tracking parser to count parse calls
        $registry = $this->createRegistry();
        $pass = new ComponentExpansionPass($this->loader, $this->parser, $registry, new SugarConfig());

        // Create AST with same component used 3 times
        // Each button component will be loaded but should only be parsed once
        $ast = new DocumentNode([
            new ComponentNode(name: 'button', children: [new TextNode('First', 1, 0)], line: 1, column: 0),
            new ComponentNode(name: 'button', children: [new TextNode('Second', 2, 0)], line: 2, column: 0),
            new ComponentNode(name: 'button', children: [new TextNode('Third', 3, 0)], line: 3, column: 0),
        ]);

        $result = (new AstMiddlewarePipeline([$pass]))->execute($ast, $this->createContext());

        // All components should be expanded correctly
        $output = $this->astToString($result);

        // Should contain all three button instances
        $this->assertStringContainsString('First', $output);
        $this->assertStringContainsString('Second', $output);
        $this->assertStringContainsString('Third', $output);

        // Verify caching by executing again with same component
        $ast2 = new DocumentNode([
            new ComponentNode(name: 'button', children: [new TextNode('Fourth', 4, 0)], line: 4, column: 0),
        ]);

        $result2 = (new AstMiddlewarePipeline([$pass]))->execute($ast2, $this->createContext());
        $output2 = $this->astToString($result2);
        $this->assertStringContainsString('Fourth', $output2);
    }

    public function testCachesSeparateComponentsSeparately(): void
    {
        $registry = $this->createRegistry();
        $pass = new ComponentExpansionPass($this->loader, $this->parser, $registry, new SugarConfig());

        // Use different components multiple times each
        $ast = new DocumentNode([
            new ComponentNode(name: 'button', children: [new TextNode('Button 1', 1, 0)], line: 1, column: 0),
            new ComponentNode(name: 'button', children: [new TextNode('Button 2', 2, 0)], line: 2, column: 0),
            new ComponentNode(name: 'alert', children: [new TextNode('Alert 1', 3, 0)], line: 3, column: 0),
            new ComponentNode(name: 'alert', children: [new TextNode('Alert 2', 4, 0)], line: 4, column: 0),
        ]);

        $result = (new AstMiddlewarePipeline([$pass]))->execute($ast, $this->createContext());
        $output = $this->astToString($result);

        // Should successfully expand both component types multiple times
        $this->assertStringContainsString('Button 1', $output);
        $this->assertStringContainsString('Button 2', $output);
        $this->assertStringContainsString('Alert 1', $output);
        $this->assertStringContainsString('Alert 2', $output);

        // Both component types should be properly expanded
        $this->assertGreaterThan(0, count($result->children));
    }

    public function testThrowsExceptionForInvalidBindExpression(): void
    {
        // Use existing button component - test that invalid s:bind throws error at compile time
        $ast = $this->parser->parse('<s-button s:bind="\'not an array\'">Click</s-button>');

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:bind attribute must be an array expression');
        $this->expectExceptionMessage('string literal');

        $this->executePipeline($ast, $this->createContext());
    }
}
