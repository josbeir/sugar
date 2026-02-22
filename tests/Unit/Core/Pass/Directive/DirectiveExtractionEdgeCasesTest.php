<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Pass\Directive;

use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\ClassDirective;
use Sugar\Core\Directive\ContentDirective;
use Sugar\Core\Directive\ForeachDirective;
use Sugar\Core\Directive\IfContentDirective;
use Sugar\Core\Directive\IfDirective;
use Sugar\Core\Directive\NoWrapDirective;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Pass\Directive\DirectiveExtractionPass;
use Sugar\Extension\FragmentCache\Directive\FragmentCacheDirective;
use Sugar\Tests\Unit\Core\Pass\MiddlewarePassTestCase;

/**
 * Edge case tests for DirectiveExtractionPass to improve code coverage
 */
final class DirectiveExtractionEdgeCasesTest extends MiddlewarePassTestCase
{
    protected function getPass(): AstPassInterface
    {
        $registry = new DirectiveRegistry();
        $registry->register('if', IfDirective::class);
        $registry->register('foreach', ForeachDirective::class);
        $registry->register('class', ClassDirective::class);
        $registry->register('text', new ContentDirective(escape: true));
        $registry->register('html', new ContentDirective(escape: false, context: OutputContext::RAW));
        $registry->register('ifcontent', IfContentDirective::class);
        $registry->register('cache', FragmentCacheDirective::class);
        $registry->register('nowrap', NoWrapDirective::class);

        return new DirectiveExtractionPass($registry, new SugarConfig());
    }

    public function testThrowsWhenFragmentAttributeContainsDynamicOutput(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Directive attributes cannot contain dynamic output expressions');

            $fragment = $this->fragment(
                attributes: [
                    $this->attributeNode(
                        's:foreach',
                        $this->outputNode('$items', true, OutputContext::HTML, 1, 5),
                        1,
                        5,
                    ),
                ],
                children: [$this->text('Content', 1, 20)],
                line: 1,
                column: 0,
            );

        $ast = $this->document()->withChild($fragment)->build();
        $this->execute($ast, $this->createTestContext());
    }

    public function testThrowsWhenFragmentHasRegularHtmlAttribute(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('<s-template> cannot have regular HTML attributes');

            $fragment = $this->fragment(
                attributes: [
                    $this->attribute('s:if', '$show', 1, 5),
                    $this->attribute('class', 'container', 1, 15),
                ],
                children: [$this->text('Content', 1, 20)],
                line: 1,
                column: 0,
            );

        $ast = $this->document()->withChild($fragment)->build();
        $this->execute($ast, $this->createTestContext());
    }

    public function testThrowsWhenFragmentHasAttributeDirective(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('<s-template> cannot have attribute directives like s:class');

            $fragment = $this->fragment(
                attributes: [
                    $this->attribute('s:class', "['active' => true]", 1, 5),
                ],
                children: [$this->text('Content', 1, 20)],
                line: 1,
                column: 0,
            );

        $ast = $this->document()->withChild($fragment)->build();
        $this->execute($ast, $this->createTestContext());
    }

    public function testFragmentWithContentDirective(): void
    {
        $fragment = $this->fragment(
            attributes: [
                $this->attribute('s:text', '$message', 1, 5),
            ],
            children: [$this->text('Default', 1, 20)],
            line: 1,
            column: 0,
        );

        $ast = $this->document()->withChild($fragment)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(DirectiveNode::class, $result->children[0]);
        $this->assertSame('text', $result->children[0]->name);
        $this->assertSame('$message', $result->children[0]->expression);
    }

    public function testFragmentWithControlFlowAndContentDirective(): void
    {
        $fragment = $this->fragment(
            attributes: [
                $this->attribute('s:if', '$show', 1, 5),
                $this->attribute('s:html', '$content', 1, 15),
            ],
            children: [$this->text('Default', 1, 30)],
            line: 1,
            column: 0,
        );

        $ast = $this->document()->withChild($fragment)->build();
        $result = $this->execute($ast, $this->createTestContext());

        // Should have if directive wrapping html directive
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(DirectiveNode::class, $result->children[0]);
        $this->assertSame('if', $result->children[0]->name);
        $this->assertSame('$show', $result->children[0]->expression);

        // Inner should have html directive with children
        $this->assertCount(1, $result->children[0]->children);
    }

    public function testElementWithOnlyAttributeDirectivesNowWorks(): void
    {
        // Attribute-only directives are now supported - element remains ElementNode with compiled attributes
        $element = $this->element('div')
            ->attribute('s:class', "['active' => true]")
            ->withChild($this->text('Content', 1, 20))
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        // Should return ElementNode with compiled class attribute
        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);

        $resultElement = $result->children[0];
        $this->assertSame('div', $resultElement->tag);

        // Should have compiled class attribute (not s:class)
        $this->assertCount(1, $resultElement->attributes);
        $this->assertSame('class', $resultElement->attributes[0]->name);
        $this->assertTrue($resultElement->attributes[0]->value->isOutput());
        $this->assertInstanceOf(OutputNode::class, $resultElement->attributes[0]->value->output);
    }

    public function testElementWithContentDirectiveOnly(): void
    {
        $element = $this->element('div')
            ->attribute('s:text', '$userName')
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $this->assertSame('text', $directive->name);
        $this->assertSame('$userName', $directive->expression);
    }

    public function testElementWithBothControlFlowAndContentDirective(): void
    {
        $element = $this->element('span')
            ->attribute('s:if', '$show')
            ->attribute('s:text', '$message')
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        // Should wrap: if -> element containing text directive
        $this->assertCount(1, $result->children);
        $ifDirective = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $ifDirective);
        $this->assertSame('if', $ifDirective->name);

        // Inner should be element
        $this->assertCount(1, $ifDirective->children);
        $element = $ifDirective->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);

        // Element's children should contain text directive
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(DirectiveNode::class, $element->children[0]);
        $this->assertSame('text', $element->children[0]->name);
    }

    public function testElementWithContentDirectiveNoWrap(): void
    {
        $element = $this->element('div')
            ->attribute('s:text', '$userName')
            ->attributeNode($this->attributeNode('s:nowrap', null, 1, 20))
            ->withChild($this->text('Ignored', 1, 30))
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertCount(1, $result->children);
        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $this->assertSame('text', $directive->name);
        $this->assertSame('$userName', $directive->expression);
        $this->assertCount(0, $directive->children);
    }

    public function testThrowsWhenNoWrapUsedWithoutContentDirective(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('requires an output directive');

        $element = $this->element('div')
            ->attributeNode($this->attributeNode('s:nowrap', null, 1, 5))
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $this->execute($ast, $this->createTestContext());
    }

    public function testThrowsWhenNoWrapHasOtherAttributes(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Output directives without a wrapper cannot include other attributes');

        $element = $this->element('div')
            ->attribute('s:text', '$userName')
            ->attributeNode($this->attributeNode('s:nowrap', null, 1, 20))
            ->attribute('class', 'badge')
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $this->execute($ast, $this->createTestContext());
    }

    public function testThrowsWhenNoWrapUsedOnFragment(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('can only be used on elements');

            $fragment = $this->fragment(
                attributes: [
                    $this->attributeNode('s:nowrap', null, 1, 5),
                ],
                children: [$this->text('Content', 1, 10)],
                line: 1,
                column: 0,
            );

        $ast = $this->document()->withChild($fragment)->build();
        $this->execute($ast, $this->createTestContext());
    }

    public function testThrowsOnMultipleControlFlowDirectives(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Only one control flow directive allowed per element');

        $element = $this->element('div')
            ->attribute('s:if', '$condition')
            ->attribute('s:foreach', '$items as $item')
            ->withChild($this->text('Content', 1, 50))
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $this->execute($ast, $this->createTestContext());
    }

    public function testThrowsOnIfContentAndCacheOnSameElement(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Only one control flow directive allowed per element');

        $element = $this->element('div')
            ->attributeNode($this->attributeNode('s:ifcontent', null, 1, 5))
            ->attributeNode($this->attributeNode('s:cache', null, 1, 18))
            ->withChild($this->text('Content', 1, 30))
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $this->execute($ast, $this->createTestContext());
    }

    public function testThrowsOnMultipleContentDirectives(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Only one output directive allowed per element');

        $element = $this->element('div')
            ->attribute('s:text', '$text')
            ->attribute('s:html', '$html')
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $this->execute($ast, $this->createTestContext());
    }
}
