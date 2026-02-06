<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Tests\CompilerTestTrait;

final class ParserTest extends TestCase
{
    use CompilerTestTrait;

    protected function setUp(): void
    {
        $this->parser = $this->createParser();
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
        $this->assertInstanceOf(ElementNode::class, $h1);
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

        $outputs = array_filter($ast->children, fn(Node $n): bool => $n instanceof OutputNode);
        $this->assertCount(2, $outputs);
    }

    public function testParseSimpleHtmlElement(): void
    {
        $template = '<div>text</div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertSame('div', $element->tag);
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(TextNode::class, $element->children[0]);
        $this->assertSame('text', $element->children[0]->content);
    }

    public function testParseElementWithAttribute(): void
    {
        $template = '<div class="test">content</div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertSame('class', $element->attributes[0]->name);
        $this->assertSame('test', $element->attributes[0]->value);
    }

    public function testParseNestedElements(): void
    {
        $template = '<div><a>link</a> label</div>';
        $doc = $this->parser->parse($template);

        $div = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $div);
        $this->assertSame('div', $div->tag);
        $this->assertCount(2, $div->children);

        $a = $div->children[0];
        $this->assertInstanceOf(ElementNode::class, $a);
        $this->assertSame('a', $a->tag);
        $this->assertCount(1, $a->children);

        $aChild = $a->children[0];
        $this->assertInstanceOf(TextNode::class, $aChild);
        $this->assertSame('link', $aChild->content);

        $divChild = $div->children[1];
        $this->assertInstanceOf(TextNode::class, $divChild);
        $this->assertSame(' label', $divChild->content);
    }

    public function testParseSelfClosingElement(): void
    {
        $template = '<br />';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertSame('br', $element->tag);
        $this->assertTrue($element->selfClosing);
        $this->assertCount(0, $element->children);
    }

    public function testParseDirectiveAttribute(): void
    {
        $template = '<div s:if="$condition">text</div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertSame('s:if', $element->attributes[0]->name);
        $this->assertSame('$condition', $element->attributes[0]->value);
    }

    public function testParseElementWithPhpOutput(): void
    {
        $template = '<div><?= $var ?></div>';
        $doc = $this->parser->parse($template);

        $div = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $div);
        $this->assertSame('div', $div->tag);
        $this->assertCount(1, $div->children);

        $output = $div->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame('$var', $output->expression);
    }

    public function testParseRawPhpBlock(): void
    {
        $template = '<?php $x = 42; ?>Result: <?= $x ?>';
        $doc = $this->parser->parse($template);

        $this->assertCount(3, $doc->children);

        $rawPhp = $doc->children[0];
        $this->assertInstanceOf(RawPhpNode::class, $rawPhp);
        $this->assertSame('$x = 42;', $rawPhp->code);
        $this->assertInstanceOf(TextNode::class, $doc->children[1]);
        $this->assertSame('Result: ', $doc->children[1]->content);
        $this->assertInstanceOf(OutputNode::class, $doc->children[2]);
        $this->assertSame('$x', $doc->children[2]->expression);
    }

    public function testParseUnclosedPhpBlock(): void
    {
        // Valid PHP - no closing tag at end of file
        $template = '<?php $x = 42;';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $this->assertInstanceOf(RawPhpNode::class, $doc->children[0]);
        $this->assertSame('$x = 42;', $doc->children[0]->code);
    }

    public function testParseMixedContentWithUnclosedPhp(): void
    {
        $template = '<h1>Title</h1><?php echo "test";';
        $doc = $this->parser->parse($template);

        $this->assertCount(2, $doc->children);

        $h1 = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $h1);
        $this->assertSame('h1', $h1->tag);

        $h1Child = $h1->children[0];
        $this->assertInstanceOf(TextNode::class, $h1Child);
        $this->assertSame('Title', $h1Child->content);

        $rawPhp = $doc->children[1];
        $this->assertInstanceOf(RawPhpNode::class, $rawPhp);
        $this->assertStringContainsString('echo "test";', $rawPhp->code);
    }

    public function testParseComplexNestedStructure(): void
    {
        $template = '<ul><li><?= $item1 ?></li><li><?= $item2 ?></li></ul>';
        $doc = $this->parser->parse($template);

        $ul = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $ul);
        $this->assertSame('ul', $ul->tag);
        $this->assertCount(2, $ul->children);

        $li1 = $ul->children[0];
        $this->assertInstanceOf(ElementNode::class, $li1);
        $this->assertSame('li', $li1->tag);
        $this->assertCount(1, $li1->children);

        $output1 = $li1->children[0];
        $this->assertInstanceOf(OutputNode::class, $output1);
        $this->assertSame('$item1', $output1->expression);

        $li2 = $ul->children[1];
        $this->assertInstanceOf(ElementNode::class, $li2);
        $this->assertSame('li', $li2->tag);
        $this->assertCount(1, $li2->children);

        $output2 = $li2->children[0];
        $this->assertInstanceOf(OutputNode::class, $output2);
        $this->assertSame('$item2', $output2->expression);
    }

    public function testParseHtmlComments(): void
    {
        $template = '<!-- Comment --><div>content</div>';
        $doc = $this->parser->parse($template);

        $this->assertGreaterThan(0, count($doc->children));

        // HTML comments are treated as text nodes
        $comment = $doc->children[0];
        $this->assertInstanceOf(TextNode::class, $comment);
        $this->assertSame('<!-- Comment -->', $comment->content);
    }

    public function testParseMultipleAttributes(): void
    {
        $template = '<input type="text" name="username" class="form-control" required />';
        $doc = $this->parser->parse($template);

        $input = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $input);
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

    public function testParseRawFunctionDisablesEscaping(): void
    {
        $source = '<?= raw($html) ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$html', $ast->children[0]->expression);
        $this->assertFalse($ast->children[0]->escape, 'raw() should disable escaping');
    }

    public function testParseShortRawFunctionDisablesEscaping(): void
    {
        $source = '<?= r($content) ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$content', $ast->children[0]->expression);
        $this->assertFalse($ast->children[0]->escape, 'r() should disable escaping');
    }

    public function testParseRawFunctionWithComplexExpression(): void
    {
        $source = '<?= raw($user->htmlBio) ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$user->htmlBio', $ast->children[0]->expression);
        $this->assertFalse($ast->children[0]->escape);
    }

    public function testParseRawFunctionWithWhitespace(): void
    {
        $source = '<?= raw( $var ) ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$var', $ast->children[0]->expression);
        $this->assertFalse($ast->children[0]->escape);
    }

    public function testParseRegularOutputStillEscapes(): void
    {
        $source = '<?= $var ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$var', $ast->children[0]->expression);
        $this->assertTrue($ast->children[0]->escape, 'Regular output should still escape');
    }

    public function testParseRawFunctionDoesNotAffectOtherFunctions(): void
    {
        $source = '<?= strtoupper($var) ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('strtoupper($var)', $ast->children[0]->expression);
        $this->assertTrue($ast->children[0]->escape, 'Other functions should not disable escaping');
    }

    public function testParseSimplePipe(): void
    {
        $source = '<?= $name |> upper(...) ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $output = $ast->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame('$name', $output->expression);
        $this->assertNotNull($output->pipes);
        $this->assertSame(['upper(...)'], $output->pipes);
    }

    public function testParseMultiplePipes(): void
    {
        $source = '<?= $name |> upper(...) |> truncate(..., 20) ?>';
        $ast = $this->parser->parse($source);

        $output = $ast->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame('$name', $output->expression);
        $this->assertNotNull($output->pipes);
        $this->assertSame(['upper(...)', 'truncate(..., 20)'], $output->pipes);
    }

    public function testParsePipeWithMultipleArguments(): void
    {
        $source = '<?= $price |> money(..., "USD", 2) ?>';
        $ast = $this->parser->parse($source);

        $output = $ast->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame('$price', $output->expression);
        $this->assertNotNull($output->pipes);
        $this->assertSame(['money(..., "USD", 2)'], $output->pipes);
    }

    public function testParsePipeWithWhitespace(): void
    {
        $source = '<?= $name  |>  upper(...)  |>  truncate(..., 10) ?>';
        $ast = $this->parser->parse($source);

        $output = $ast->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame('$name', $output->expression);
        $this->assertNotNull($output->pipes);
        $this->assertSame(['upper(...)', 'truncate(..., 10)'], $output->pipes);
    }

    public function testParseExpressionWithoutPipes(): void
    {
        $source = '<?= $name ?>';
        $ast = $this->parser->parse($source);

        $output = $ast->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame('$name', $output->expression);
        $this->assertNull($output->pipes);
    }

    public function testParsePipeWithRawFunction(): void
    {
        $source = '<?= raw($html |> upper(...)) ?>';
        $ast = $this->parser->parse($source);

        $output = $ast->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame('$html', $output->expression);
        $this->assertFalse($output->escape); // raw() disables escaping
        $this->assertNotNull($output->pipes);
        $this->assertSame(['upper(...)'], $output->pipes);
    }
}
