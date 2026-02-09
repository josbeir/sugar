<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\TextNode;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;

final class FragmentParserTest extends TestCase
{
    use CompilerTestTrait;

    protected function setUp(): void
    {
        $this->parser = $this->createParser();
    }

    public function testParsesBasicFragment(): void
    {
        $html = '<s-template><div>Content</div></s-template>';
        $doc = $this->parser->parse($html);

        $this->assertCount(1, $doc->children);
        $this->assertInstanceOf(FragmentNode::class, $doc->children[0]);

        $fragment = $doc->children[0];
        $this->assertCount(1, $fragment->children);
    }

    public function testParsesSelfClosingFragment(): void
    {
        $html = '<s-template s:if="$show" /><div>After</div>';
        $doc = $this->parser->parse($html);

        $this->assertCount(2, $doc->children);
        $this->assertInstanceOf(FragmentNode::class, $doc->children[0]);
        $this->assertInstanceOf(ElementNode::class, $doc->children[1]);

        $fragment = $doc->children[0];
        $this->assertTrue($fragment->selfClosing);
        $this->assertCount(0, $fragment->children);
    }

    public function testParsesFragmentWithDirective(): void
    {
        $html = '<s-template s:if="$show"><div>Content</div></s-template>';
        $doc = $this->parser->parse($html);

        $this->assertCount(1, $doc->children);
        $this->assertInstanceOf(FragmentNode::class, $doc->children[0]);

        $fragment = $doc->children[0];
        $this->assertCount(1, $fragment->attributes);
        $this->assertSame('s:if', $fragment->attributes[0]->name);
        $this->assertSame('$show', $fragment->attributes[0]->value);
    }

    public function testParsesFragmentWithMultipleChildren(): void
    {
        $html = '<s-template s:if="$show">
            <h1>Title</h1>
            <p>Content</p>
        </s-template>';

        $doc = $this->parser->parse($html);

        $fragment = $doc->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        // Should have whitespace + h1 + whitespace + p + whitespace
        $this->assertGreaterThanOrEqual(3, count($fragment->children));
    }

    public function testParsesEmptyFragment(): void
    {
        $html = '<s-template></s-template>';
        $doc = $this->parser->parse($html);

        $this->assertCount(1, $doc->children);
        $fragment = $doc->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        $this->assertCount(0, $fragment->children);
    }

    public function testParsesFragmentWithTextContent(): void
    {
        $html = '<s-template>Just text</s-template>';
        $doc = $this->parser->parse($html);

        $fragment = $doc->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        $this->assertCount(1, $fragment->children);
        $this->assertInstanceOf(TextNode::class, $fragment->children[0]);
        $this->assertSame('Just text', $fragment->children[0]->content);
    }

    public function testParsesNestedFragments(): void
    {
        $html = '<s-template s:if="$outer">
            <s-template s:foreach="$items as $item">
                <div><?= $item ?></div>
            </s-template>
        </s-template>';

        $doc = $this->parser->parse($html);

        $outerFragment = $doc->children[0];
        $this->assertInstanceOf(FragmentNode::class, $outerFragment);
        $this->assertSame('s:if', $outerFragment->attributes[0]->name);
    }

    public function testParsesFragmentWithMultipleDirectives(): void
    {
        $html = '<s-template s:if="$show" s:foreach="$items as $item">
            <div><?= $item ?></div>
        </s-template>';

        $doc = $this->parser->parse($html);

        $fragment = $doc->children[0];
        $this->assertInstanceOf(FragmentNode::class, $fragment);
        $this->assertCount(2, $fragment->attributes);
    }
}
