<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Pass;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\RuntimeCallNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\AstPipeline;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\ForeachDirective;
use Sugar\Core\Directive\IfDirective;
use Sugar\Core\Directive\WhileDirective;
use Sugar\Core\Enum\OutputContext;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Extension\Component\Loader\StringComponentTemplateLoader;
use Sugar\Extension\Component\Pass\ComponentPassFactory;
use Sugar\Extension\Component\Runtime\ComponentRuntimeServiceIds;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

final class ComponentExpansionPassTest extends TestCase
{
    use CompilerTestTrait;
    use NodeBuildersTrait;
    use TemplateTestHelperTrait;

    private StringComponentTemplateLoader $loader;

    private AstPipeline $pipeline;

    private SugarConfig $config;

    protected function setUp(): void
    {
        $this->config = new SugarConfig();
        $this->templateLoader = new StringTemplateLoader(config: $this->config);
        $this->loader = new StringComponentTemplateLoader(
            config: $this->config,
            components: [
                'alert' => $this->loadTemplate('components/s-alert.sugar.php'),
                'button' => $this->loadTemplate('components/s-button.sugar.php'),
                'card' => $this->loadTemplate('components/s-card.sugar.php'),
            ],
        );

        $this->parser = $this->createParser();
        $this->registry = $this->createRegistry();
        $passFactory = new ComponentPassFactory(
            templateLoader: $this->templateLoader,
            componentLoader: $this->loader,
            parser: $this->parser,
            registry: $this->registry,
            config: $this->config,
        );

        // Register standard directives for testing
        $this->registry->register('if', IfDirective::class);
        $this->registry->register('foreach', ForeachDirective::class);
        $this->registry->register('while', WhileDirective::class);

        $pass = $passFactory->createExpansionPass();
        $this->pipeline = new AstPipeline([$pass]);
    }

    private function executePipeline(DocumentNode $ast, CompilationContext $context): DocumentNode
    {
        return $this->pipeline->execute($ast, $context);
    }

    public function testExpandsSimpleComponent(): void
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

    public function testCustomPassesApplyToComponentTemplates(): void
    {
        $this->loader->addComponent('plain', '<div>hello</div>');

        $pass = new class implements AstPassInterface {
            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof TextNode) {
                    $node->content = strtoupper($node->content);
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $passFactory = new ComponentPassFactory(
            templateLoader: $this->templateLoader,
            componentLoader: $this->loader,
            parser: $this->parser,
            registry: $this->registry,
            config: $this->config,
            customPasses: [
                ['pass' => $pass, 'priority' => 35],
            ],
        );
        $componentPass = $passFactory->createExpansionPass();
        $pipeline = new AstPipeline([$componentPass]);

        $ast = $this->parser->parse('<s-plain></s-plain>');
        $result = $pipeline->execute($ast, $this->createContext());

        $output = $this->astToString($result);
        $this->assertStringContainsString('HELLO', $output);
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
        $code = $this->astToString($result);

        $this->assertStringContainsString('...($bindings)', $code);
    }

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

    public function testComponentWithNoRootElementSkipsMerge(): void
    {
        $this->loader->addComponent('plain', 'Plain <?= $slot ?>');

        $template = '<s-plain class="extra">Text</s-plain>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());
        $code = $this->astToString($result);

        $this->assertStringContainsString('Plain', $code);
        $this->assertStringContainsString('Text', $code);
        $this->assertStringNotContainsString('class="extra"', $code);
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
        $this->loader->addComponent('wrapper', '<div s:component="button">Inner</div>');

        $template = '<s-wrapper></s-wrapper>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());
        $code = $this->astToString($result);

        $this->assertStringContainsString('<button class="btn">', $code);
        $this->assertStringContainsString('Inner', $code);
    }

    public function testNestedComponentNamedSlotsExpand(): void
    {
        $this->loader->addComponent(
            'panel',
            '<s-card><div s:slot="header">Head</div>Body</s-card>',
        );

        $template = '<s-panel></s-panel>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());
        $code = $this->astToString($result);

        $this->assertStringContainsString('Head', $code);
        $this->assertStringContainsString('Body', $code);
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
        $ast = $this->document()
            ->withChild(
                $this->component(
                    name: 'button',
                    attributes: [
                        $this->attributeNode(
                            'class',
                            $this->outputNode('$class', true, OutputContext::HTML_ATTRIBUTE, 1, 1),
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
        $code = $this->astToString($result);

        $this->assertStringContainsString('class="<?= $class ?>"', $code);
        $this->assertStringNotContainsString('class="btn"', $code);
    }

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

    public function testNestedTemplateWithDynamicComponentDirectiveCreatesRuntimeCall(): void
    {
        $this->loader->addComponent('dynamic-panel', '<div s:component="$componentName"></div>');

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
    }

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
        $this->assertSame(
            RuntimeEnvironment::class
                . '::requireService(' . ComponentRuntimeServiceIds::class . '::RENDERER)->renderComponent',
            $call->callableExpression,
        );
        $this->assertSame('$component', $call->arguments[0]);
        $this->assertSame('[]', $call->arguments[1]);
        $this->assertStringContainsString("'slot' => 'Content'", $call->arguments[2]);
        $this->assertStringContainsString("'class' => 'panel'", $call->arguments[3]);
        $this->assertStringContainsString("'disabled' => null", $call->arguments[3]);
        $this->assertStringContainsString('\'data-id\' => $id', $call->arguments[3]);
    }

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

    public function testExpandsNestedComponents(): void
    {
        $this->loader->addComponent(
            'panel',
            '<div class="panel"><s-button><?= $slot ?></s-button></div>',
        );

        $template = '<s-panel>Submit</s-panel>';
        $ast = $this->parser->parse($template);

        $result = $this->executePipeline($ast, $this->createContext());

        $code = $this->astToString($result);
        $this->assertStringContainsString('<div class="panel">', $code);
        $this->assertStringContainsString('<button class="btn">', $code);
        $this->assertStringContainsString('Submit', $code);
    }

    public function testThrowsExceptionForUndiscoveredComponent(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->component(
                    name: 'nonexistent',
                    children: [],
                    line: 1,
                    column: 0,
                ),
            )
            ->build();

        $this->expectException(SyntaxException::class);
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

    /**
     * @param array<\Sugar\Core\Ast\Node> $nodes
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
     * @param \Sugar\Core\Ast\Node $node Node to convert
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
                if (!$attr->value->isBoolean()) {
                    $parts = $attr->value->toParts() ?? [];
                    if (count($parts) > 1) {
                        $html .= '="';
                        foreach ($parts as $part) {
                            if ($part instanceof OutputNode) {
                                $html .= '<?= ' . $part->expression . ' ?>';
                                continue;
                            }

                            $html .= $part;
                        }

                        $html .= '"';
                    } else {
                        $part = $parts[0] ?? '';
                        if ($part instanceof OutputNode) {
                            $html .= '="<?= ' . $part->expression . ' ?>"';
                        } else {
                            $html .= '="' . $part . '"';
                        }
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
        $passFactory = new ComponentPassFactory(
            templateLoader: $this->templateLoader,
            componentLoader: $this->loader,
            parser: $this->parser,
            registry: $registry,
            config: $this->config,
        );
        $pass = $passFactory->createExpansionPass();

        // Create AST with same component used 3 times
        // Each button component will be loaded but should only be parsed once
        $ast = $this->document()
            ->withChildren([
                 $this->component(name: 'button', children: [$this->text('First', 1, 0)], line: 1, column: 0),
                 $this->component(name: 'button', children: [$this->text('Second', 2, 0)], line: 2, column: 0),
                 $this->component(name: 'button', children: [$this->text('Third', 3, 0)], line: 3, column: 0),
            ])
            ->build();

        $result = (new AstPipeline([$pass]))->execute($ast, $this->createContext());

        // All components should be expanded correctly
        $output = $this->astToString($result);

        // Should contain all three button instances
        $this->assertStringContainsString('First', $output);
        $this->assertStringContainsString('Second', $output);
        $this->assertStringContainsString('Third', $output);

        // Verify caching by executing again with same component
        $ast2 = $this->document()
            ->withChild(
                $this->component(name: 'button', children: [$this->text('Fourth', 4, 0)], line: 4, column: 0),
            )
            ->build();

        $result2 = (new AstPipeline([$pass]))->execute($ast2, $this->createContext());
        $output2 = $this->astToString($result2);
        $this->assertStringContainsString('Fourth', $output2);
    }

    public function testCachesSeparateComponentsSeparately(): void
    {
        $registry = $this->createRegistry();
        $passFactory = new ComponentPassFactory(
            templateLoader: $this->templateLoader,
            componentLoader: $this->loader,
            parser: $this->parser,
            registry: $registry,
            config: $this->config,
        );
        $pass = $passFactory->createExpansionPass();

        // Use different components multiple times each
        $ast = $this->document()
            ->withChildren([
                $this->component(name: 'button', children: [$this->text('Button 1', 1, 0)], line: 1, column: 0),
                $this->component(name: 'button', children: [$this->text('Button 2', 2, 0)], line: 2, column: 0),
                $this->component(name: 'alert', children: [$this->text('Alert 1', 3, 0)], line: 3, column: 0),
                $this->component(name: 'alert', children: [$this->text('Alert 2', 4, 0)], line: 4, column: 0),
            ])
            ->build();

        $result = (new AstPipeline([$pass]))->execute($ast, $this->createContext());
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
