<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Parser\Parser;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Ast\OutputNode;

final class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    public function testParseReturnsDocumentNode(): void
    {
        $source = '<h1>Hello</h1>';
        $ast = $this->parser->parse($source);

        $this->assertInstanceOf(DocumentNode::class, $ast);
    }

    public function testParseSimpleText(): void
    {
        $source = 'Hello, World!';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(TextNode::class, $ast->children[0]);
        $this->assertSame('Hello, World!', $ast->children[0]->content);
    }

    public function testParseSimpleOutput(): void
    {
        $source = '<?= $title ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$title', $ast->children[0]->expression);
    }

    public function testParseHtmlWithOutput(): void
    {
        $source = '<h1><?= $title ?></h1>';
        $ast = $this->parser->parse($source);

        // Should have h1 element
        $this->assertCount(1, $ast->children);
        $h1 = $ast->children[0];
        $this->assertInstanceOf(\Sugar\Core\Ast\ElementNode::class, $h1);
        $this->assertSame('h1', $h1->tag);

        // h1 should contain OutputNode
        $this->assertCount(1, $h1->children);
        $this->assertInstanceOf(OutputNode::class, $h1->children[0]);
        $this->assertSame('$title', $h1->children[0]->expression);
    }

    public function testParseMultipleOutputs(): void
    {
        $source = '<?= $title ?> and <?= $subtitle ?>';
        $ast = $this->parser->parse($source);

        $outputs = array_filter($ast->children, fn($n) => $n instanceof OutputNode);
        $this->assertCount(2, $outputs);
    }

    public function testParseSimpleHtmlElement(): void
    {
        $template = '<div>text</div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $element = $doc->children[0];
        $this->assertInstanceOf(\Sugar\Core\Ast\ElementNode::class, $element);
        $this->assertSame('div', $element->tag);
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(\Sugar\Core\Ast\TextNode::class, $element->children[0]);
        $this->assertSame('text', $element->children[0]->content);
    }

    public function testParseElementWithAttribute(): void
    {
        $template = '<div class="test">content</div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(\Sugar\Core\Ast\ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertSame('class', $element->attributes[0]->name);
        $this->assertSame('test', $element->attributes[0]->value);
    }

    public function testParseNestedElements(): void
    {
        $template = '<div><a>link</a> label</div>';
        $doc = $this->parser->parse($template);

        $div = $doc->children[0];
        $this->assertSame('div', $div->tag);
        $this->assertCount(2, $div->children);

        $a = $div->children[0];
        $this->assertInstanceOf(\Sugar\Core\Ast\ElementNode::class, $a);
        $this->assertSame('a', $a->tag);
        $this->assertCount(1, $a->children);
        $this->assertSame('link', $a->children[0]->content);

        $this->assertSame(' label', $div->children[1]->content);
    }

    public function testParseSelfClosingElement(): void
    {
        $template = '<br />';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(\Sugar\Core\Ast\ElementNode::class, $element);
        $this->assertSame('br', $element->tag);
        $this->assertTrue($element->selfClosing);
        $this->assertCount(0, $element->children);
    }

    public function testParseDirectiveAttribute(): void
    {
        $template = '<div s:if="$condition">text</div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(\Sugar\Core\Ast\ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertSame('s:if', $element->attributes[0]->name);
        $this->assertSame('$condition', $element->attributes[0]->value);
    }

    public function testParseElementWithPhpOutput(): void
    {
        $template = '<div><?= $var ?></div>';
        $doc = $this->parser->parse($template);

        $div = $doc->children[0];
        $this->assertSame('div', $div->tag);
        $this->assertCount(1, $div->children);
        $this->assertInstanceOf(\Sugar\Core\Ast\OutputNode::class, $div->children[0]);
        $this->assertSame('$var', $div->children[0]->expression);
    }

    public function testParseRawPhpBlock(): void
    {
        $template = '<?php $x = 42; ?>Result: <?= $x ?>';
        $doc = $this->parser->parse($template);

        $this->assertCount(3, $doc->children);
        $this->assertInstanceOf(\Sugar\Core\Ast\RawPhpNode::class, $doc->children[0]);
        $this->assertSame('$x = 42;', $doc->children[0]->code);
        $this->assertInstanceOf(\Sugar\Core\Ast\TextNode::class, $doc->children[1]);
        $this->assertSame('Result: ', $doc->children[1]->content);
        $this->assertInstanceOf(\Sugar\Core\Ast\OutputNode::class, $doc->children[2]);
        $this->assertSame('$x', $doc->children[2]->expression);
    }

    public function testParseUnclosedPhpBlock(): void
    {
        // Valid PHP - no closing tag at end of file
        $template = '<?php $x = 42;';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $this->assertInstanceOf(\Sugar\Core\Ast\RawPhpNode::class, $doc->children[0]);
        $this->assertSame('$x = 42;', $doc->children[0]->code);
    }

    public function testParseMixedContentWithUnclosedPhp(): void
    {
        $template = '<h1>Title</h1><?php echo "test";';
        $doc = $this->parser->parse($template);

        $this->assertCount(2, $doc->children);

        $h1 = $doc->children[0];
        $this->assertInstanceOf(\Sugar\Core\Ast\ElementNode::class, $h1);
        $this->assertSame('h1', $h1->tag);
        $this->assertSame('Title', $h1->children[0]->content);

        $this->assertInstanceOf(\Sugar\Core\Ast\RawPhpNode::class, $doc->children[1]);
        $this->assertStringContainsString('echo "test";', $doc->children[1]->code);
    }

    public function testParseComplexNestedStructure(): void
    {
        $template = '<ul><li><?= $item1 ?></li><li><?= $item2 ?></li></ul>';
        $doc = $this->parser->parse($template);

        $ul = $doc->children[0];
        $this->assertSame('ul', $ul->tag);
        $this->assertCount(2, $ul->children);

        $li1 = $ul->children[0];
        $this->assertSame('li', $li1->tag);
        $this->assertCount(1, $li1->children);
        $this->assertInstanceOf(\Sugar\Core\Ast\OutputNode::class, $li1->children[0]);
        $this->assertSame('$item1', $li1->children[0]->expression);

        $li2 = $ul->children[1];
        $this->assertSame('li', $li2->tag);
        $this->assertCount(1, $li2->children);
        $this->assertInstanceOf(\Sugar\Core\Ast\OutputNode::class, $li2->children[0]);
        $this->assertSame('$item2', $li2->children[0]->expression);
    }

    public function testParseHtmlComments(): void
    {
        $template = '<!-- Comment --><div>content</div>';
        $doc = $this->parser->parse($template);

        $this->assertGreaterThan(0, count($doc->children));
        // HTML comments are treated as text nodes
        $this->assertInstanceOf(\Sugar\Core\Ast\TextNode::class, $doc->children[0]);
        $this->assertSame('<!-- Comment -->', $doc->children[0]->content);
    }

    public function testParseMultipleAttributes(): void
    {
        $template = '<input type="text" name="username" class="form-control" required />';
        $doc = $this->parser->parse($template);

        $input = $doc->children[0];
        $this->assertInstanceOf(\Sugar\Core\Ast\ElementNode::class, $input);
        $this->assertSame('input', $input->tag);
        $this->assertTrue($input->selfClosing);
        $this->assertCount(4, $input->attributes);

        $this->assertSame('type', $input->attributes[0]->name);
        $this->assertSame('text', $input->attributes[0]->value);
        $this->assertSame('name', $input->attributes[1]->name);
        $this->assertSame('username', $input->attributes[1]->value);
        $this->assertSame('class', $input->attributes[2]->name);
        $this->assertSame('form-control', $input->attributes[2]->value);
        $this->assertSame('required', $input->attributes[3]->name);
        $this->assertNull($input->attributes[3]->value); // Boolean attribute
    }
}
