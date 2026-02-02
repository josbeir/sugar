<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Config\ParserConfig;
use Sugar\Core\Parser\HtmlParser;

/**
 * Test HtmlParser - builds proper HTML tree structure
 */
final class HtmlParserTest extends TestCase
{
    private HtmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HtmlParser(new ParserConfig('s'));
    }

    public function testSimpleElement(): void
    {
        $result = $this->parser->parse('<div>content</div>', 1);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);
        $this->assertSame('div', $result[0]->tag);
        $this->assertCount(1, $result[0]->children);
        $this->assertInstanceOf(TextNode::class, $result[0]->children[0]);
        $this->assertSame('content', $result[0]->children[0]->content);
    }

    public function testNestedElements(): void
    {
        $result = $this->parser->parse('<div><span>inner</span></div>', 1);

        $this->assertCount(1, $result);
        $div = $result[0];
        $this->assertInstanceOf(ElementNode::class, $div);
        $this->assertCount(1, $div->children);

        $span = $div->children[0];
        $this->assertInstanceOf(ElementNode::class, $span);
        $this->assertSame('span', $span->tag);
        $this->assertCount(1, $span->children);
        $this->assertSame('inner', $span->children[0]->content);
    }

    public function testVoidElement(): void
    {
        $result = $this->parser->parse('<img src="test.png">', 1);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);
        $this->assertSame('img', $result[0]->tag);
        $this->assertCount(0, $result[0]->children); // Void elements have no children
    }

    public function testSelfClosingElement(): void
    {
        $result = $this->parser->parse('<img src="test.png" />', 1);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);
        $this->assertSame('img', $result[0]->tag);
        $this->assertTrue($result[0]->selfClosing);
    }

    public function testDirectiveElement(): void
    {
        $result = $this->parser->parse('<div s:if="$isAdmin">Admin</div>', 1);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(DirectiveNode::class, $result[0]);
        $this->assertSame('if', $result[0]->name);
        $this->assertSame('$isAdmin', $result[0]->expression);

        // Directive should wrap the element
        $this->assertCount(1, $result[0]->children);
        $this->assertInstanceOf(ElementNode::class, $result[0]->children[0]);
        $this->assertSame('div', $result[0]->children[0]->tag);

        // Element should have text child
        $this->assertCount(1, $result[0]->children[0]->children);
        $this->assertInstanceOf(TextNode::class, $result[0]->children[0]->children[0]);
        $this->assertSame('Admin', $result[0]->children[0]->children[0]->content);
    }

    public function testNestedDirectives(): void
    {
        $result = $this->parser->parse(
            '<ul s:if="$show"><li s:foreach="$items as $item">Item</li></ul>',
            1,
        );

        $this->assertCount(1, $result);

        // Outer directive
        $ifDirective = $result[0];
        $this->assertInstanceOf(DirectiveNode::class, $ifDirective);
        $this->assertSame('if', $ifDirective->name);

        // UL element inside if directive
        $ul = $ifDirective->children[0];
        $this->assertInstanceOf(ElementNode::class, $ul);
        $this->assertSame('ul', $ul->tag);

        // Inner directive inside ul
        $foreachDirective = $ul->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $foreachDirective);
        $this->assertSame('foreach', $foreachDirective->name);

        // LI element inside foreach directive
        $li = $foreachDirective->children[0];
        $this->assertInstanceOf(ElementNode::class, $li);
        $this->assertSame('li', $li->tag);
    }

    public function testMultipleSiblings(): void
    {
        $result = $this->parser->parse('<div>First</div><div>Second</div>', 1);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);
        $this->assertInstanceOf(ElementNode::class, $result[1]);
        $this->assertSame('First', $result[0]->children[0]->content);
        $this->assertSame('Second', $result[1]->children[0]->content);
    }

    public function testMixedContent(): void
    {
        $result = $this->parser->parse('Before <span>middle</span> After', 1);

        $this->assertCount(3, $result);
        $this->assertInstanceOf(TextNode::class, $result[0]);
        $this->assertSame('Before ', $result[0]->content);

        $this->assertInstanceOf(ElementNode::class, $result[1]);
        $this->assertSame('span', $result[1]->tag);

        $this->assertInstanceOf(TextNode::class, $result[2]);
        $this->assertSame(' After', $result[2]->content);
    }

    public function testAttributeParsing(): void
    {
        $result = $this->parser->parse('<div class="container" id="main" data-value="test">content</div>', 1);

        $this->assertCount(1, $result);
        $element = $result[0];
        $this->assertCount(3, $element->attributes);
        $this->assertSame('class', $element->attributes[0]->name);
        $this->assertSame('container', $element->attributes[0]->value);
        $this->assertSame('id', $element->attributes[1]->name);
        $this->assertSame('main', $element->attributes[1]->value);
    }

    public function testBooleanAttribute(): void
    {
        $result = $this->parser->parse('<input type="text" disabled>', 1);

        $this->assertCount(1, $result);
        $element = $result[0];
        $this->assertCount(2, $element->attributes);

        // disabled is boolean (no value)
        $disabledAttr = $element->attributes[1];
        $this->assertSame('disabled', $disabledAttr->name);
        $this->assertNull($disabledAttr->value);
    }

    public function testDeeplyNestedStructure(): void
    {
        $result = $this->parser->parse(
            '<div><ul><li><span>Deep</span></li></ul></div>',
            1,
        );

        $this->assertCount(1, $result);
        $div = $result[0];
        $ul = $div->children[0];
        $li = $ul->children[0];
        $span = $li->children[0];

        $this->assertSame('div', $div->tag);
        $this->assertSame('ul', $ul->tag);
        $this->assertSame('li', $li->tag);
        $this->assertSame('span', $span->tag);
        $this->assertSame('Deep', $span->children[0]->content);
    }

    public function testAllVoidElements(): void
    {
        $voidElements = [
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
            'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
        ];

        foreach ($voidElements as $tag) {
            $result = $this->parser->parse(sprintf('<%s>', $tag), 1);
            $this->assertCount(1, $result);
            $this->assertInstanceOf(ElementNode::class, $result[0]);
            $this->assertSame($tag, $result[0]->tag);
            $this->assertCount(0, $result[0]->children, sprintf('Void element <%s> should have no children', $tag));
        }
    }

    public function testQuotedAttributesWithSpecialChars(): void
    {
        $result = $this->parser->parse('<a href="http://example.com?foo=bar&baz=qux">Link</a>', 1);

        $this->assertCount(1, $result);
        $element = $result[0];
        $this->assertSame('href', $element->attributes[0]->name);
        $this->assertSame('http://example.com?foo=bar&baz=qux', $element->attributes[0]->value);
    }

    public function testEmptyElement(): void
    {
        $result = $this->parser->parse('<div></div>', 1);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ElementNode::class, $result[0]);
        $this->assertCount(0, $result[0]->children);
    }

    public function testPlainText(): void
    {
        $result = $this->parser->parse('Just plain text', 1);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(TextNode::class, $result[0]);
        $this->assertSame('Just plain text', $result[0]->content);
    }

    public function testComplexRealWorldExample(): void
    {
        $html = '<div class="container"><h1>Title</h1><ul s:if="$items"><li s:foreach="$items as $item">Item</li></ul></div>';
        $result = $this->parser->parse($html, 1);

        $this->assertCount(1, $result);
        $div = $result[0];
        $this->assertSame('div', $div->tag);
        $this->assertCount(2, $div->children); // h1 and directive

        $h1 = $div->children[0];
        $this->assertSame('h1', $h1->tag);

        $directive = $div->children[1];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
    }
}
