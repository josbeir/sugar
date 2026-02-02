<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\OutputContext;
use Sugar\Pass\Directive\DirectiveExtractionPass;

final class DirectiveExtractionPassTest extends TestCase
{
    private DirectiveExtractionPass $pass;

    protected function setUp(): void
    {
        $this->pass = new DirectiveExtractionPass();
    }

    public function testExtractsSimpleIfDirective(): void
    {
        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('s:if', '$user', 1, 5),
                new AttributeNode('class', 'card', 1, 15),
            ],
            children: [new TextNode('Content', 1, 20)],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->transform($ast);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(DirectiveNode::class, $result->children[0]);

        $directive = $result->children[0];
        $this->assertSame('if', $directive->name);
        $this->assertSame('$user', $directive->expression);
        $this->assertCount(1, $directive->children);

        // The wrapped element should have the directive attribute removed
        $wrappedElement = $directive->children[0];
        $this->assertInstanceOf(ElementNode::class, $wrappedElement);
        $this->assertSame('div', $wrappedElement->tag);
        $this->assertCount(1, $wrappedElement->attributes);
        $this->assertSame('class', $wrappedElement->attributes[0]->name);
    }

    public function testExtractsForeachDirective(): void
    {
        $element = new ElementNode(
            tag: 'li',
            attributes: [
                new AttributeNode('s:foreach', '$items as $item', 1, 5),
            ],
            children: [new TextNode('Item', 1, 20)],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->transform($ast);

        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $this->assertSame('foreach', $directive->name);
        $this->assertSame('$items as $item', $directive->expression);
    }

    public function testExtractsNestedDirectives(): void
    {
        $innerElement = new ElementNode(
            tag: 'span',
            attributes: [new AttributeNode('s:if', '$item->active', 2, 5)],
            children: [new TextNode('Active', 2, 20)],
            selfClosing: false,
            line: 2,
            column: 4,
        );

        $outerElement = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('s:foreach', '$items as $item', 1, 5)],
            children: [$innerElement],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$outerElement]);
        $result = $this->pass->transform($ast);

        // Outer directive
        $outerDirective = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $outerDirective);
        $this->assertSame('foreach', $outerDirective->name);

        // Outer wrapped element contains inner directive
        $outerWrappedElement = $outerDirective->children[0];
        $this->assertInstanceOf(ElementNode::class, $outerWrappedElement);

        // Inner directive should be extracted from the outer element's children
        $innerDirective = $outerWrappedElement->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $innerDirective);
        $this->assertSame('if', $innerDirective->name);
        $this->assertSame('$item->active', $innerDirective->expression);
    }

    public function testPreservesNonDirectiveAttributes(): void
    {
        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('id', 'container', 1, 5),
                new AttributeNode('s:if', '$show', 1, 15),
                new AttributeNode('class', 'wrapper', 1, 25),
                new AttributeNode('data-value', '123', 1, 35),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->transform($ast);

        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $wrappedElement = $directive->children[0];
        $this->assertInstanceOf(ElementNode::class, $wrappedElement);

        $this->assertCount(3, $wrappedElement->attributes);
        $this->assertSame('id', $wrappedElement->attributes[0]->name);
        $this->assertSame('class', $wrappedElement->attributes[1]->name);
        $this->assertSame('data-value', $wrappedElement->attributes[2]->name);
    }

    public function testHandlesDirectiveWithoutValue(): void
    {
        $element = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('s:isset', '$user', 1, 5)],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->transform($ast);

        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $this->assertSame('isset', $directive->name);
        $this->assertSame('$user', $directive->expression);
    }

    public function testHandlesMultipleTopLevelDirectives(): void
    {
        $element1 = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('s:if', '$show', 1, 0)],
            children: [new TextNode('First', 1, 10)],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $element2 = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('s:unless', '$hide', 2, 0)],
            children: [new TextNode('Second', 2, 10)],
            selfClosing: false,
            line: 2,
            column: 0,
        );

        $ast = new DocumentNode([$element1, $element2]);
        $result = $this->pass->transform($ast);

        $this->assertCount(2, $result->children);
        $this->assertInstanceOf(DirectiveNode::class, $result->children[0]);
        $this->assertInstanceOf(DirectiveNode::class, $result->children[1]);
        $this->assertSame('if', $result->children[0]->name);
        $this->assertSame('unless', $result->children[1]->name);
    }

    public function testLeavesNonDirectiveElementsUnchanged(): void
    {
        $element = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('class', 'card', 1, 5)],
            children: [new TextNode('Content', 1, 20)],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->transform($ast);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertSame('div', $result->children[0]->tag);
        $this->assertCount(1, $result->children[0]->attributes);
    }

    public function testTransformsNestedElementsWithoutDirectives(): void
    {
        $innerElement = new ElementNode(
            tag: 'span',
            attributes: [],
            children: [new TextNode('Inner', 2, 5)],
            selfClosing: false,
            line: 2,
            column: 4,
        );

        $outerElement = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('s:if', '$show', 1, 5)],
            children: [$innerElement],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$outerElement]);
        $result = $this->pass->transform($ast);

        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $wrappedElement = $directive->children[0];
        $this->assertInstanceOf(ElementNode::class, $wrappedElement);
        $innerEl = $wrappedElement->children[0];

        $this->assertInstanceOf(ElementNode::class, $innerEl);
        $this->assertSame('span', $innerEl->tag);
    }

    public function testThrowsOnDynamicOutputInDirectiveAttribute(): void
    {
        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode(
                    's:if',
                    new OutputNode('$dynamicValue', true, OutputContext::HTML, 1, 10),
                    1,
                    5,
                ),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Directive attributes cannot contain dynamic output expressions');

        $this->pass->transform($ast);
    }

    public function testHandlesSelfClosingElementWithDirective(): void
    {
        $element = new ElementNode(
            tag: 'input',
            attributes: [
                new AttributeNode('s:if', '$show', 1, 7),
                new AttributeNode('type', 'text', 1, 15),
            ],
            children: [],
            selfClosing: true,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->transform($ast);

        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $wrappedElement = $directive->children[0];

        $this->assertInstanceOf(ElementNode::class, $wrappedElement);
        $this->assertTrue($wrappedElement->selfClosing);
    }

    public function testHandlesComplexNesting(): void
    {
        $deepElement = new ElementNode(
            tag: 'p',
            attributes: [new AttributeNode('s:unless', '$hidden', 3, 8)],
            children: [new TextNode('Deep', 3, 20)],
            selfClosing: false,
            line: 3,
            column: 8,
        );

        $midElement = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('s:foreach', '$items as $item', 2, 4)],
            children: [$deepElement],
            selfClosing: false,
            line: 2,
            column: 4,
        );

        $topElement = new ElementNode(
            tag: 'section',
            attributes: [new AttributeNode('s:if', '$show', 1, 8)],
            children: [$midElement],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$topElement]);
        $result = $this->pass->transform($ast);

        // Top level: s:if directive
        $topDirective = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $topDirective);
        $this->assertSame('if', $topDirective->name);

        // Second level: s:foreach directive
        $topWrapped = $topDirective->children[0];
        $this->assertInstanceOf(ElementNode::class, $topWrapped);
        $midDirective = $topWrapped->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $midDirective);
        $this->assertSame('foreach', $midDirective->name);

        // Third level: s:unless directive
        $midWrapped = $midDirective->children[0];
        $this->assertInstanceOf(ElementNode::class, $midWrapped);
        $deepDirective = $midWrapped->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $deepDirective);
        $this->assertSame('unless', $deepDirective->name);
    }

    public function testUsesCustomDirectivePrefix(): void
    {
        $pass = new DirectiveExtractionPass('x');

        $element = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('x:if', '$show', 1, 5)],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $pass->transform($ast);

        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $this->assertSame('if', $directive->name);
    }

    public function testDoesNotExtractNonPrefixedAttributes(): void
    {
        $pass = new DirectiveExtractionPass('x');

        $element = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('s:if', '$show', 1, 5)],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $pass->transform($ast);

        // Should not be extracted since we're using 'x' prefix
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertCount(1, $result->children[0]->attributes);
    }
}
