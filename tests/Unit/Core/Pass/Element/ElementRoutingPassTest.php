<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Pass\Element;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\AstPipeline;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\IfDirective;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\Interface\ElementClaimingDirectiveInterface;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Pass\Element\ElementRoutingPass;
use Sugar\Tests\Unit\Core\Pass\MiddlewarePassTestCase;

/**
 * Unit tests for ElementRoutingPass.
 *
 * The pass converts ComponentNodes — produced by the parser for <s-NAME> tags —
 * into FragmentNodes carrying a synthesized s:NAME directive attribute, so that
 * DirectiveExtractionPass can process them using its normal nesting rules.
 *
 * All other nodes and ComponentNodes for non-claiming directives are left untouched.
 */
final class ElementRoutingPassTest extends MiddlewarePassTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $routingRegistry = new DirectiveRegistry(withDefaults: false);

        // youtube: expression-carrying, OUTPUT type
        $routingRegistry->register('youtube', new class implements DirectiveInterface, ElementClaimingDirectiveInterface {
            public function getType(): DirectiveType
            {
                return DirectiveType::OUTPUT;
            }

            public function getElementExpressionAttribute(): string
            {
                return 'src';
            }

            public function compile(Node $node, CompilationContext $context): array
            {
                return [];
            }
        });

        // nobr: expression-less, CONTROL_FLOW type
        $routingRegistry->register('nobr', new class implements DirectiveInterface, ElementClaimingDirectiveInterface {
            public function getType(): DirectiveType
            {
                return DirectiveType::CONTROL_FLOW;
            }

            public function getElementExpressionAttribute(): ?string
            {
                return null;
            }

            public function compile(Node $node, CompilationContext $context): array
            {
                return [];
            }
        });

        $routingRegistry->register('if', new IfDirective());

        $this->pass = new ElementRoutingPass($routingRegistry, new SugarConfig());
    }

    protected function getPass(): AstPassInterface
    {
        // Only used by parent setUp() before our setUp() can override $this->pass.
        return new ElementRoutingPass(new DirectiveRegistry(withDefaults: false), new SugarConfig());
    }

    // ================================================================
    // Non-component nodes pass through unchanged
    // ================================================================

    /**
     * TextNodes must never be modified by this pass.
     */
    public function testIgnoresTextNodes(): void
    {
        $ast = $this->document()
            ->withChild($this->text('Hello'))
            ->build();

        $result = $this->execute($ast);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(TextNode::class, $result->children[0]);
    }

    /**
     * ElementNodes must never be modified by this pass.
     */
    public function testIgnoresElementNodes(): void
    {
        $ast = $this->document()
            ->withChild($this->element('div')->build())
            ->build();

        $result = $this->execute($ast);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertSame('div', $result->children[0]->tag);
    }

    // ================================================================
    // ComponentNodes for non-claiming directives pass through unchanged
    // ================================================================

    /**
     * A ComponentNode whose name is not in the registry must not be touched.
     */
    public function testIgnoresUnregisteredComponentName(): void
    {
        $component = $this->component('button', [], [$this->text('Click')]);
        $ast = $this->document()->withChild($component)->build();

        $result = $this->execute($ast);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ComponentNode::class, $result->children[0]);
        $this->assertSame('button', $result->children[0]->name);
    }

    /**
     * A ComponentNode whose registered directive does not implement
     * ElementClaimingDirectiveInterface must pass through untouched.
     */
    public function testIgnoresNonElementClaimingDirective(): void
    {
        $component = $this->component('if', [$this->attribute('condition', '$show')]);
        $ast = $this->document()->withChild($component)->build();

        $result = $this->execute($ast);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ComponentNode::class, $result->children[0]);
    }

    // ================================================================
    // Basic routing: ComponentNode → FragmentNode with synthesized s:NAME
    // ================================================================

    /**
     * <s-youtube src="$videoId"> produces FragmentNode(attrs=[s:youtube="$videoId"])
     * with children from the original ComponentNode.
     */
    public function testConvertsComponentToFragmentWithSynthesizedDirectiveAttr(): void
    {
        $component = $this->component(
            name: 'youtube',
            attributes: [$this->attribute('src', '$videoId')],
            children: [$this->text('Fallback')],
        );
        $ast = $this->document()->withChild($component)->build();

        $result = $this->execute($ast);

        $this->assertCount(1, $result->children);
        $fragment = $result->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);

        // Synthesized s:youtube attribute is present
        $this->assertCount(1, $fragment->attributes);
        $this->assertSame('s:youtube', $fragment->attributes[0]->name);
        $this->assertSame('$videoId', $fragment->attributes[0]->value->static);
    }

    /**
     * Children from the ComponentNode are placed directly inside the FragmentNode.
     */
    public function testOriginalChildrenPreservedInFragment(): void
    {
        $component = $this->component(
            name: 'youtube',
            attributes: [$this->attribute('src', "'abc'")],
            children: [$this->text('Fallback')],
        );
        $ast = $this->document()->withChild($component)->build();

        $result = $this->execute($ast);

        $fragment = $result->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        $this->assertCount(1, $fragment->children);
        $this->assertInstanceOf(TextNode::class, $fragment->children[0]);
        $this->assertSame('Fallback', $fragment->children[0]->content);
    }

    /**
     * A self-closing <s-youtube src="'abc'" /> produces a FragmentNode with no children.
     */
    public function testSelfClosingComponentProducesFragmentWithNoChildren(): void
    {
        $component = $this->component(
            name: 'youtube',
            attributes: [$this->attribute('src', "'abc'")],
            children: [],
        );
        $ast = $this->document()->withChild($component)->build();

        $result = $this->execute($ast);

        $fragment = $result->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        $this->assertSame("'abc'", $fragment->attributes[0]->value->static);
        $this->assertCount(0, $fragment->children);
    }

    // ================================================================
    // Expression attribute handling
    // ================================================================

    /**
     * When the directive's getElementExpressionAttribute() returns null, no expression
     * attribute is extracted and the synthesized s:NAME attribute has empty-string value.
     */
    public function testExpressionLessDirectiveUsesEmptyExpression(): void
    {
        $component = $this->component(
            name: 'nobr',
            attributes: [],
            children: [$this->text('No-wrap content')],
        );
        $ast = $this->document()->withChild($component)->build();

        $result = $this->execute($ast);

        $fragment = $result->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        $this->assertSame('s:nobr', $fragment->attributes[0]->name);
        $this->assertSame('', $fragment->attributes[0]->value->static);
    }

    /**
     * When the named expression attribute is absent, the expression defaults to ''.
     */
    public function testMissingExpressionAttributeDefaultsToEmptyString(): void
    {
        $component = $this->component(
            name: 'youtube',
            attributes: [],
            children: [],
        );
        $ast = $this->document()->withChild($component)->build();

        $result = $this->execute($ast);

        $fragment = $result->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        $this->assertSame('', $fragment->attributes[0]->value->static);
    }

    /**
     * A boolean (presence-only) expression attribute should produce expression 'true'.
     */
    public function testBooleanExpressionAttributeProducesTrueExpression(): void
    {
        $component = new ComponentNode(
            name: 'youtube',
            attributes: [
                new AttributeNode('src', AttributeValue::boolean(), 1, 0),
            ],
            children: [],
        );
        $ast = $this->document()->withChild($component)->build();

        $result = $this->execute($ast);

        $fragment = $result->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        $this->assertSame('true', $fragment->attributes[0]->value->static);
    }

    /**
     * A dynamic (output expression) attribute value must throw SyntaxException.
     */
    public function testDynamicExpressionAttributeThrowsSyntaxException(): void
    {
        $outputNode = new OutputNode('$videoId', true, OutputContext::HTML, 1, 0);
        $component = new ComponentNode(
            name: 'youtube',
            attributes: [
                new AttributeNode('src', AttributeValue::output($outputNode), 1, 0),
            ],
            children: [],
        );
        $ast = $this->document()->withChild($component)->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('"src" expression attribute');

        $this->execute($ast);
    }

    // ================================================================
    // Remaining s:* attributes are forwarded to the FragmentNode
    // ================================================================

    /**
     * s:if on the element tag is forwarded as a second attribute on the FragmentNode.
     * DirectiveExtractionPass will then extract both attributes and produce the right
     * DirectiveNode nesting (s:if wraps s:youtube).
     */
    public function testRemainingDirectiveAttributesForwardedToFragment(): void
    {
        $component = $this->component(
            name: 'youtube',
            attributes: [
                $this->attribute('src', '$videoId'),
                $this->attribute('s:if', '$show'),
            ],
            children: [$this->text('Fallback')],
        );
        $ast = $this->document()->withChild($component)->build();

        $result = $this->execute($ast);

        $fragment = $result->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);

        // First attr: synthesized s:youtube
        $this->assertSame('s:youtube', $fragment->attributes[0]->name);
        $this->assertSame('$videoId', $fragment->attributes[0]->value->static);

        // Second attr: forwarded s:if
        $this->assertSame('s:if', $fragment->attributes[1]->name);
        $this->assertSame('$show', $fragment->attributes[1]->value->static);
    }

    /**
     * Multiple s:* attributes are all forwarded after the synthesized attribute.
     */
    public function testMultipleDirectiveAttributesAllForwarded(): void
    {
        $component = $this->component(
            name: 'youtube',
            attributes: [
                $this->attribute('src', '$id'),
                $this->attribute('s:if', '$show'),
                $this->attribute('s:foreach', '$ids as $id'),
            ],
            children: [],
        );
        $ast = $this->document()->withChild($component)->build();

        $result = $this->execute($ast);

        $fragment = $result->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        $this->assertCount(3, $fragment->attributes);
        $this->assertSame('s:youtube', $fragment->attributes[0]->name);
        $this->assertSame('s:if', $fragment->attributes[1]->name);
        $this->assertSame('s:foreach', $fragment->attributes[2]->name);
    }

    // ================================================================
    // Regular HTML attributes are rejected
    // ================================================================

    /**
     * A regular HTML attribute (non-s:*) on an element-claiming ComponentNode
     * must throw SyntaxException with a clear message.
     */
    public function testRegularHtmlAttributeThrowsSyntaxException(): void
    {
        $component = $this->component(
            name: 'youtube',
            attributes: [
                $this->attribute('src', '$id'),
                $this->attribute('class', 'player'),
            ],
            children: [],
        );
        $ast = $this->document()->withChild($component)->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('"class"');

        $this->execute($ast);
    }

    // ================================================================
    // Source location is preserved
    // ================================================================

    /**
     * The FragmentNode must inherit line and column from the source ComponentNode.
     */
    public function testSourceLocationIsPreserved(): void
    {
        $component = new ComponentNode(
            name: 'youtube',
            attributes: [$this->attribute('src', "'id'")],
            children: [],
            line: 7,
            column: 12,
        );
        $ast = $this->document()->withChild($component)->build();

        $result = $this->execute($ast);

        $fragment = $result->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        $this->assertSame(7, $fragment->line);
        $this->assertSame(12, $fragment->column);
    }

    // ================================================================
    // Custom prefix configuration
    // ================================================================

    /**
     * When a custom prefix is used (e.g. 'x'), the synthesized attribute name
     * must use that prefix (x:youtube, not s:youtube).
     */
    public function testCustomPrefixIsUsedInSynthesizedAttribute(): void
    {
        $registry = new DirectiveRegistry(withDefaults: false);
        $registry->register('youtube', new class implements DirectiveInterface, ElementClaimingDirectiveInterface {
            public function getType(): DirectiveType
            {
                return DirectiveType::OUTPUT;
            }

            public function getElementExpressionAttribute(): string
            {
                return 'src';
            }

            public function compile(Node $node, CompilationContext $context): array
            {
                return [];
            }
        });

        $pass = new ElementRoutingPass($registry, SugarConfig::withPrefix('x'));

        $component = $this->component(
            name: 'youtube',
            attributes: [$this->attribute('src', '$id')],
            children: [],
        );
        $ast = $this->document()->withChild($component)->build();

        $pipeline = new AstPipeline([$pass]);
        $result = $pipeline->execute($ast, $this->createTestContext());

        $fragment = $result->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        $this->assertSame('x:youtube', $fragment->attributes[0]->name);
    }
}
