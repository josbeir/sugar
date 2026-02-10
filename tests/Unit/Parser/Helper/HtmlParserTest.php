<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\TextNode;
use Sugar\Config\Helper\DirectivePrefixHelper;
use Sugar\Config\SugarConfig;
use Sugar\Parser\Helper\ClosingTagMarker;
use Sugar\Parser\Helper\HtmlParser;
use Sugar\Parser\Helper\NodeFactory;

final class HtmlParserTest extends TestCase
{
    public function testParsesTextAndClosingTagMarker(): void
    {
        $parser = $this->createParser();
        $nodes = $parser->parse('Hello <strong>World</strong>', 1, 1);

        $this->assertCount(4, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('Hello ', $nodes[0]->content);
        $this->assertInstanceOf(ElementNode::class, $nodes[1]);
        $this->assertSame('strong', $nodes[1]->tag);
        $this->assertInstanceOf(TextNode::class, $nodes[2]);
        $this->assertSame('World', $nodes[2]->content);
        $this->assertInstanceOf(ClosingTagMarker::class, $nodes[3]);
        $this->assertSame('strong', $nodes[3]->tagName);
    }

    public function testParsesSelfClosingTag(): void
    {
        $parser = $this->createParser();
        $nodes = $parser->parse('<img src="sugar.png" />', 1, 1);

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(ElementNode::class, $nodes[0]);
        $this->assertSame('img', $nodes[0]->tag);
        $this->assertTrue($nodes[0]->selfClosing);
    }

    public function testParsesFragmentElement(): void
    {
        $parser = $this->createParser();
        $nodes = $parser->parse('<s-template></s-template>', 1, 1);

        $this->assertCount(2, $nodes);
        $this->assertInstanceOf(FragmentNode::class, $nodes[0]);
        $this->assertInstanceOf(ClosingTagMarker::class, $nodes[1]);
        $this->assertSame('s-template', $nodes[1]->tagName);
    }

    public function testParsesComponentElement(): void
    {
        $parser = $this->createParser();
        $nodes = $parser->parse('<s-card></s-card>', 1, 1);

        $this->assertCount(2, $nodes);
        $this->assertInstanceOf(ComponentNode::class, $nodes[0]);
        $this->assertSame('card', $nodes[0]->name);
        $this->assertInstanceOf(ClosingTagMarker::class, $nodes[1]);
        $this->assertSame('s-card', $nodes[1]->tagName);
    }

    private function createParser(): HtmlParser
    {
        $config = new SugarConfig();

        return new HtmlParser(
            $config,
            new DirectivePrefixHelper($config->directivePrefix),
            new NodeFactory(),
        );
    }
}
