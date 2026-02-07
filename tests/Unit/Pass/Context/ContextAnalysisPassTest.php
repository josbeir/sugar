<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Context;

use Sugar\Ast\DocumentNode;
use Sugar\Ast\OutputNode;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Enum\OutputContext;
use Sugar\Pass\Context\ContextAnalysisPass;
use Sugar\Tests\Unit\Pass\MiddlewarePassTestCase;

/**
 * Test context analysis pass
 */
final class ContextAnalysisPassTest extends MiddlewarePassTestCase
{
    protected function getPass(): AstPassInterface
    {
        return new ContextAnalysisPass();
    }

    public function testDefaultsToHtmlContext(): void
    {
        $ast = new DocumentNode([
            $this->createText('Hello '),
            new OutputNode('$name', true, OutputContext::HTML, 1, 7),
        ]);

        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->containsNodeType(OutputNode::class)
            ->containsOutput();
    }

    public function testDetectsJavascriptContext(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text('<script>'),
                $this->outputNode('$data', true, OutputContext::HTML, 1, 9),
                $this->text('</script>', 1, 15),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::JAVASCRIPT, $result->children[1]->context);
    }

    public function testDetectsCssContext(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text('<style>'),
                $this->outputNode('$css', true, OutputContext::HTML, 1, 8),
                $this->text('</style>', 1, 13),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::CSS, $result->children[1]->context);
    }

    public function testHandlesNestedTags(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text('<div><script>'),
                $this->outputNode('$code', true, OutputContext::HTML, 1, 14),
                $this->text('</script></div>', 1, 20),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::JAVASCRIPT, $result->children[1]->context);
    }

    public function testResetsContextAfterClosingTag(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text('<script>'),
                $this->outputNode('$js', true, OutputContext::HTML, 1, 9),
                $this->text('</script>', 1, 13),
                $this->outputNode('$html', true, OutputContext::HTML, 1, 22),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::JAVASCRIPT, $result->children[1]->context);
        $this->assertInstanceOf(OutputNode::class, $result->children[3]);
        $this->assertSame(OutputContext::HTML, $result->children[3]->context);
    }

    public function testHandlesMultipleTagsInSingleTextNode(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text('<div><script>'),
                $this->outputNode('$data', true, OutputContext::HTML, 1, 14),
                $this->text('</script><style>', 1, 20),
                $this->outputNode('$css', true, OutputContext::HTML, 1, 36),
                $this->text('</style></div>', 1, 41),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::JAVASCRIPT, $result->children[1]->context);
        $this->assertInstanceOf(OutputNode::class, $result->children[3]);
        $this->assertSame(OutputContext::CSS, $result->children[3]->context);
    }

    public function testIgnoresRawOutputNodes(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text('<script>'),
                $this->outputNode('$raw', false, OutputContext::RAW, 1, 9),
                $this->text('</script>', 1, 14),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        // Raw output should not be modified
        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::RAW, $result->children[1]->context);
    }

    public function testHandlesSelfClosingTags(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text('<img src="'),
                $this->outputNode('$url', true, OutputContext::HTML, 1, 11),
                $this->text('" />', 1, 16),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        // Should detect attribute context
        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $result->children[1]->context);
    }

    public function testDetectsAttributeContext(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text('<a href="'),
                $this->outputNode('$url', true, OutputContext::HTML, 1, 10),
                $this->text('">', 1, 15),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $result->children[1]->context);
    }

    public function testAttributeContextEndsAtQuote(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text('<a href="'),
                $this->outputNode('$url', true, OutputContext::HTML, 1, 10),
                $this->text('">', 1, 15),
                $this->outputNode('$text', true, OutputContext::HTML, 1, 17),
                $this->text('</a>', 1, 23),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $result->children[1]->context);
        $this->assertInstanceOf(OutputNode::class, $result->children[3]);
        $this->assertSame(OutputContext::HTML, $result->children[3]->context);
    }
}
