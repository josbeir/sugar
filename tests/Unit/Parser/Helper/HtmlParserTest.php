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

    public function testTracksAttributeLineAndColumnAcrossNewlines(): void
    {
        $parser = $this->createParser();
        $nodes = $parser->parse(
            "<div\n    class=\"card\"\n    s:blik=\"true\"></div>",
            1,
            1,
        );

        $this->assertCount(2, $nodes);
        $this->assertInstanceOf(ElementNode::class, $nodes[0]);

        $element = $nodes[0];
        $this->assertSame(1, $element->line);
        $this->assertSame(1, $element->column);
        $this->assertCount(2, $element->attributes);

        $this->assertSame(2, $element->attributes[0]->line);
        $this->assertSame(5, $element->attributes[0]->column);
        $this->assertSame('class', $element->attributes[0]->name);

        $this->assertSame(3, $element->attributes[1]->line);
        $this->assertSame(5, $element->attributes[1]->column);
        $this->assertSame('s:blik', $element->attributes[1]->name);
    }

    public function testResolvePositionHandlesEmptySourceAndLargeOffset(): void
    {
        $parser = $this->createParser();
        $resolvePosition = static function (
            HtmlParser $parser,
            string $html,
            int $offset,
            int $line,
            int $column,
        ): array {
            /** @phpstan-ignore-next-line */
            return $parser->resolvePosition($html, $offset, $line, $column);
        };
        $boundResolve = $resolvePosition->bindTo(null, HtmlParser::class);

        $emptyResult = $boundResolve($parser, '', 10, 3, 4);
        $this->assertSame([3, 4], $emptyResult);

        $clampedResult = $boundResolve($parser, 'abc', 10, 1, 1);
        $this->assertSame([1, 4], $clampedResult);
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
