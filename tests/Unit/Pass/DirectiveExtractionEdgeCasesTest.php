<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\TextNode;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\Directive\ClassCompiler;
use Sugar\Directive\ContentCompiler;
use Sugar\Directive\ForeachCompiler;
use Sugar\Directive\IfCompiler;
use Sugar\Enum\OutputContext;
use Sugar\Exception\SyntaxException;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\Directive\DirectiveExtractionPass;

/**
 * Edge case tests for DirectiveExtractionPass to improve code coverage
 */
final class DirectiveExtractionEdgeCasesTest extends TestCase
{
    private DirectiveExtractionPass $pass;

    protected function setUp(): void
    {
        $registry = new DirectiveRegistry();
        $registry->register('if', IfCompiler::class);
        $registry->register('foreach', ForeachCompiler::class);
        $registry->register('class', ClassCompiler::class);
        $registry->register('text', new ContentCompiler(escape: true));
        $registry->register('html', new ContentCompiler(escape: false, context: OutputContext::RAW));

        $this->pass = new DirectiveExtractionPass($registry, new SugarConfig());
    }

    public function testThrowsWhenDirectiveAttributeContainsDynamicOutput(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Directive attributes cannot contain dynamic output expressions');

        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode(
                    's:if',
                    new OutputNode('$value', true, OutputContext::HTML, 1, 5),
                    1,
                    5,
                ),
            ],
            children: [new TextNode('Content', 1, 20)],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $this->pass->execute($ast, $this->createContext());
    }

    public function testThrowsWhenFragmentAttributeContainsDynamicOutput(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Directive attributes cannot contain dynamic output expressions');

        $fragment = new FragmentNode(
            attributes: [
                new AttributeNode(
                    's:foreach',
                    new OutputNode('$items', true, OutputContext::HTML, 1, 5),
                    1,
                    5,
                ),
            ],
            children: [new TextNode('Content', 1, 20)],
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$fragment]);
        $this->pass->execute($ast, $this->createContext());
    }

    public function testThrowsWhenFragmentHasRegularHtmlAttribute(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('<s-template> cannot have regular HTML attributes');

        $fragment = new FragmentNode(
            attributes: [
                new AttributeNode('s:if', '$show', 1, 5),
                new AttributeNode('class', 'container', 1, 15),
            ],
            children: [new TextNode('Content', 1, 20)],
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$fragment]);
        $this->pass->execute($ast, $this->createContext());
    }

    public function testThrowsWhenFragmentHasAttributeDirective(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('<s-template> cannot have attribute directives like s:class');

        $fragment = new FragmentNode(
            attributes: [
                new AttributeNode('s:class', "['active' => true]", 1, 5),
            ],
            children: [new TextNode('Content', 1, 20)],
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$fragment]);
        $this->pass->execute($ast, $this->createContext());
    }

    public function testFragmentWithContentDirective(): void
    {
        $fragment = new FragmentNode(
            attributes: [
                new AttributeNode('s:text', '$message', 1, 5),
            ],
            children: [new TextNode('Default', 1, 20)],
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$fragment]);
        $result = $this->pass->execute($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(DirectiveNode::class, $result->children[0]);
        $this->assertSame('text', $result->children[0]->name);
        $this->assertSame('$message', $result->children[0]->expression);
    }

    public function testFragmentWithControlFlowAndContentDirective(): void
    {
        $fragment = new FragmentNode(
            attributes: [
                new AttributeNode('s:if', '$show', 1, 5),
                new AttributeNode('s:html', '$content', 1, 15),
            ],
            children: [new TextNode('Default', 1, 30)],
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$fragment]);
        $result = $this->pass->execute($ast, $this->createContext());

        // Should have if directive wrapping html directive
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(DirectiveNode::class, $result->children[0]);
        $this->assertSame('if', $result->children[0]->name);
        $this->assertSame('$show', $result->children[0]->expression);

        // Inner should have html directive with children
        $this->assertCount(1, $result->children[0]->children);
    }

    public function testElementWithOnlyAttributeDirectivesNowWorks(): void
    {
        // Attribute-only directives are now supported - element remains ElementNode with compiled attributes
        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('s:class', "['active' => true]", 1, 5),
            ],
            children: [new TextNode('Content', 1, 20)],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->execute($ast, $this->createContext());

        // Should return ElementNode with compiled class attribute
        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);

        $resultElement = $result->children[0];
        $this->assertSame('div', $resultElement->tag);

        // Should have compiled class attribute (not s:class)
        $this->assertCount(1, $resultElement->attributes);
        $this->assertSame('class', $resultElement->attributes[0]->name);
        $this->assertInstanceOf(OutputNode::class, $resultElement->attributes[0]->value);
    }

    public function testElementWithContentDirectiveOnly(): void
    {
        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('s:text', '$userName', 1, 5),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->execute($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $directive = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $directive);
        $this->assertSame('text', $directive->name);
        $this->assertSame('$userName', $directive->expression);
    }

    public function testElementWithBothControlFlowAndContentDirective(): void
    {
        $element = new ElementNode(
            tag: 'span',
            attributes: [
                new AttributeNode('s:if', '$show', 1, 5),
                new AttributeNode('s:text', '$message', 1, 15),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->execute($ast, $this->createContext());

        // Should wrap: if -> element containing text directive
        $this->assertCount(1, $result->children);
        $ifDirective = $result->children[0];
        $this->assertInstanceOf(DirectiveNode::class, $ifDirective);
        $this->assertSame('if', $ifDirective->name);

        // Inner should be element
        $this->assertCount(1, $ifDirective->children);
        $element = $ifDirective->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);

        // Element's children should contain text directive
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(DirectiveNode::class, $element->children[0]);
        $this->assertSame('text', $element->children[0]->name);
    }

    public function testThrowsOnMultipleControlFlowDirectives(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Only one control flow directive allowed per element');

        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('s:if', '$condition', 1, 5),
                new AttributeNode('s:foreach', '$items as $item', 1, 20),
            ],
            children: [new TextNode('Content', 1, 50)],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $this->pass->execute($ast, $this->createContext());
    }

    public function testThrowsOnMultipleContentDirectives(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Only one content directive allowed per element');

        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('s:text', '$text', 1, 5),
                new AttributeNode('s:html', '$html', 1, 20),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $this->pass->execute($ast, $this->createContext());
    }

    protected function createContext(
        string $source = '',
        string $templatePath = 'test.sugar.php',
        bool $debug = false,
    ): CompilationContext {
        return new CompilationContext($templatePath, $source, $debug);
    }
}
