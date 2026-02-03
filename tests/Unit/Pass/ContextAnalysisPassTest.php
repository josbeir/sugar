<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\OutputContext;
use Sugar\Pass\ContextAnalysisPass;

/**
 * Test context analysis pass
 */
final class ContextAnalysisPassTest extends TestCase
{
    private ContextAnalysisPass $pass;

    protected function setUp(): void
    {
        $this->pass = new ContextAnalysisPass();
    }

    public function testDefaultsToHtmlContext(): void
    {
        $ast = new DocumentNode([
            new TextNode('Hello ', 1, 1),
            new OutputNode('$name', true, OutputContext::HTML, 1, 7),
        ]);

        $result = $this->pass->execute($ast);

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::HTML, $result->children[1]->context);
    }

    public function testDetectsJavascriptContext(): void
    {
        $ast = new DocumentNode([
            new TextNode('<script>', 1, 1),
            new OutputNode('$data', true, OutputContext::HTML, 1, 9),
            new TextNode('</script>', 1, 15),
        ]);

        $result = $this->pass->execute($ast);

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::JAVASCRIPT, $result->children[1]->context);
    }

    public function testDetectsCssContext(): void
    {
        $ast = new DocumentNode([
            new TextNode('<style>', 1, 1),
            new OutputNode('$css', true, OutputContext::HTML, 1, 8),
            new TextNode('</style>', 1, 13),
        ]);

        $result = $this->pass->execute($ast);

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::CSS, $result->children[1]->context);
    }

    public function testHandlesNestedTags(): void
    {
        $ast = new DocumentNode([
            new TextNode('<div><script>', 1, 1),
            new OutputNode('$code', true, OutputContext::HTML, 1, 14),
            new TextNode('</script></div>', 1, 20),
        ]);

        $result = $this->pass->execute($ast);

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::JAVASCRIPT, $result->children[1]->context);
    }

    public function testResetsContextAfterClosingTag(): void
    {
        $ast = new DocumentNode([
            new TextNode('<script>', 1, 1),
            new OutputNode('$js', true, OutputContext::HTML, 1, 9),
            new TextNode('</script>', 1, 13),
            new OutputNode('$html', true, OutputContext::HTML, 1, 22),
        ]);

        $result = $this->pass->execute($ast);

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::JAVASCRIPT, $result->children[1]->context);
        $this->assertInstanceOf(OutputNode::class, $result->children[3]);
        $this->assertSame(OutputContext::HTML, $result->children[3]->context);
    }

    public function testHandlesMultipleTagsInSingleTextNode(): void
    {
        $ast = new DocumentNode([
            new TextNode('<div><script>', 1, 1),
            new OutputNode('$data', true, OutputContext::HTML, 1, 14),
            new TextNode('</script><style>', 1, 20),
            new OutputNode('$css', true, OutputContext::HTML, 1, 36),
            new TextNode('</style></div>', 1, 41),
        ]);

        $result = $this->pass->execute($ast);

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::JAVASCRIPT, $result->children[1]->context);
        $this->assertInstanceOf(OutputNode::class, $result->children[3]);
        $this->assertSame(OutputContext::CSS, $result->children[3]->context);
    }

    public function testIgnoresRawOutputNodes(): void
    {
        $ast = new DocumentNode([
            new TextNode('<script>', 1, 1),
            new OutputNode('$raw', false, OutputContext::RAW, 1, 9),
            new TextNode('</script>', 1, 14),
        ]);

        $result = $this->pass->execute($ast);

        // Raw output should not be modified
        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::RAW, $result->children[1]->context);
    }

    public function testHandlesSelfClosingTags(): void
    {
        $ast = new DocumentNode([
            new TextNode('<img src="', 1, 1),
            new OutputNode('$url', true, OutputContext::HTML, 1, 11),
            new TextNode('" />', 1, 16),
        ]);

        $result = $this->pass->execute($ast);

        // Should detect attribute context
        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $result->children[1]->context);
    }

    public function testDetectsAttributeContext(): void
    {
        $ast = new DocumentNode([
            new TextNode('<a href="', 1, 1),
            new OutputNode('$url', true, OutputContext::HTML, 1, 10),
            new TextNode('">', 1, 15),
        ]);

        $result = $this->pass->execute($ast);

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $result->children[1]->context);
    }

    public function testAttributeContextEndsAtQuote(): void
    {
        $ast = new DocumentNode([
            new TextNode('<a href="', 1, 1),
            new OutputNode('$url', true, OutputContext::HTML, 1, 10),
            new TextNode('">', 1, 15),
            new OutputNode('$text', true, OutputContext::HTML, 1, 17),
            new TextNode('</a>', 1, 23),
        ]);

        $result = $this->pass->execute($ast);

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $result->children[1]->context);
        $this->assertInstanceOf(OutputNode::class, $result->children[3]);
        $this->assertSame(OutputContext::HTML, $result->children[3]->context);
    }
}
