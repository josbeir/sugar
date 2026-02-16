<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawBodyNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Enum\OutputContext;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;

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

    public function testParseOutputAllowsTrailingSemicolon(): void
    {
        $source = '<?= time(); ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('time()', $ast->children[0]->expression);
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
        $this->assertTrue($element->attributes[0]->value->isStatic());
        $this->assertSame('test', $element->attributes[0]->value->static);
    }

    public function testParseAttributeWithInlineOutput(): void
    {
        $template = '<a href="<?= $url ?>">Link</a>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertSame('href', $element->attributes[0]->name);
        $this->assertTrue($element->attributes[0]->value->isOutput());
        $this->assertInstanceOf(OutputNode::class, $element->attributes[0]->value->output);
        $this->assertSame('$url', $element->attributes[0]->value->output->expression);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $element->attributes[0]->value->output->context);
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(TextNode::class, $element->children[0]);
        $this->assertSame('Link', $element->children[0]->content);
    }

    public function testParseAttributeWithInlineOutputAndPipe(): void
    {
        $template = '<a href="<?= $url |> raw() ?>">Link</a>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertSame('href', $element->attributes[0]->name);
        $this->assertTrue($element->attributes[0]->value->isOutput());
        $this->assertInstanceOf(OutputNode::class, $element->attributes[0]->value->output);
        $this->assertSame('$url', $element->attributes[0]->value->output->expression);
        $this->assertFalse($element->attributes[0]->value->output->escape);
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(TextNode::class, $element->children[0]);
        $this->assertSame('Link', $element->children[0]->content);
    }

    public function testParseAttributeWithJsonPipeUsesJsonAttributeContext(): void
    {
        $template = '<div data-config="<?= $config |> json() ?>"></div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertSame('data-config', $element->attributes[0]->name);
        $this->assertTrue($element->attributes[0]->value->isOutput());
        $this->assertInstanceOf(OutputNode::class, $element->attributes[0]->value->output);
        $this->assertSame('$config', $element->attributes[0]->value->output->expression);
        $this->assertSame(OutputContext::JSON_ATTRIBUTE, $element->attributes[0]->value->output->context);
    }

    public function testParseAttributeWithMixedOutputs(): void
    {
        $template = '<div x-data="{ data: <?= $var ?>, other: \'<?= $name ?>\' }"></div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);

        $attribute = $element->attributes[0];
        $this->assertSame('x-data', $attribute->name);
        $this->assertTrue($attribute->value->isParts());
        $parts = $attribute->value->toParts() ?? [];
        $this->assertCount(5, $parts);

        $this->assertSame('{ data: ', $parts[0]);
        $this->assertInstanceOf(OutputNode::class, $parts[1]);
        $this->assertSame('$var', $parts[1]->expression);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $parts[1]->context);
        $this->assertSame(", other: '", $parts[2]);
        $this->assertInstanceOf(OutputNode::class, $parts[3]);
        $this->assertSame('$name', $parts[3]->expression);
        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $parts[3]->context);
        $this->assertSame("' }", $parts[4]);
    }

    public function testParseAttributeContinuationAfterInlineOutput(): void
    {
        $template = '<img src="<?= $url ?>" alt="Logo" data-id="1" />';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertTrue($element->selfClosing);
        $this->assertCount(3, $element->attributes);
        $this->assertSame('src', $element->attributes[0]->name);
        $this->assertTrue($element->attributes[0]->value->isOutput());
        $srcOutput = $element->attributes[0]->value->output;
        $this->assertInstanceOf(OutputNode::class, $srcOutput);
        $this->assertSame('$url', $srcOutput->expression);
        $this->assertSame('alt', $element->attributes[1]->name);
        $this->assertSame('Logo', $element->attributes[1]->value->static);
        $this->assertSame('data-id', $element->attributes[2]->name);
        $this->assertSame('1', $element->attributes[2]->value->static);
    }

    public function testParseAttributeContinuationWithOutputAttribute(): void
    {
        $template = '<div data-id="<?= $id ?>" other="<?= $other ?>"></div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(2, $element->attributes);
        $this->assertSame('data-id', $element->attributes[0]->name);
        $this->assertTrue($element->attributes[0]->value->isOutput());
        $dataIdOutput = $element->attributes[0]->value->output;
        $this->assertInstanceOf(OutputNode::class, $dataIdOutput);
        $this->assertSame('$id', $dataIdOutput->expression);
        $this->assertSame('other', $element->attributes[1]->name);
        $this->assertTrue($element->attributes[1]->value->isOutput());
        $otherOutput = $element->attributes[1]->value->output;
        $this->assertInstanceOf(OutputNode::class, $otherOutput);
        $this->assertSame('$other', $otherOutput->expression);
    }

    public function testParseAttributeContinuationWithSingleQuotes(): void
    {
        $template = '<a href=\'<?= $url ?>\' class="btn">Link</a>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(2, $element->attributes);
        $this->assertSame('href', $element->attributes[0]->name);
        $this->assertTrue($element->attributes[0]->value->isOutput());
        $hrefOutput = $element->attributes[0]->value->output;
        $this->assertInstanceOf(OutputNode::class, $hrefOutput);
        $this->assertSame('$url', $hrefOutput->expression);
        $this->assertSame('class', $element->attributes[1]->name);
        $this->assertSame('btn', $element->attributes[1]->value->static);
    }

    public function testParseAttributeContinuationWithoutQuotes(): void
    {
        $template = '<div data-id=<?= $id ?> class="box"></div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(2, $element->attributes);
        $this->assertSame('data-id', $element->attributes[0]->name);
        $this->assertTrue($element->attributes[0]->value->isOutput());
        $dataIdOutput = $element->attributes[0]->value->output;
        $this->assertInstanceOf(OutputNode::class, $dataIdOutput);
        $this->assertSame('$id', $dataIdOutput->expression);
        $this->assertSame('class', $element->attributes[1]->name);
        $this->assertSame('box', $element->attributes[1]->value->static);
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

    public function testParseImplicitSelfClosingTag(): void
    {
        $template = '<img src="logo.png"><div>After</div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(2, $doc->children);

        $img = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $img);
        $this->assertSame('img', $img->tag);
        $this->assertTrue($img->selfClosing);
        $this->assertCount(0, $img->children);

        $div = $doc->children[1];
        $this->assertInstanceOf(ElementNode::class, $div);
        $this->assertSame('div', $div->tag);
    }

    public function testParseVoidElementWithoutSlash(): void
    {
        $template = '<meta charset="utf-8"><div>Content</div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(2, $doc->children);

        $meta = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $meta);
        $this->assertSame('meta', $meta->tag);
        $this->assertTrue($meta->selfClosing);
        $this->assertCount(0, $meta->children);

        $div = $doc->children[1];
        $this->assertInstanceOf(ElementNode::class, $div);
        $this->assertSame('div', $div->tag);
        $this->assertCount(1, $div->children);
        $this->assertInstanceOf(TextNode::class, $div->children[0]);
        $this->assertSame('Content', $div->children[0]->content);
    }

    public function testParseDirectiveAttribute(): void
    {
        $template = '<div s:if="$condition">text</div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertSame('s:if', $element->attributes[0]->name);
        $this->assertTrue($element->attributes[0]->value->isStatic());
        $this->assertSame('$condition', $element->attributes[0]->value->static);
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

    public function testParseRawDirectivePreservesOutputAsText(): void
    {
        $template = '<div s:raw><?= $var ?></div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertSame('div', $element->tag);
        $this->assertCount(0, $element->attributes);
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(RawBodyNode::class, $element->children[0]);
        $this->assertSame('<?= $var ?>', $element->children[0]->content);
    }

    public function testParseRawDirectivePreservesPhpBlockAsText(): void
    {
        $template = '<div s:raw><?php echo strtoupper($name); ?></div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(0, $element->attributes);
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(RawBodyNode::class, $element->children[0]);
        $this->assertSame('<?php echo strtoupper($name); ?>', $element->children[0]->content);
    }

    public function testParseRawDirectivePreservesNestedMarkupAsText(): void
    {
        $template = '<div s:raw><span><?= $name ?></span></div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(RawBodyNode::class, $element->children[0]);
        $this->assertSame('<span><?= $name ?></span>', $element->children[0]->content);
    }

    public function testParseRawDirectiveKeepsNonRawAttributes(): void
    {
        $template = '<div class="card" s:raw><?= $var ?></div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertSame('class', $element->attributes[0]->name);
        $this->assertTrue($element->attributes[0]->value->isStatic());
        $this->assertSame('card', $element->attributes[0]->value->static);
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(RawBodyNode::class, $element->children[0]);
        $this->assertSame('<?= $var ?>', $element->children[0]->content);
    }

    public function testParseRawDirectiveStripsAttributeOnSelfClosingTag(): void
    {
        $template = '<div s:raw />';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertTrue($element->selfClosing);
        $this->assertCount(0, $element->attributes);
    }

    public function testParseRawDirectiveStripsAttributeOnVoidTag(): void
    {
        $template = '<img s:raw src="logo.png">';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertTrue($element->selfClosing);
        $this->assertCount(1, $element->attributes);
        $this->assertSame('src', $element->attributes[0]->name);
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
        $this->assertSame('text', $input->attributes[0]->value->static);
        $this->assertSame('name', $input->attributes[1]->name);
        $this->assertSame('username', $input->attributes[1]->value->static);
        $this->assertSame('class', $input->attributes[2]->name);
        $this->assertSame('form-control', $input->attributes[2]->value->static);
        $this->assertSame('required', $input->attributes[3]->name);
        $this->assertTrue($input->attributes[3]->value->isBoolean());
    }

    public function testParseUnquotedAttributeValue(): void
    {
        $template = '<div data-id=123></div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertSame('data-id', $element->attributes[0]->name);
        $this->assertSame('123', $element->attributes[0]->value->static);
    }

    public function testParseQuotedAttributeWithEscapedQuote(): void
    {
        $template = '<div title="He said \\"hi\\""></div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertSame('title', $element->attributes[0]->name);
        $this->assertSame('He said "hi"', $element->attributes[0]->value->static);
    }

    public function testParseAttributeWithEqualsButNoValue(): void
    {
        $template = '<div data-empty=></div>';
        $doc = $this->parser->parse($template);

        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertSame('data-empty', $element->attributes[0]->name);
        $this->assertTrue($element->attributes[0]->value->isStatic());
        $this->assertSame('', $element->attributes[0]->value->static);
    }

    public function testParseDoctypeAsTextNode(): void
    {
        $template = '<!DOCTYPE html><div>ok</div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(2, $doc->children);
        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
        $this->assertSame('<!DOCTYPE html>', $doc->children[0]->content);
        $this->assertInstanceOf(ElementNode::class, $doc->children[1]);
        $this->assertSame('div', $doc->children[1]->tag);
    }

    public function testParseCdataAsTextNode(): void
    {
        $template = '<![CDATA[Hello]]><div>ok</div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(2, $doc->children);
        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
        $this->assertSame('<![CDATA[Hello]]>', $doc->children[0]->content);
    }

    public function testParseXmlProcessingInstructionAsTextNode(): void
    {
        $template = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<div>ok</div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(3, $doc->children);
        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
        $this->assertSame('<?xml version="1.0" encoding="utf-8"?>', $doc->children[0]->content);
        $this->assertInstanceOf(TextNode::class, $doc->children[1]);
        $this->assertSame("\n", $doc->children[1]->content);
        $this->assertInstanceOf(ElementNode::class, $doc->children[2]);
        $this->assertSame('div', $doc->children[2]->tag);
    }

    public function testParseXslProcessingInstructionAsTextNode(): void
    {
        $template = '<?xsl-stylesheet type="text/xsl" href="style.xsl"?>' . "\n" . '<root/>';
        $doc = $this->parser->parse($template);

        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
        $this->assertSame('<?xsl-stylesheet type="text/xsl" href="style.xsl"?>', $doc->children[0]->content);
    }

    public function testParseSpecialTagWithoutClosingBracket(): void
    {
        $template = '<!DOCTYPE html';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
        $this->assertSame('<!DOCTYPE html', $doc->children[0]->content);
    }

    public function testParseClosingTagWithoutEndBracket(): void
    {
        $template = '</div';
        $doc = $this->parser->parse($template);

        $this->assertCount(0, $doc->children);
    }

    public function testParseClosingTagWithTrailingWhitespace(): void
    {
        $template = '<div>text</div   >';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $element = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertSame('div', $element->tag);
        $this->assertCount(1, $element->children);
        $this->assertInstanceOf(TextNode::class, $element->children[0]);
        $this->assertSame('text', $element->children[0]->content);
    }

    public function testParseRawPipeDisablesEscaping(): void
    {
        $source = '<?= $html |> raw() ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$html', $ast->children[0]->expression);
        $this->assertNull($ast->children[0]->pipes);
        $this->assertFalse($ast->children[0]->escape, 'raw() pipe should disable escaping');
    }

    public function testParseRawPipeWithWhitespace(): void
    {
        $source = '<?= $content |> raw() ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$content', $ast->children[0]->expression);
        $this->assertFalse($ast->children[0]->escape, 'raw() pipe should disable escaping');
    }

    public function testParseRawPipeWithComplexExpression(): void
    {
        $source = '<?= $user->htmlBio |> raw() ?>';
        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$user->htmlBio', $ast->children[0]->expression);
        $this->assertFalse($ast->children[0]->escape);
    }

    public function testParseRawPipeWithExtraWhitespace(): void
    {
        $source = '<?= $var |> raw() ?>';
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

    public function testParseOtherFunctionsStillEscape(): void
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

    public function testParsePipeWithRawPipe(): void
    {
        $source = '<?= $html |> upper(...) |> raw() ?>';
        $ast = $this->parser->parse($source);

        $output = $ast->children[0];
        $this->assertInstanceOf(OutputNode::class, $output);
        $this->assertSame('$html', $output->expression);
        $this->assertFalse($output->escape); // raw() pipe disables escaping
        $this->assertNotNull($output->pipes);
        $this->assertSame(['upper(...)'], $output->pipes);
    }

    public function testParseNestedElementsWithUnmatchedClosingTags(): void
    {
        // Closing tag without opening - should be skipped
        $template = '</notopened><div>ok</div>';
        $doc = $this->parser->parse($template);

        // The closing tag is skipped, only div remains
        $this->assertCount(1, $doc->children);
        $this->assertInstanceOf(ElementNode::class, $doc->children[0]);
        $this->assertSame('div', $doc->children[0]->tag);
    }

    public function testParseMalformedAttributeWithMissingValue(): void
    {
        // Missing attribute value after =
        $template = '<div class=>content</div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $this->assertInstanceOf(ElementNode::class, $doc->children[0]);
        $this->assertSame('div', $doc->children[0]->tag);
        // Attribute should still be parsed even with no value
        $this->assertCount(1, $doc->children[0]->attributes);
        $this->assertSame('class', $doc->children[0]->attributes[0]->name);
    }

    public function testParseAttributeWithPhpExpressionEdgeCases(): void
    {
        // PHP expression in attribute that ends at tag close
        $template = '<div data-value="<?= $x + 1 ?>">ok</div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $elem = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $elem);
        $this->assertSame('div', $elem->tag);
        $this->assertNotEmpty($elem->attributes);
        $this->assertSame('data-value', $elem->attributes[0]->name);
    }

    public function testParseVoidElementsWithAttributes(): void
    {
        // Void element (img) with various attribute types
        $template = '<img src="pic.png" alt="Photo" data-id="123">';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $elem = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $elem);
        $this->assertSame('img', $elem->tag);
        $this->assertCount(3, $elem->attributes);
        $this->assertSame('src', $elem->attributes[0]->name);
        $this->assertSame('alt', $elem->attributes[1]->name);
        $this->assertSame('data-id', $elem->attributes[2]->name);
    }

    public function testParseMultilineAttributes(): void
    {
        // Attributes spanning multiple lines
        $template = <<<EOT
<div
  class="container"
  id="main"
  data-config="json">
  content
</div>
EOT;
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $elem = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $elem);
        $this->assertSame('div', $elem->tag);
        $this->assertCount(3, $elem->attributes);
        $this->assertSame('class', $elem->attributes[0]->name);
        $this->assertSame('id', $elem->attributes[1]->name);
        $this->assertSame('data-config', $elem->attributes[2]->name);
    }

    public function testParseProcessingInstructionWithAttributes(): void
    {
        // Processing instruction with complex content
        $template = '<?xsl-stylesheet type="text/xsl" href="style.xsl" version="1.0"?>' . "\n" . '<root/>';
        $doc = $this->parser->parse($template);

        // PI should be text node
        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
        $this->assertStringContainsString('xsl-stylesheet', $doc->children[0]->content);
    }

    public function testParseEmptyCdataSection(): void
    {
        $template = '<![CDATA[]]><div/>';
        $doc = $this->parser->parse($template);

        $this->assertCount(2, $doc->children);
        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
        $this->assertSame('<![CDATA[]]>', $doc->children[0]->content);
    }

    public function testParseNestedRawRegionsWithComplexContent(): void
    {
        // Raw region with nested HTML-like content
        $template = <<<EOT
<div s:raw>
  <script>
    var x = "<?= \$value ?>";
    if (x > 100) {}
  </script>
</div>
EOT;
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $elem = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $elem);

        // Raw body should be preserved as-is
        $this->assertCount(1, $elem->children);
        $this->assertInstanceOf(Node::class, $elem->children[0]);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════╗
     * LEXER COVERAGE IMPROVEMENT TESTS                                                  ║
     * ═════════════════════════════════════════════════════════════════════════════════╝
     */

    // ────────────────────────────────────────────────────────────────────────────────
    // isTagStart / scanText / scanHtmlText coverage improvements
    // ────────────────────────────────────────────────────────────────────────────────

    public function testParseTextWithLessThanNotFollowedByTag(): void
    {
        // Test where less-than is not followed by a tag (improves isTagStart coverage)
        $template = 'Price: 5 < 10 and 3 > 1';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
        $this->assertStringContainsString('5 < 10', $doc->children[0]->content);
    }

    public function testParseTextWithMultipleLessThanCharacters(): void
    {
        // Multiple < characters that aren't tags
        $template = 'Math: x < y < z';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $text = $doc->children[0];
        $this->assertInstanceOf(TextNode::class, $text);
        $this->assertStringContainsString('x < y < z', $text->content);
    }

    public function testParseLessThanFollowedByNumbers(): void
    {
        // Less-than followed by numbers (not a tag)
        $template = 'Count: <123>';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // scanAttributes / scanQuotedAttributeValue coverage improvements
    // ────────────────────────────────────────────────────────────────────────────────

    public function testParseAttributeWithEscapedQuotes(): void
    {
        // Attributes with escaped quotes (exercises quote and attribute handling)
        $template = '<div data-value="He said &quot;hello&quot;">text</div>';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $elem = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $elem);
        $this->assertCount(1, $elem->attributes);
    }

    public function testParseAttributeWithMixedQuoteStyles(): void
    {
        // Mix of single and double quoted attributes
        $template = '<div class="main" id=\'myid\' data-value="test">content</div>';
        $doc = $this->parser->parse($template);

        $elem = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $elem);
        $this->assertCount(3, $elem->attributes);
    }

    public function testParseAttributeWithoutValue(): void
    {
        // Boolean attributes (no value)
        $template = '<button disabled>Click</button>';
        $doc = $this->parser->parse($template);

        $elem = $doc->children[0];
        $this->assertCount(1, $elem->attributes);
        $attr = $elem->attributes[0];
        $this->assertSame('disabled', $attr->name);
    }

    public function testParseMultipleBooleanAttributes(): void
    {
        // Multiple boolean attributes
        $template = '<input type="text" required disabled>';
        $doc = $this->parser->parse($template);

        $elem = $doc->children[0];
        $this->assertCount(3, $elem->attributes);
    }

    public function testParseAttributeWithPhpExpressionInQuotes(): void
    {
        // PHP expression inside quoted attribute
        $template = '<div data-id="<?= $id ?>">content</div>';
        $doc = $this->parser->parse($template);

        $elem = $doc->children[0];
        $attr = $elem->attributes[0];
        $this->assertSame('data-id', $attr->name);
    }

    public function testParseAttributeWithMultiplePhpInAttribute(): void
    {
        // Multiple PHP expressions in one attribute value
        $template = '<div data-info="<?= $first ?> and <?= $second ?>">text</div>';
        $doc = $this->parser->parse($template);

        $elem = $doc->children[0];
        $attr = $elem->attributes[0];
        $this->assertSame('data-info', $attr->name);
    }

    public function testParseAttributeWithSpecialCharactersUnquoted(): void
    {
        // Unquoted attribute with special characters
        $template = '<div data-value=test123>content</div>';
        $doc = $this->parser->parse($template);

        $elem = $doc->children[0];
        $this->assertCount(1, $elem->attributes);
        $this->assertSame('data-value', $elem->attributes[0]->name);
    }

    public function testParseAttributeWithDashAndUnderscore(): void
    {
        // Attribute names with dashes and underscores
        $template = '<div data-my_attr="val" aria-label-id_test="aria">text</div>';
        $doc = $this->parser->parse($template);

        $elem = $doc->children[0];
        $this->assertCount(2, $elem->attributes);
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Raw region scanning and emitAttributesFromString coverage
    // ────────────────────────────────────────────────────────────────────────────────

    public function testParseRawRegionWithAttributesAndPhp(): void
    {
        // Raw region with opening tag containing PHP-like attribute values
        $template = <<<EOT
<div s:raw data-config="<?= \$config ?>" id="test">
  <![CDATA[
    var x = <?= \$x ?>;
  ]]>
</div>
EOT;
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $elem = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $elem);
    }

    public function testParseRawRegionWithMultipleAttributes(): void
    {
        // Raw region with many attributes
        $template = <<<EOT
<script s:raw type="text/javascript" src="file.js" data-module="main" async defer>
  var code = "<?= \$code ?>";
</script>
EOT;
        $doc = $this->parser->parse($template);

        $script = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $script);
    }

    public function testParseRawRegionWithAttributeQuotesAndSpecialChars(): void
    {
        // Raw region attributes with various quote and special character scenarios
        $template = <<<EOT
<pre s:raw class="code-block" data-value='single-quoted' id=unquoted>
  <?php
    echo "content";
  ?>
</pre>
EOT;
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // scanText / Lexer state transitions
    // ────────────────────────────────────────────────────────────────────────────────

    public function testParseTextWithInterruptedByRawRegion(): void
    {
        // Text before, during, and after raw region
        $template = 'Before <div s:raw>raw content</div> After';
        $doc = $this->parser->parse($template);

        $this->assertCount(3, $doc->children);
        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
        $this->assertInstanceOf(ElementNode::class, $doc->children[1]);
        $this->assertInstanceOf(TextNode::class, $doc->children[2]);
    }

    public function testParseTextWithPhpAndHtmlMixed(): void
    {
        // Mix of text with PHP outputs and HTML
        $template = 'Hello <?= $name ?> from <strong><?= $place ?></strong>';
        $doc = $this->parser->parse($template);

        // Should have: Text + Output + Text + Element (with Output inside)
        $this->assertGreaterThan(2, count($doc->children));
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // findsimpletag / findMatchingCloseTag / extractSimpleTag coverage
    // ────────────────────────────────────────────────────────────────────────────────

    public function testParseNestedElementsDeepNesting(): void
    {
        // Deeply nested elements (exercises findMatchingCloseTag)
        $template = '<div><p><span><strong>deep</strong></span></p></div>';
        $doc = $this->parser->parse($template);

        $div = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $div);
        $this->assertSame('div', $div->tag);
    }

    public function testParseNestedElementsSameName(): void
    {
        // Nested elements with same tag name (stress test for depth tracking)
        $template = '<div><div><div>nested</div></div></div>';
        $doc = $this->parser->parse($template);

        $outer = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $outer);
        $this->assertSame('div', $outer->tag);
        $this->assertCount(1, $outer->children);
    }

    public function testParseTagWithComplexAttributes(): void
    {
        // Complex tag with many attributes (exercises scanAttributes)
        $template = '<div class="main" id="app" data-module="core" aria-label="Main" role="main" style="color: red;">content</div>';
        $doc = $this->parser->parse($template);

        $elem = $doc->children[0];
        $this->assertGreaterThanOrEqual(6, count($elem->attributes));
    }

    public function testParseVoidElementWithAttributes(): void
    {
        // Void elements with attributes (br, img, input, etc.)
        $template = '<img src="test.jpg" alt="test" width="100" height="100">';
        $doc = $this->parser->parse($template);

        $img = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $img);
        $this->assertTrue($img->selfClosing);
    }

    public function testParseMultipleVoidElements(): void
    {
        // Multiple void elements in sequence
        $template = '<br><hr><img src="a.jpg"><meta name="test"><link rel="style">';
        $doc = $this->parser->parse($template);

        $this->assertGreaterThan(4, count($doc->children));
    }

    public function testParseVoidElementWithSlashInAttribute(): void
    {
        // Void element with / in attribute value (don't confuse with self-closing)
        $template = '<img src="path/to/image.jpg" alt="path/test">';
        $doc = $this->parser->parse($template);

        $img = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $img);
        $this->assertTrue($img->selfClosing);
    }

    public function testParseExplicitSelfClosingTag(): void
    {
        // Explicit self-closing syntax />
        $template = '<custom-element attr="value" />';
        $doc = $this->parser->parse($template);

        $elem = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $elem);
        $this->assertTrue($elem->selfClosing);
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Processing instruction edge cases
    // ────────────────────────────────────────────────────────────────────────────────

    public function testParseProcessingInstructionVariants(): void
    {
        // Various processing instruction formats
        $template = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<?xml-stylesheet type="text/xsl" href="style.xsl"?>
<?custom-pi attr="value"?>
<root/><?php echo "test"; ?>
EOT;
        $doc = $this->parser->parse($template);

        // Should parse without errors
        $this->assertGreaterThan(0, count($doc->children));
    }

    public function testParseProcessingInstructionWithSpecialChars(): void
    {
        // PI with special characters and quotes
        $template = '<?xml version="1.0" encoding="iso-8859-1"?><root/>';
        $doc = $this->parser->parse($template);

        $this->assertCount(2, $doc->children);
        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Special HTML / Comment scanning
    // ────────────────────────────────────────────────────────────────────────────────

    public function testParseHtmlCommentWithSpecialContent(): void
    {
        // HTML comment with nested markup and PHP expressions
        $template = '<!-- This is a <comment> with "quotes" and <?= $var ?>  -->';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $this->assertInstanceOf(TextNode::class, $doc->children[0]);
        $this->assertStringContainsString('comment', $doc->children[0]->content);
    }

    public function testParseDocTypeDeclaration(): void
    {
        // DOCTYPE declaration
        $template = '<!DOCTYPE html><html><body>test</body></html>';
        $doc = $this->parser->parse($template);

        // First child is DOCTYPE as text
        $this->assertGreaterThan(0, count($doc->children));
    }

    public function testParseCdataWithContent(): void
    {
        // CDATA section with nested markup and PHP-like content
        $template = '<root><![CDATA[Content with <tags> and <?php code?>]]></root>';
        $doc = $this->parser->parse($template);

        $root = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $root);
        $this->assertCount(1, $root->children);
    }

    public function testParseMixedSpecialTags(): void
    {
        // Mix of DOCTYPE, CDATA, PI
        $template = <<<EOT
<?xml version="1.0"?>
<!DOCTYPE html>
<root>
  <![CDATA[Raw content]]>
  <!-- Comment -->
  <?target instruction?>
</root>
EOT;
        $doc = $this->parser->parse($template);

        // Should parse multiple special tags without error
        $this->assertGreaterThan(0, count($doc->children));
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // PHP block scanning edge cases
    // ────────────────────────────────────────────────────────────────────────────────

    public function testParsePhpBlockWithoutTerminator(): void
    {
        // PHP block at end of file without proper closing
        $template = '<?php $x = 5;';
        $doc = $this->parser->parse($template);

        // Should still parse (lexer handles missing terminator)
        $this->assertGreaterThan(0, count($doc->children));
    }

    public function testParseShortOpenTagWithoutPhpKeyword(): void
    {
        // Short open tag without 'php' keyword
        $template = trim(str_replace('PHPOPEN', '<?', 'PHPOPEN $x = 5; ?>'));
        $doc = $this->parser->parse($template);

        // Should be parsed as PHP block
        $this->assertGreaterThan(0, count($doc->children));
    }

    public function testParsePhpOutputWithComplexExpression(): void
    {
        // Complex PHP output expression with ternary operator
        $template = '<?= isset($x) ? $x : "default" ?>';
        $doc = $this->parser->parse($template);

        $this->assertCount(1, $doc->children);
        $this->assertInstanceOf(OutputNode::class, $doc->children[0]);
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Combined / stress tests for multiple lexer paths
    // ────────────────────────────────────────────────────────────────────────────────

    public function testParseComplexPageStructure(): void
    {
        // Complex realistic page with multiple code paths
        $template = <<<EOT
<?xml version="1.0"?>
<!DOCTYPE html>
<!-- Main page -->
<html>
<head>
  <meta charset="utf-8">
  <title><?= {$title} ?></title>
</head>
<body>
  <div class="container" data-id="<?= {$id} ?>">
    <h1><?= {$heading} ?></h1>
    <!-- Content section -->
    <section s:raw>
      <![CDATA[
        var code = <?= \$json ?>;
      ]]>
    </section>
    <p>Price: $<?= number_format({$price}, 2) ?></p>
  </div>
</body>
</html>
EOT;
        $doc = $this->parser->parse($template);

        // Should have multiple children including text, elements, outputs
        $this->assertGreaterThan(5, count($doc->children));
    }

    public function testParseAttributeValueEdgeCases(): void
    {
        // Edge cases in attribute value parsing
        $template = '<div data-math="2 < 3 and 4 > 1" data-arrow="a => b" data-range="5...10">text</div>';
        $doc = $this->parser->parse($template);

        $div = $doc->children[0];
        $this->assertInstanceOf(ElementNode::class, $div);
        // All attributes should be parsed
        $this->assertGreaterThanOrEqual(3, count($div->attributes));
    }

    public function testParseNestedRawRegionWithAttributes(): void
    {
        // Raw region inside another element with attributes
        $template = '<article><section s:raw data-type="test" id="main"><![CDATA[content]]></section></article>';
        $doc = $this->parser->parse($template);

        $article = $doc->children[0];
        $section = $article->children[0];
        $this->assertInstanceOf(ElementNode::class, $section);
        $this->assertCount(1, $section->children); // Raw body
    }
}
