<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Pass\Context;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Core\Pass\Context\ContextAnalysisPass;
use Sugar\Tests\Unit\Core\Pass\MiddlewarePassTestCase;

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
        $ast = $this->document()
            ->withChildren([
                $this->text('Hello '),
                $this->outputNode('$name', true, OutputContext::HTML, 1, 7),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->containsNodeType(OutputNode::class)
            ->containsOutput();
    }

    public function testDetectsJavascriptContext(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('script')
                    ->withChild($this->outputNode('$data', true, OutputContext::HTML, 1, 9))
                    ->build(),
            )
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $script = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $script);
        $output = $script->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame(OutputContext::JAVASCRIPT, $output->context);
    }

    public function testDetectsCssContext(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('style')
                    ->withChild($this->outputNode('$css', true, OutputContext::HTML, 1, 8))
                    ->build(),
            )
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $style = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $style);
        $output = $style->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame(OutputContext::CSS, $output->context);
    }

    public function testHandlesNestedTags(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->withChild(
                        $this->element('script')
                            ->withChild($this->outputNode('$code', true, OutputContext::HTML, 1, 14))
                            ->build(),
                    )
                    ->build(),
            )
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $div = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $div);
        $script = $div->children[0];
        $this->assertInstanceOf(ElementNode::class, $script);
        $output = $script->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame(OutputContext::JAVASCRIPT, $output->context);
    }

    public function testResetsContextAfterClosingTag(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->element('script')
                    ->withChild($this->outputNode('$js', true, OutputContext::HTML, 1, 9))
                    ->build(),
                $this->outputNode('$html', true, OutputContext::HTML, 1, 22),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $script = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $script);
        $scriptOutput = $script->children[0];
        $this->assertInstanceOf(OutputNode::class, $scriptOutput);
        $this->assertSame(OutputContext::JAVASCRIPT, $scriptOutput->context);
        $output = $result->children[1];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame(OutputContext::HTML, $output->context);
    }

    public function testHandlesMultipleTagsInSingleTextNode(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->withChildren([
                        $this->element('script')
                            ->withChild($this->outputNode('$data', true, OutputContext::HTML, 1, 14))
                            ->build(),
                        $this->element('style')
                            ->withChild($this->outputNode('$css', true, OutputContext::HTML, 1, 36))
                            ->build(),
                    ])
                    ->build(),
            )
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $div = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $div);
        $script = $div->children[0];
        $style = $div->children[1];
        $this->assertInstanceOf(ElementNode::class, $script);
        $this->assertInstanceOf(ElementNode::class, $style);
        $scriptOutput = $script->children[0];
        $styleOutput = $style->children[0];
        $this->assertInstanceOf(OutputNode::class, $scriptOutput);
        $this->assertInstanceOf(OutputNode::class, $styleOutput);
        $this->assertSame(OutputContext::JAVASCRIPT, $scriptOutput->context);
        $this->assertSame(OutputContext::CSS, $styleOutput->context);
    }

    public function testIgnoresRawOutputNodes(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('script')
                    ->withChild($this->outputNode('$raw', false, OutputContext::RAW, 1, 9))
                    ->build(),
            )
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        // Raw output should not be modified
        $script = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $script);
        $output = $script->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame(OutputContext::RAW, $output->context);
    }

    public function testPreservesJsonContext(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text(''),
                $this->outputNode('$data', true, OutputContext::JSON, 1, 1),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $this->assertInstanceOf(OutputNode::class, $result->children[1]);
        $this->assertSame(OutputContext::JSON, $result->children[1]->context);
    }

    public function testHandlesSelfClosingTags(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('img')
                    ->attributeNode($this->attributeNode(
                        'src',
                        $this->outputNode('$url', true, OutputContext::HTML, 1, 11),
                    ))
                    ->selfClosing()
                    ->build(),
            )
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        // Should detect attribute context
        $img = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $img);
        $attr = $img->attributes[0];
        $this->assertInstanceOf(AttributeNode::class, $attr);
        $this->assertTrue($attr->value->isOutput());
        $value = $attr->value->output;
        $this->assertInstanceOf(OutputNode::class, $value);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $value->context);
    }

    public function testDetectsAttributeContext(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('a')
                    ->attributeNode($this->attributeNode(
                        'href',
                        $this->outputNode('$url', true, OutputContext::HTML, 1, 10),
                    ))
                    ->build(),
            )
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $link = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $link);
        $attr = $link->attributes[0];
        $this->assertInstanceOf(AttributeNode::class, $attr);
        $this->assertTrue($attr->value->isOutput());
        $value = $attr->value->output;
        $this->assertInstanceOf(OutputNode::class, $value);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $value->context);
    }

    public function testAttributeContextEndsAtQuote(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->element('a')
                    ->attributeNode($this->attributeNode(
                        'href',
                        $this->outputNode('$url', true, OutputContext::HTML, 1, 10),
                    ))
                    ->build(),
                $this->outputNode('$text', true, OutputContext::HTML, 1, 17),
            ])
            ->build();

        $result = $this->execute($ast, $this->createTestContext());

        $link = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $link);
        $attr = $link->attributes[0];
        $this->assertInstanceOf(AttributeNode::class, $attr);
        $this->assertTrue($attr->value->isOutput());
        $value = $attr->value->output;
        $this->assertInstanceOf(OutputNode::class, $value);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $value->context);
        $output = $result->children[1];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame(OutputContext::HTML, $output->context);
    }
}
