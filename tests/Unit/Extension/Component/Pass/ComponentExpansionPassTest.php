<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Pass;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\RuntimeCallNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\Pipeline\AstPipeline;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Extension\Component\Loader\ComponentLoader;
use Sugar\Extension\Component\Pass\ComponentExpansionPass;
use Sugar\Extension\Component\Runtime\ComponentRenderer;
use Sugar\Tests\Helper\Trait\AstStringifyTrait;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Tests for ComponentExpansionPass.
 *
 * All component invocations (ComponentNode and s:component directives)
 * are converted to RuntimeCallNode instances that delegate rendering
 * to ComponentRenderer at runtime.
 */
final class ComponentExpansionPassTest extends TestCase
{
    use AstStringifyTrait;
    use CompilerTestTrait;
    use NodeBuildersTrait;
    use TemplateTestHelperTrait;

    private AstPipeline $pipeline;

    /**
     * Expected callable expression for all component runtime calls.
     */
    private string $expectedCallable;

    protected function setUp(): void
    {
        $config = new SugarConfig();
        $stringTemplateLoader = new StringTemplateLoader(templates: [
            'components/s-alert.sugar.php' => $this->loadTemplate('components/s-alert.sugar.php'),
            'components/s-button.sugar.php' => $this->loadTemplate('components/s-button.sugar.php'),
            'components/s-card.sugar.php' => $this->loadTemplate('components/s-card.sugar.php'),
        ]);
        $loader = new ComponentLoader(
            templateLoader: $stringTemplateLoader,
            config: $config,
            componentDirectories: ['components'],
        );

        $this->parser = $this->createParser();
        $this->registry = $this->createRegistry();
        $pass = new ComponentExpansionPass(
            loader: $loader,
            registry: $this->registry,
            config: $config,
        );
        $this->pipeline = new AstPipeline([$pass]);

        $this->expectedCallable = RuntimeEnvironment::class
            . '::requireService(' . ComponentRenderer::class . '::class)->renderComponent';
    }

    private function executePipeline(DocumentNode $ast, CompilationContext $context): DocumentNode
    {
        return $this->pipeline->execute($ast, $context);
    }

    // ================================================================
    // Basic Component → RuntimeCallNode conversion
    // ================================================================

    /**
     * Test that a simple ComponentNode is converted to a RuntimeCallNode.
     */
    public function testExpandsSimpleComponentToRuntimeCall(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->component(
                    name: 'button',
                    children: [$this->text('Click me', 1, 0)],
                    line: 1,
                    column: 0,
                ),
            )
            ->build();

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertSame($this->expectedCallable, $call->callableExpression);
        $this->assertSame("'button'", $call->arguments[0]);
    }

    /**
     * Test that component children are included in the slots expression.
     */
    public function testComponentWithChildrenGeneratesSlotExpression(): void
    {
        $template = '<s-button>Save</s-button>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertStringContainsString("'slot' => 'Save'", $call->arguments[2]);
    }

    /**
     * Test that empty components produce valid runtime calls.
     */
    public function testComponentWithoutChildrenProducesValidRuntimeCall(): void
    {
        $template = '<s-button></s-button>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertSame("'button'", $call->arguments[0]);
    }

    /**
     * Test that no ComponentNode instances remain after pipeline execution.
     */
    public function testNoComponentNodeRemainsAfterExpansion(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->component(
                    name: 'button',
                    children: [$this->text('Click', 1, 0)],
                    line: 1,
                    column: 0,
                ),
            )
            ->build();

        $result = $this->executePipeline($ast, $this->createContext());

        foreach ($result->children as $child) {
            $this->assertNotInstanceOf(ComponentNode::class, $child);
        }
    }

    // ================================================================
    // s:bind attribute handling
    // ================================================================

    /**
     * Test that s:bind without a value throws SyntaxException.
     */
    public function testComponentBindMissingValueThrows(): void
    {
        $template = '<s-button s:bind>Click</s-button>';
        $ast = $this->parser->parse($template);

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:bind attribute must have a value');

        $this->executePipeline($ast, $this->createContext());
    }

    /**
     * Test that s:bind with an output expression passes it as binding argument.
     */
    public function testComponentBindOutputExpressionUsesExpression(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->component(
                    name: 'button',
                    attributes: [
                        $this->attributeNode(
                            's:bind',
                            $this->outputNode('$bindings', true, OutputContext::HTML, 1, 1),
                            1,
                            1,
                        ),
                    ],
                    children: [$this->text('Click', 1, 1)],
                    line: 1,
                    column: 1,
                ),
            )
            ->build();

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertSame('$bindings', $call->arguments[1]);
    }

    /**
     * Test that s:bind with mixed output parts throws SyntaxException.
     */
    public function testComponentBindMixedOutputPartsThrows(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->component(
                    name: 'button',
                    attributes: [
                        $this->attributeNode(
                            's:bind',
                            AttributeValue::parts([
                                '{',
                                $this->outputNode('$bindings', true, OutputContext::HTML, 1, 1),
                            ]),
                            1,
                            1,
                        ),
                    ],
                    children: [$this->text('Click', 1, 1)],
                    line: 1,
                    column: 1,
                ),
            )
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:bind attribute cannot contain mixed output expressions');

        $this->executePipeline($ast, $this->createContext());
    }

    /**
     * Test that s:bind with a static array expression is included in runtime call.
     */
    public function testComponentBindStaticArrayExpression(): void
    {
        $template = '<s-alert s:bind="[\'type\' => \'warning\']">Important</s-alert>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertStringContainsString("'type' => 'warning'", $call->arguments[1]);
        $this->assertStringContainsString("'slot' => 'Important'", $call->arguments[2]);
    }

    /**
     * Test that s:bind with an invalid expression throws SyntaxException.
     */
    public function testThrowsExceptionForInvalidBindExpression(): void
    {
        $ast = $this->parser->parse('<s-button s:bind="\'not an array\'">Click</s-button>');

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('must be an array expression');

        $this->executePipeline($ast, $this->createContext());
    }

    // ================================================================
    // Attribute handling
    // ================================================================

    /**
     * Test that regular attributes are passed as the attributes argument.
     */
    public function testRegularAttributesMergedIntoRuntimeAttributes(): void
    {
        $template = '<s-button class="primary">Click</s-button>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertStringContainsString("'class' => 'primary'", $call->arguments[3]);
    }

    /**
     * Test that attribute directives (s:class, s:spread) are passed to runtime.
     */
    public function testAttributeDirectivesPassedToRuntime(): void
    {
        $template = '<s-button s:class="[\'active\' => true]">Click</s-button>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertStringContainsString("'s:class'", $call->arguments[3]);
        $this->assertStringContainsString('active', $call->arguments[3]);
    }

    /**
     * Test that mixed-part attribute values are concatenated in the expression.
     */
    public function testRuntimeComponentAttributesConcatenateMixedParts(): void
    {
        $element = $this->element('div')
            ->attribute('s:component', '$component')
            ->attributeNode(
                $this->attributeNode(
                    'class',
                    AttributeValue::parts([
                        'btn-',
                        $this->outputNode('$state', true, OutputContext::HTML_ATTRIBUTE, 1, 1),
                        '-lg',
                    ]),
                    1,
                    1,
                ),
            )
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RuntimeCallNode::class, $result->children[0]);

        $call = $result->children[0];
        $this->assertStringContainsString("'class' => 'btn-' . (\$state) . '-lg'", $call->arguments[3]);
    }

    /**
     * Test that boolean and output attributes are properly represented.
     */
    public function testRuntimeComponentAttributesIncludeOutputAndNull(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->attribute('s:component', '$component')
                    ->attributeNode(
                        $this->attributeNode(
                            'data-id',
                            $this->outputNode('$id', true, OutputContext::HTML, 1, 0),
                            1,
                            0,
                        ),
                    )
                    ->attributeNode($this->attributeNode('hidden', null, 1, 0))
                    ->withChild($this->text('Click', 1, 0))
                    ->build(),
            )
            ->build();

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RuntimeCallNode::class, $result->children[0]);

        $runtimeCall = $result->children[0];
        $this->assertStringContainsString("'data-id' => \$id", $runtimeCall->arguments[3]);
        $this->assertStringContainsString("'hidden' => null", $runtimeCall->arguments[3]);
    }

    // ================================================================
    // Named slots
    // ================================================================

    /**
     * Test that named slots are extracted and included in the slots expression.
     */
    public function testSupportsNamedSlots(): void
    {
        $template = '<s-card>' .
            '<div s:slot="header">Custom Header</div>' .
            '<p>Body content</p>' .
            '<div s:slot="footer">Custom Footer</div>' .
            '</s-card>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertStringContainsString("'header' =>", $call->arguments[2]);
        $this->assertStringContainsString("'footer' =>", $call->arguments[2]);
        $this->assertStringContainsString("'slot' =>", $call->arguments[2]);
        $this->assertStringContainsString('Custom Header', $call->arguments[2]);
        $this->assertStringContainsString('Custom Footer', $call->arguments[2]);
        $this->assertStringContainsString('Body content', $call->arguments[2]);
    }

    /**
     * Test that s:slot without a value puts content into the default slot.
     */
    public function testSlotAttributeWithoutValueUsesDefaultSlot(): void
    {
        $template = '<s-card><div s:slot>Header</div>Body</s-card>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);

        // s:slot without value puts content in default slot
        $this->assertStringContainsString("'slot' =>", $call->arguments[2]);
    }

    /**
     * Test that multiple child nodes are concatenated into a single slot expression.
     */
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

    // ================================================================
    // s:component directive handling
    // ================================================================

    /**
     * Test that s:component on an element with a literal name creates a RuntimeCallNode.
     */
    public function testExpandsComponentDirectiveOnElement(): void
    {
        $template = '<div s:component="button">Click</div>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertSame("'button'", $call->arguments[0]);
        $this->assertStringContainsString("'slot' => 'Click'", $call->arguments[2]);
    }

    /**
     * Test that s:component on a fragment with s:bind creates a RuntimeCallNode.
     */
    public function testExpandsComponentDirectiveOnFragment(): void
    {
        $template = '<s-template s:component="alert" s:bind="[\'type\' => \'info\']">Hello</s-template>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertSame("'alert'", $call->arguments[0]);
        $this->assertStringContainsString("'type' => 'info'", $call->arguments[1]);
        $this->assertStringContainsString("'slot' => 'Hello'", $call->arguments[2]);
    }

    /**
     * Test that s:component with empty name throws SyntaxException.
     */
    public function testComponentDirectiveThrowsForEmptyName(): void
    {
        $template = '<div s:component="">Content</div>';
        $ast = $this->parser->parse($template);

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Component name must be a non-empty string.');

        $this->executePipeline($ast, $this->createContext());
    }

    /**
     * Test that quoted component names are normalized.
     */
    public function testComponentDirectiveNormalizesQuotedName(): void
    {
        $template = '<div s:component="\'button\'">Click</div>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RuntimeCallNode::class, $result->children[0]);

        $runtimeCall = $result->children[0];
        $this->assertSame("'button'", $runtimeCall->arguments[0]);
    }

    /**
     * Test that invalid literal names (e.g., "123") are treated as dynamic expressions.
     */
    public function testCreatesRuntimeCallForInvalidLiteralComponentName(): void
    {
        $template = '<div s:component="123">Click</div>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RuntimeCallNode::class, $result->children[0]);
    }

    /**
     * Test that a dynamic expression creates a RuntimeCallNode with the expression as name.
     */
    public function testCreatesRuntimeCallForDynamicComponentDirective(): void
    {
        $template = '<div s:component="$componentName">Click</div>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertSame('$componentName', $call->arguments[0]);
    }

    /**
     * Test dynamic component with full attribute set.
     */
    public function testCreatesRuntimeComponentCallForDynamicName(): void
    {
        $element = $this->element('div')
            ->attribute('s:component', '$component')
            ->attribute('class', 'panel')
            ->attributeNode($this->attributeNode('disabled', null, 1, 1))
            ->attributeNode(
                $this->attributeNode(
                    'data-id',
                    $this->outputNode('$id', true, OutputContext::HTML_ATTRIBUTE, 1, 1),
                    1,
                    1,
                ),
            )
            ->withChild($this->text('Content', 1, 1))
            ->build();

        $ast = $this->document()->withChild($element)->build();

        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $call = $result->children[0];
        $this->assertInstanceOf(RuntimeCallNode::class, $call);
        $this->assertSame($this->expectedCallable, $call->callableExpression);
        $this->assertSame('$component', $call->arguments[0]);
        $this->assertSame('[]', $call->arguments[1]);
        $this->assertStringContainsString("'slot' => 'Content'", $call->arguments[2]);
        $this->assertStringContainsString("'class' => 'panel'", $call->arguments[3]);
        $this->assertStringContainsString("'disabled' => null", $call->arguments[3]);
        $this->assertStringContainsString('\'data-id\' => $id', $call->arguments[3]);
    }

    // ================================================================
    // Dynamic s:component with s:bind
    // ================================================================

    /**
     * Test that dynamic component with s:bind output expression passes it correctly.
     */
    public function testRuntimeComponentBindUsesOutputExpression(): void
    {
        $element = $this->element('div')
            ->attribute('s:component', '$component')
            ->attributeNode(
                $this->attributeNode(
                    's:bind',
                    $this->outputNode('$bindings', true, OutputContext::HTML, 1, 1),
                    1,
                    1,
                ),
            )
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->executePipeline($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(RuntimeCallNode::class, $result->children[0]);

        $runtimeCall = $result->children[0];
        $this->assertSame('$bindings', $runtimeCall->arguments[1]);
        $this->assertSame('[]', $runtimeCall->arguments[3]);
    }

    /**
     * Test that dynamic component with mixed s:bind parts throws.
     */
    public function testRuntimeComponentBindMixedOutputPartsThrows(): void
    {
        $element = $this->element('div')
            ->attribute('s:component', '$component')
            ->attributeNode(
                $this->attributeNode(
                    's:bind',
                    AttributeValue::parts([
                        '{',
                        $this->outputNode('$bindings', true, OutputContext::HTML, 1, 1),
                    ]),
                    1,
                    1,
                ),
            )
            ->build();

        $ast = $this->document()->withChild($element)->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:bind attribute cannot contain mixed output expressions');

        $this->executePipeline($ast, $this->createContext());
    }

    /**
     * Test that dynamic component with boolean s:bind throws.
     */
    public function testDynamicComponentBindMissingValueThrows(): void
    {
        $template = '<div s:component="$componentName" s:bind>Click</div>';
        $ast = $this->parser->parse($template);

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:bind attribute must have a value');

        $this->executePipeline($ast, $this->createContext());
    }

    // ================================================================
    // Error handling
    // ================================================================

    /**
     * Test that output expression as s:component value throws.
     */
    public function testThrowsWhenComponentNameIsNotString(): void
    {
        $element = $this->element('div')
            ->attributeNode(
                $this->attributeNode(
                    's:component',
                    $this->outputNode('$name', true, OutputContext::HTML, 1, 1),
                    1,
                    1,
                ),
            )
            ->build();

        $ast = $this->document()->withChild($element)->build();

        $this->expectException(SyntaxException::class);

        $this->executePipeline($ast, $this->createContext());
    }

    // ================================================================
    // Helper
    // ================================================================

    /**
     * Create a compilation context for tests.
     */
    protected function createContext(
        string $source = '',
        string $templatePath = 'test.sugar.php',
        bool $debug = false,
    ): CompilationContext {
        return new CompilationContext($templatePath, $source, $debug);
    }
}
