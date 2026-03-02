<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Pass\Element;

use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Pass\Element\WhitespaceTrimPass;
use Sugar\Tests\Unit\Core\Pass\MiddlewarePassTestCase;

/**
 * Unit tests for WhitespaceTrimPass.
 */
final class WhitespaceTrimPassTest extends MiddlewarePassTestCase
{
    protected function getPass(): AstPassInterface
    {
        return new WhitespaceTrimPass(new SugarConfig());
    }

    public function testIgnoresElementsWithoutTrimAttribute(): void
    {
        $element = $this->element('title')
            ->withChildren([
                $this->text("\n\t", 1, 1),
                $this->text('Glaze', 2, 1),
                $this->text("\n", 3, 1),
            ])
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertCount(3, $result->children[0]->children);
    }

    public function testRemovesTrimAttributeAndWhitespaceOnlyTextChildren(): void
    {
        $element = $this->element('title')
            ->attributeNode($this->attributeNode('s:trim', null, 1, 1))
            ->withChildren([
                $this->text("\n\t\t", 1, 1),
                $this->text('Glaze Documentation | ', 2, 1),
                $this->text("\n\t\t", 3, 1),
                $this->outputNode('$siteTitle', true, OutputContext::HTML, 4, 1),
                $this->text("\n\t", 5, 1),
            ])
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast);

        $trimmed = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $trimmed);
        $this->assertSame([], $trimmed->attributes);
        $this->assertCount(2, $trimmed->children);
        $this->assertInstanceOf(TextNode::class, $trimmed->children[0]);
        $this->assertSame('Glaze Documentation | ', $trimmed->children[0]->content);
        $this->assertInstanceOf(OutputNode::class, $trimmed->children[1]);
    }

    public function testKeepsNestedElementsWhileTrimmingOnlyWhitespaceTextNodes(): void
    {
        $element = $this->element('div')
            ->attributeNode($this->attributeNode('s:trim', null, 1, 1))
            ->withChildren([
                $this->text("\n    ", 1, 1),
                $this->element('span')
                    ->withChild($this->text('Keep', 2, 1))
                    ->build(),
                $this->text("\n", 3, 1),
            ])
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast);

        $trimmed = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $trimmed);
        $this->assertCount(1, $trimmed->children);
        $this->assertInstanceOf(ElementNode::class, $trimmed->children[0]);
        $this->assertSame('span', $trimmed->children[0]->tag);
    }

    public function testRejectsTrimWithExplicitValue(): void
    {
        $element = $this->element('title')
            ->attributeNode($this->attributeNode('s:trim', 'yes', 1, 1))
            ->withChild($this->text('Hello', 1, 1))
            ->build();

        $ast = $this->document()->withChild($element)->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:trim does not accept a value; use it as a presence-only attribute.');

        $this->execute($ast);
    }

    public function testRejectsTrimOnFragmentNode(): void
    {
        $fragment = $this->fragment(
            attributes: [$this->attributeNode('s:trim', null, 1, 1)],
            children: [$this->text('Hello', 1, 1)],
            line: 1,
            column: 1,
        );

        $ast = $this->document()->withChild($fragment)->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:trim is only supported on HTML elements, not on <s-template>.');

        $this->execute($ast);
    }

    public function testRejectsTrimOnComponentNode(): void
    {
        $component = $this->component(
            name: 'card',
            attributes: [$this->attributeNode('s:trim', null, 1, 1)],
            children: [$this->text('Hello', 1, 1)],
            line: 1,
            column: 1,
        );

        $ast = $this->document()->withChild($component)->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:trim is only supported on HTML elements, not on component tags.');

        $this->execute($ast);
    }
}
