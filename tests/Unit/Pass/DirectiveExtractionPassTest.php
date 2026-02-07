<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Config\SugarConfig;
use Sugar\Directive\ClassCompiler;
use Sugar\Directive\ContentCompiler;
use Sugar\Directive\ForeachCompiler;
use Sugar\Directive\IfCompiler;
use Sugar\Directive\IssetCompiler;
use Sugar\Directive\PassThroughCompiler;
use Sugar\Directive\SpreadCompiler;
use Sugar\Directive\TagCompiler;
use Sugar\Directive\UnlessCompiler;
use Sugar\Enum\OutputContext;
use Sugar\Exception\SyntaxException;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\Directive\DirectiveExtractionPass;
use Sugar\Pass\Middleware\AstMiddlewarePassInterface;
use Sugar\Pass\Middleware\AstMiddlewarePipeline;

final class DirectiveExtractionPassTest extends MiddlewarePassTestCase
{
    protected function getPass(): AstMiddlewarePassInterface
    {
        // Create registry with test directives
        $registry = $this->createTestRegistry();

        return new DirectiveExtractionPass($registry, new SugarConfig());
    }

    private function createTestRegistry(): DirectiveRegistry
    {
        $registry = new DirectiveRegistry();
        // Register built-in directives needed for tests
        $registry->register('if', IfCompiler::class);
        $registry->register('foreach', ForeachCompiler::class);
        $registry->register('class', ClassCompiler::class);
        $registry->register('spread', SpreadCompiler::class);
        $registry->register('tag', TagCompiler::class);
        $registry->register('slot', PassThroughCompiler::class);
        $registry->register('text', new ContentCompiler(escape: true));
        $registry->register('html', new ContentCompiler(escape: false, context: OutputContext::RAW));
        $registry->register('isset', IssetCompiler::class);
        $registry->register('unless', UnlessCompiler::class);

        return $registry;
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
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsDirective('if')
            ->withExpression('$user')
            ->hasChildCount(1);
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
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->containsDirective('foreach')
            ->withExpression('$items as $item');
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
        $result = $this->execute($ast, $this->createTestContext());

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
        $result = $this->execute($ast, $this->createTestContext());

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
        $result = $this->execute($ast, $this->createTestContext());

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
        $result = $this->execute($ast, $this->createTestContext());

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
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertSame('div', $result->children[0]->tag);
        $this->assertCount(1, $result->children[0]->attributes);
    }

    public function testExtractsComponentControlFlowDirective(): void
    {
        $component = new ComponentNode(
            name: 'button',
            attributes: [new AttributeNode('s:if', '$show', 1, 1)],
            children: [new TextNode('Click', 1, 1)],
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$component]);
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(DirectiveNode::class, $result->children[0]);
        $this->assertSame('if', $result->children[0]->name);

        $wrapped = $result->children[0]->children[0];
        $this->assertInstanceOf(ComponentNode::class, $wrapped);
        $this->assertCount(0, $wrapped->attributes);
    }

    public function testExtractsComponentAttributeDirectiveToClassAttribute(): void
    {
        $component = new ComponentNode(
            name: 'button',
            attributes: [new AttributeNode('s:class', "['active' => true]", 1, 1)],
            children: [],
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$component]);
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ComponentNode::class, $result->children[0]);

        $attrs = $result->children[0]->attributes;
        $this->assertCount(1, $attrs);
        $this->assertSame('class', $attrs[0]->name);
        $this->assertInstanceOf(OutputNode::class, $attrs[0]->value);
        $this->assertStringContainsString('classNames', $attrs[0]->value->expression);
    }

    public function testExtractsCustomExtractionDirectiveToFragment(): void
    {
        $element = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('s:tag', '$tag', 1, 1)],
            children: [new TextNode('Content', 1, 1)],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);
        $this->assertCount(2, $result->children[0]->children);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]->children[0]);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]->children[1]);
    }

    public function testExtractsSpreadDirectiveToAttributeOutput(): void
    {
        $element = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('s:spread', '$attrs', 1, 1)],
            children: [],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);

        $attrs = $result->children[0]->attributes;
        $this->assertCount(1, $attrs);
        $this->assertSame('', $attrs[0]->name);
        $this->assertInstanceOf(OutputNode::class, $attrs[0]->value);
        $this->assertStringContainsString('spreadAttrs', $attrs[0]->value->expression);
    }

    public function testKeepsFragmentWithInheritanceAttributeOnly(): void
    {
        $fragment = new FragmentNode(
            attributes: [new AttributeNode('s:block', 'content', 1, 1)],
            children: [new TextNode('Content', 1, 1)],
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$fragment]);
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);
        $this->assertSame('s:block', $result->children[0]->attributes[0]->name);
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
        $result = $this->execute($ast, $this->createTestContext());

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

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Directive attributes cannot contain dynamic output expressions');

        $this->execute($ast, $this->createTestContext());
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
        $result = $this->execute($ast, $this->createTestContext());

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
        $result = $this->execute($ast, $this->createTestContext());

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
        $registry = $this->createTestRegistry();
        $pass = new DirectiveExtractionPass($registry, SugarConfig::withPrefix('x'));

        $element = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('x:if', '$show', 1, 5)],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $pipeline = new AstMiddlewarePipeline([$pass]);
        $result = $pipeline->execute($ast, $this->createTestContext());

        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $this->assertSame('if', $directive->name);
    }

    public function testDoesNotExtractNonPrefixedAttributes(): void
    {
        $registry = $this->createTestRegistry();
        $pass = new DirectiveExtractionPass($registry, SugarConfig::withPrefix('x'));

        $element = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('s:if', '$show', 1, 5)],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $pipeline = new AstMiddlewarePipeline([$pass]);
        $result = $pipeline->execute($ast, $this->createTestContext());

        // Should not be extracted since we're using 'x' prefix
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertCount(1, $result->children[0]->attributes);
    }
}
