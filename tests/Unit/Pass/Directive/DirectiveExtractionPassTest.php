<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Directive;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Compiler\Pipeline\AstPipeline;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\Directive\ClassDirective;
use Sugar\Directive\ContentDirective;
use Sugar\Directive\ForeachDirective;
use Sugar\Directive\IfDirective;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Directive\Interface\ElementAwareDirectiveInterface;
use Sugar\Directive\IssetDirective;
use Sugar\Directive\PassThroughDirective;
use Sugar\Directive\SpreadDirective;
use Sugar\Directive\TagDirective;
use Sugar\Directive\UnlessDirective;
use Sugar\Enum\DirectiveType;
use Sugar\Enum\OutputContext;
use Sugar\Exception\SyntaxException;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\Directive\DirectiveExtractionPass;
use Sugar\Tests\Unit\Pass\MiddlewarePassTestCase;

final class DirectiveExtractionPassTest extends MiddlewarePassTestCase
{
    protected function getPass(): AstPassInterface
    {
        // Create registry with test directives
        $registry = $this->createTestRegistry();

        return new DirectiveExtractionPass($registry, new SugarConfig());
    }

    private function createTestRegistry(): DirectiveRegistry
    {
        $registry = new DirectiveRegistry();
        // Register built-in directives needed for tests
        $registry->register('if', IfDirective::class);
        $registry->register('foreach', ForeachDirective::class);
        $registry->register('class', ClassDirective::class);
        $registry->register('spread', SpreadDirective::class);
        $registry->register('tag', TagDirective::class);
        $registry->register('slot', PassThroughDirective::class);
        $registry->register('text', new ContentDirective(escape: true));
        $registry->register('html', new ContentDirective(escape: false, context: OutputContext::RAW));
        $registry->register('isset', IssetDirective::class);
        $registry->register('unless', UnlessDirective::class);

        return $registry;
    }

    public function testExtractsSimpleIfDirective(): void
    {
        $element = $this->element('div')
            ->attribute('s:if', '$user')
            ->attribute('class', 'card')
            ->withChild($this->text('Content', 1, 20))
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsDirective('if')
            ->withExpression('$user')
            ->hasChildCount(1);
    }

    public function testUnknownDirectiveShowsSnippetAndSuggestion(): void
    {
        $element = $this->element('p')
            ->attributeNode($this->attributeNode('s:blik', 'true', 2, 8))
            ->at(2, 5)
            ->withChild($this->text('Test', 2, 20))
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $context = $this->createTestContext(
            'test.sugar.php',
            "<div>\n    <p s:blik=\"true\">Test</p>\n</div>",
        );

        try {
            $this->execute($ast, $context);
            $this->fail('Expected SyntaxException for unknown directive.');
        } catch (SyntaxException $syntaxException) {
            $this->assertStringContainsString('Unknown directive "blik"', $syntaxException->getMessage());
            $this->assertStringContainsString('Did you mean "block"', $syntaxException->getMessage());
            $this->assertNotNull($syntaxException->snippet);
            $this->assertStringContainsString('s:blik', $syntaxException->snippet);
        }
    }

    public function testExtractsForeachDirective(): void
    {
        $element = $this->element('li')
            ->attribute('s:foreach', '$items as $item')
            ->withChild($this->text('Item', 1, 20))
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->containsDirective('foreach')
            ->withExpression('$items as $item');
    }

    public function testExtractsNestedDirectives(): void
    {
        $innerElement = $this->element('span')
            ->attribute('s:if', '$item->active')
            ->withChild($this->text('Active', 2, 20))
            ->at(2, 4)
            ->build();

        $outerElement = $this->element('div')
            ->attribute('s:foreach', '$items as $item')
            ->withChild($innerElement)
            ->build();

        $ast = $this->document()->withChild($outerElement)->build();
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
        $element = $this->element('div')
            ->attribute('id', 'container')
            ->attribute('s:if', '$show')
            ->attribute('class', 'wrapper')
            ->attribute('data-value', '123')
            ->build();

        $ast = $this->document()->withChild($element)->build();
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
        $element = $this->element('div')
            ->attribute('s:isset', '$user')
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $this->assertSame('isset', $directive->name);
        $this->assertSame('$user', $directive->expression);
    }

    public function testHandlesMultipleTopLevelDirectives(): void
    {
        $element1 = $this->element('div')
            ->attributeNode($this->attribute('s:if', '$show', 1, 0))
            ->withChild($this->text('First', 1, 10))
            ->build();

        $element2 = $this->element('div')
            ->attributeNode($this->attribute('s:unless', '$hide', 2, 0))
            ->withChild($this->text('Second', 2, 10))
            ->build();

        $ast = $this->document()->withChildren([$element1, $element2])->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(2, $result->children);
        $this->assertInstanceOf(DirectiveNode::class, $result->children[0]);
        $this->assertInstanceOf(DirectiveNode::class, $result->children[1]);
        $this->assertSame('if', $result->children[0]->name);
        $this->assertSame('unless', $result->children[1]->name);
    }

    public function testLeavesNonDirectiveElementsUnchanged(): void
    {
        $element = $this->element('div')
            ->attribute('class', 'card')
            ->withChild($this->text('Content', 1, 20))
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertSame('div', $result->children[0]->tag);
        $this->assertCount(1, $result->children[0]->attributes);
    }

    public function testExtractsComponentControlFlowDirective(): void
    {
        $component = $this->component(
            name: 'button',
            attributes: [$this->attribute('s:if', '$show')],
            children: [$this->text('Click', 1, 1)],
            line: 1,
            column: 1,
        );

        $ast = $this->document()->withChild($component)->build();
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
        $component = $this->component(
            name: 'button',
            attributes: [$this->attribute('s:class', "['active' => true]")],
            children: [],
            line: 1,
            column: 1,
        );

        $ast = $this->document()->withChild($component)->build();
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
        $element = $this->element('div')
            ->attribute('s:tag', '$tag')
            ->withChild($this->text('Content', 1, 1))
            ->at(1, 1)
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);
        $this->assertCount(2, $result->children[0]->children);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]->children[0]);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]->children[1]);
    }

    public function testExtractsSpreadDirectiveToAttributeOutput(): void
    {
        $element = $this->element('div')
            ->attribute('s:spread', '$attrs')
            ->at(1, 1)
            ->build();

        $ast = $this->document()->withChild($element)->build();
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
        $fragment = $this->fragment(
            attributes: [$this->attribute('s:block', 'content')],
            children: [$this->text('Content', 1, 1)],
            line: 1,
            column: 1,
        );

        $ast = $this->document()->withChild($fragment)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);
        $this->assertSame('s:block', $result->children[0]->attributes[0]->name);
    }

    public function testKeepsFragmentWithPassThroughDirectiveOnly(): void
    {
        $fragment = $this->fragment(
            attributes: [$this->attribute('s:slot', 'header')],
            children: [$this->text('Content', 1, 1)],
            line: 1,
            column: 1,
        );

        $ast = $this->document()->withChild($fragment)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);
        $this->assertSame('s:slot', $result->children[0]->attributes[0]->name);
    }

    public function testCustomExtractionReturnsFragmentWithoutElement(): void
    {
        $compiler = new class () implements DirectiveInterface, ElementAwareDirectiveInterface {
            public function extractFromElement(
                ElementNode $element,
                string $expression,
                array $transformedChildren,
                array $remainingAttrs,
            ): FragmentNode {
                return new FragmentNode(
                    attributes: [
                        new AttributeNode('s:if', '$item', 1, 5),
                    ],
                    children: [new RawPhpNode('prefix', 1, 1)],
                    line: 1,
                    column: 0,
                );
            }

            public function compile(Node $node, CompilationContext $context): array
            {
                return [];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::ATTRIBUTE;
            }
        };

        $registry = DirectiveRegistry::empty();
        $registry->register('if', IfDirective::class);
        $registry->register('custom', $compiler);

        $pass = new DirectiveExtractionPass($registry, new SugarConfig());
        $pipeline = new AstPipeline([$pass]);

        $element = $this->element('div')
            ->attribute('s:custom', '$value')
            ->at(1, 1)
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $pipeline->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(FragmentNode::class, $result->children[0]);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]->children[0]);
    }

    public function testIgnoresNonRawPhpNodesFromAttributeCompiler(): void
    {
        $compiler = new class () implements DirectiveInterface {
            public function compile(Node $node, CompilationContext $context): array
            {
                return [new TextNode('ignored', 1, 1)];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::ATTRIBUTE;
            }
        };

        $registry = DirectiveRegistry::empty();
        $registry->register('textattr', $compiler);

        $pass = new DirectiveExtractionPass($registry, new SugarConfig());
        $pipeline = new AstPipeline([$pass]);

        $element = $this->element('div')
            ->attribute('s:textattr', 'value')
            ->at(1, 1)
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $pipeline->execute($ast, $this->createTestContext());

        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertCount(0, $result->children[0]->attributes);
    }

    public function testTransformsNestedElementsWithoutDirectives(): void
    {
        $innerElement = $this->element('span')
            ->withChild($this->text('Inner', 2, 5))
            ->at(2, 4)
            ->build();

        $outerElement = $this->element('div')
            ->attribute('s:if', '$show')
            ->withChild($innerElement)
            ->build();

        $ast = $this->document()->withChild($outerElement)->build();
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
        $element = $this->element('div')
            ->attributeNode(
                $this->attributeNode(
                    's:if',
                    $this->outputNode('$dynamicValue', true, OutputContext::HTML, 1, 10),
                    1,
                    5,
                ),
            )
            ->build();

        $ast = $this->document()->withChild($element)->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Directive attributes cannot contain dynamic output expressions');

        $this->execute($ast, $this->createTestContext());
    }

    public function testHandlesSelfClosingElementWithDirective(): void
    {
        $element = $this->element('input')
            ->attributeNode($this->attribute('s:if', '$show', 1, 7))
            ->attributeNode($this->attribute('type', 'text', 1, 15))
            ->selfClosing()
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $wrappedElement = $directive->children[0];

        $this->assertInstanceOf(ElementNode::class, $wrappedElement);
        $this->assertTrue($wrappedElement->selfClosing);
    }

    public function testHandlesComplexNesting(): void
    {
        $deepElement = $this->element('p')
            ->attributeNode($this->attribute('s:unless', '$hidden', 3, 8))
            ->withChild($this->text('Deep', 3, 20))
            ->at(3, 8)
            ->build();

        $midElement = $this->element('div')
            ->attributeNode($this->attribute('s:foreach', '$items as $item', 2, 4))
            ->withChild($deepElement)
            ->at(2, 4)
            ->build();

        $topElement = $this->element('section')
            ->attributeNode($this->attribute('s:if', '$show', 1, 8))
            ->withChild($midElement)
            ->build();

        $ast = $this->document()->withChild($topElement)->build();
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

        $element = $this->element('div')
            ->attribute('x:if', '$show')
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $pipeline = new AstPipeline([$pass]);
        $result = $pipeline->execute($ast, $this->createTestContext());

        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $this->assertSame('if', $directive->name);
    }

    public function testDoesNotExtractNonPrefixedAttributes(): void
    {
        $registry = $this->createTestRegistry();
        $pass = new DirectiveExtractionPass($registry, SugarConfig::withPrefix('x'));

        $element = $this->element('div')
            ->attribute('s:if', '$show')
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $pipeline = new AstPipeline([$pass]);
        $result = $pipeline->execute($ast, $this->createTestContext());

        // Should not be extracted since we're using 'x' prefix
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertCount(1, $result->children[0]->attributes);
    }
}
