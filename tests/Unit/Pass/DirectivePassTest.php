<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\OutputContext;
use Sugar\Pass\DirectivePass;

/**
 * Test DirectivePass - transforms DirectiveNodes into PHP control structures
 */
final class DirectivePassTest extends TestCase
{
    private DirectivePass $pass;

    protected function setUp(): void
    {
        $this->pass = new DirectivePass();
    }

    public function testTransformIfDirective(): void
    {
        // <div s:if="$isAdmin">Admin content</div>
        $directive = new DirectiveNode(
            name: 'if',
            expression: '$isAdmin',
            children: [
                new ElementNode(
                    tag: 'div',
                    attributes: [],
                    children: [new TextNode('Admin content', 1, 1)],
                    selfClosing: false,
                    line: 1,
                    column: 1,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$directive]);
        $result = $this->pass->transform($ast);

        // Should produce: if statement with opening, content, and closing
        $this->assertCount(3, $result->children);

        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
        $this->assertSame('if ($isAdmin):', $result->children[0]->code);

        $this->assertInstanceOf(ElementNode::class, $result->children[1]);
        $this->assertSame('div', $result->children[1]->tag);

        $this->assertInstanceOf(RawPhpNode::class, $result->children[2]);
        $this->assertSame('endif;', $result->children[2]->code);
    }

    public function testTransformIfWithElse(): void
    {
        // <div s:if="$isAdmin">Admin</div><div>Guest</div> (else parsed separately)
        // For now, test just if without else - else handling comes later
        $directive = new DirectiveNode(
            name: 'if',
            expression: '$isAdmin',
            children: [
                new TextNode('Admin', 1, 1),
            ],
            elseChildren: [
                new TextNode('Guest', 1, 1),
            ],
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$directive]);
        $result = $this->pass->transform($ast);

        // Should produce: if-else statement with opening, admin content, else, guest content, and closing
        $this->assertCount(5, $result->children);

        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
        $this->assertSame('if ($isAdmin):', $result->children[0]->code);

        $this->assertInstanceOf(TextNode::class, $result->children[1]);
        $this->assertSame('Admin', $result->children[1]->content);

        $this->assertInstanceOf(RawPhpNode::class, $result->children[2]);
        $this->assertSame('else:', $result->children[2]->code);

        $this->assertInstanceOf(TextNode::class, $result->children[3]);
        $this->assertSame('Guest', $result->children[3]->content);

        $this->assertInstanceOf(RawPhpNode::class, $result->children[4]);
        $this->assertSame('endif;', $result->children[4]->code);
    }

    public function testTransformForeachDirective(): void
    {
        // <li s:foreach="$items as $item">output</li>
        $directive = new DirectiveNode(
            name: 'foreach',
            expression: '$items as $item',
            children: [
                new ElementNode(
                    tag: 'li',
                    attributes: [],
                    children: [new OutputNode('$item', true, OutputContext::HTML, 1, 1)],
                    selfClosing: false,
                    line: 1,
                    column: 1,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$directive]);
        $result = $this->pass->transform($ast);

        // Should produce: foreach with opening, content, and closing
        $this->assertCount(3, $result->children);

        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
        $this->assertSame('foreach ($items as $item):', $result->children[0]->code);

        $this->assertInstanceOf(ElementNode::class, $result->children[1]);

        $this->assertInstanceOf(RawPhpNode::class, $result->children[2]);
        $this->assertSame('endforeach;', $result->children[2]->code);
    }

    public function testTransformWhileDirective(): void
    {
        // <div s:while="$hasMore">Loading...</div>
        $directive = new DirectiveNode(
            name: 'while',
            expression: '$hasMore',
            children: [
                new ElementNode(
                    tag: 'div',
                    attributes: [],
                    children: [new TextNode('Loading...', 1, 1)],
                    selfClosing: false,
                    line: 1,
                    column: 1,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$directive]);
        $result = $this->pass->transform($ast);

        // Should produce: while with opening, content, and closing
        $this->assertCount(3, $result->children);

        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
        $this->assertSame('while ($hasMore):', $result->children[0]->code);

        $this->assertInstanceOf(ElementNode::class, $result->children[1]);

        $this->assertInstanceOf(RawPhpNode::class, $result->children[2]);
        $this->assertSame('endwhile;', $result->children[2]->code);
    }

    public function testTransformNestedDirectives(): void
    {
        // <div s:if="$show"><li s:foreach="$items as $item">output</li></div>
        $foreachDirective = new DirectiveNode(
            name: 'foreach',
            expression: '$items as $item',
            children: [
                new ElementNode(
                    tag: 'li',
                    attributes: [],
                    children: [new OutputNode('$item', true, OutputContext::HTML, 1, 1)],
                    selfClosing: false,
                    line: 1,
                    column: 1,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $ifDirective = new DirectiveNode(
            name: 'if',
            expression: '$show',
            children: [
                new ElementNode(
                    tag: 'div',
                    attributes: [],
                    children: [$foreachDirective],
                    selfClosing: false,
                    line: 1,
                    column: 1,
                ),
            ],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$ifDirective]);
        $result = $this->pass->transform($ast);

        // Should produce: nested if/foreach structure
        $this->assertCount(3, $result->children);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
        $this->assertSame('if ($show):', $result->children[0]->code);

        // The middle element should have the foreach transformed inside
        $this->assertInstanceOf(ElementNode::class, $result->children[1]);
        $divElement = $result->children[1];
        $this->assertCount(3, $divElement->children); // foreach start, li, foreach end
    }

    public function testPassThroughNonDirectiveNodes(): void
    {
        // Mix of regular nodes and directives
        $ast = new DocumentNode([
            new TextNode('Before', 1, 1),
            new DirectiveNode(
                name: 'if',
                expression: '$x',
                children: [new TextNode('Inside', 1, 1)],
                elseChildren: null,
                line: 1,
                column: 1,
            ),
            new OutputNode('$after', true, OutputContext::HTML, 1, 1),
        ]);

        $result = $this->pass->transform($ast);

        // Before + if start + Inside + if end + $after = 5 nodes
        $this->assertCount(5, $result->children);
        $this->assertInstanceOf(TextNode::class, $result->children[0]);
        $this->assertSame('Before', $result->children[0]->content);

        $this->assertInstanceOf(RawPhpNode::class, $result->children[1]);
        $this->assertInstanceOf(TextNode::class, $result->children[2]);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[3]);

        $this->assertInstanceOf(OutputNode::class, $result->children[4]);
        $this->assertSame('$after', $result->children[4]->expression);
    }

    public function testUnsupportedDirectiveThrowsException(): void
    {
        $directive = new DirectiveNode(
            name: 'unknown',
            expression: '$value',
            children: [],
            elseChildren: null,
            line: 1,
            column: 1,
        );

        $ast = new DocumentNode([$directive]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported directive: unknown');

        $this->pass->transform($ast);
    }
}
