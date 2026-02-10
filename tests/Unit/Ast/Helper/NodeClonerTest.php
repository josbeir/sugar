<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\Helper\NodeCloner;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

final class NodeClonerTest extends TestCase
{
    use NodeBuildersTrait;

    public function testWithChildren(): void
    {
        $node = $this->element('div')
            ->attribute('id', 'main')
            ->withChild($this->text('old', 1, 1))
            ->at(1, 1)
            ->build();

        $newChildren = [$this->text('new', 1, 1)];
        $result = NodeCloner::withChildren($node, $newChildren);

        $this->assertNotSame($node, $result);
        $this->assertSame('div', $result->tag);
        $this->assertSame($node->attributes, $result->attributes);
        $this->assertSame($newChildren, $result->children);
    }

    public function testWithAttributesAndChildren(): void
    {
        $node = $this->element('div')
            ->attribute('id', 'old')
            ->withChild($this->text('old', 1, 1))
            ->at(1, 1)
            ->build();

        $newAttributes = [$this->attribute('id', 'new', 1, 1)];
        $newChildren = [$this->text('new', 1, 1)];
        $result = NodeCloner::withAttributesAndChildren($node, $newAttributes, $newChildren);

        $this->assertNotSame($node, $result);
        $this->assertSame('div', $result->tag);
        $this->assertSame($newAttributes, $result->attributes);
        $this->assertSame($newChildren, $result->children);
        $this->assertSame($node->selfClosing, $result->selfClosing);
        $this->assertSame($node->line, $result->line);
        $this->assertSame($node->column, $result->column);
    }

    public function testFragmentWithChildren(): void
    {
        $node = $this->fragment(
            attributes: [$this->attribute('s:if', '$show', 1, 1)],
            children: [$this->text('old', 1, 1)],
            line: 1,
            column: 1,
        );

        $newChildren = [$this->text('new', 1, 1)];
        $result = NodeCloner::fragmentWithChildren($node, $newChildren);

        $this->assertNotSame($node, $result);
        $this->assertSame($node->attributes, $result->attributes);
        $this->assertSame($newChildren, $result->children);
        $this->assertSame($node->line, $result->line);
        $this->assertSame($node->column, $result->column);
    }

    public function testFragmentWithAttributes(): void
    {
        $node = $this->fragment(
            attributes: [$this->attribute('s:if', 'old', 1, 1)],
            children: [$this->text('content', 1, 1)],
            line: 1,
            column: 1,
        );

        $newAttributes = [$this->attribute('s:if', 'new', 1, 1)];
        $result = NodeCloner::fragmentWithAttributes($node, $newAttributes);

        $this->assertNotSame($node, $result);
        $this->assertSame($newAttributes, $result->attributes);
        $this->assertSame($node->children, $result->children);
        $this->assertSame($node->line, $result->line);
        $this->assertSame($node->column, $result->column);
    }

    public function testImmutabilityWithChildren(): void
    {
        $original = $this->element('span')
            ->attribute('class', 'original')
            ->withChild($this->text('text', 1, 1))
            ->at(5, 10)
            ->build();

        $modified = NodeCloner::withChildren($original, []);

        // Original unchanged
        $this->assertCount(1, $original->children);

        // Modified is different
        $this->assertCount(0, $modified->children);
        $this->assertSame($original->tag, $modified->tag);
        $this->assertSame($original->attributes, $modified->attributes);
    }
}
