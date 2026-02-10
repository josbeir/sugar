<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\OutputContext;
use Sugar\Parser\Helper\ClosingTagMarker;
use Sugar\Parser\Helper\NodeFactory;

final class NodeFactoryTest extends TestCase
{
    public function testCreatesTextNode(): void
    {
        $factory = new NodeFactory();

        $node = $factory->text('Hello', 2, 3);

        $this->assertInstanceOf(TextNode::class, $node);
        $this->assertSame('Hello', $node->content);
        $this->assertSame(2, $node->line);
        $this->assertSame(3, $node->column);
    }

    public function testCreatesOutputNode(): void
    {
        $factory = new NodeFactory();

        $node = $factory->output('$value', true, OutputContext::HTML, 1, 2, ['trim']);

        $this->assertInstanceOf(OutputNode::class, $node);
        $this->assertSame('$value', $node->expression);
        $this->assertTrue($node->escape);
        $this->assertSame(OutputContext::HTML, $node->context);
        $this->assertSame(['trim'], $node->pipes);
    }

    public function testCreatesRawPhpNode(): void
    {
        $factory = new NodeFactory();

        $node = $factory->rawPhp('echo 1;', 4, 5);

        $this->assertInstanceOf(RawPhpNode::class, $node);
        $this->assertSame('echo 1;', $node->code);
    }

    public function testCreatesAttributeNode(): void
    {
        $factory = new NodeFactory();

        $node = $factory->attribute('class', 'btn', 1, 1);

        $this->assertInstanceOf(AttributeNode::class, $node);
        $this->assertSame('class', $node->name);
        $this->assertSame('btn', $node->value);
    }

    public function testCreatesElementNode(): void
    {
        $factory = new NodeFactory();
        $attributes = [$factory->attribute('id', 'main', 1, 1)];

        $node = $factory->element('div', $attributes, false, 1, 1);

        $this->assertInstanceOf(ElementNode::class, $node);
        $this->assertSame('div', $node->tag);
        $this->assertSame($attributes, $node->attributes);
    }

    public function testCreatesFragmentNode(): void
    {
        $factory = new NodeFactory();

        $node = $factory->fragment([], true, 1, 1);

        $this->assertInstanceOf(FragmentNode::class, $node);
        $this->assertTrue($node->selfClosing);
    }

    public function testCreatesComponentNode(): void
    {
        $factory = new NodeFactory();

        $node = $factory->component('card', [], 1, 1);

        $this->assertInstanceOf(ComponentNode::class, $node);
        $this->assertSame('card', $node->name);
    }

    public function testCreatesClosingTagMarker(): void
    {
        $factory = new NodeFactory();

        $marker = $factory->closingTagMarker('div');

        $this->assertInstanceOf(ClosingTagMarker::class, $marker);
        $this->assertSame('div', $marker->tagName);
    }
}
