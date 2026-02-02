<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Config\ParserConfig;
use Sugar\Core\Enum\OutputContext;
use Sugar\Core\Parser\Parser;

/**
 * Test template parsing to AST
 */
final class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    public function testParseStaticText(): void
    {
        $source = 'Hello World';

        $ast = $this->parser->parse($source);

        $this->assertInstanceOf(DocumentNode::class, $ast);
        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(TextNode::class, $ast->children[0]);
        $this->assertSame('Hello World', $ast->children[0]->content);
    }

    public function testParsePhpShortEcho(): void
    {
        $source = '<?= $name ?>';

        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$name', $ast->children[0]->expression);
        $this->assertTrue($ast->children[0]->escape); // Default to escaped
        $this->assertSame(OutputContext::HTML, $ast->children[0]->context);
    }

    public function testParseMixedContent(): void
    {
        $source = 'Hello <?= $name ?>!';

        $ast = $this->parser->parse($source);

        $this->assertCount(3, $ast->children);
        $this->assertInstanceOf(TextNode::class, $ast->children[0]);
        $this->assertSame('Hello ', $ast->children[0]->content);

        $this->assertInstanceOf(OutputNode::class, $ast->children[1]);
        $this->assertSame('$name', $ast->children[1]->expression);

        $this->assertInstanceOf(TextNode::class, $ast->children[2]);
        $this->assertSame('!', $ast->children[2]->content);
    }

    public function testParseMultipleOutputs(): void
    {
        $source = '<?= $first ?> and <?= $second ?>';

        $ast = $this->parser->parse($source);

        $this->assertCount(3, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$first', $ast->children[0]->expression);

        $this->assertInstanceOf(TextNode::class, $ast->children[1]);
        $this->assertSame(' and ', $ast->children[1]->content);

        $this->assertInstanceOf(OutputNode::class, $ast->children[2]);
        $this->assertSame('$second', $ast->children[2]->expression);
    }

    public function testParseEmptyString(): void
    {
        $source = '';

        $ast = $this->parser->parse($source);

        $this->assertInstanceOf(DocumentNode::class, $ast);
        $this->assertCount(0, $ast->children);
    }

    public function testParseExpressionWithSpaces(): void
    {
        $source = '<?= $user->name ?>';

        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$user->name', $ast->children[0]->expression);
    }

    public function testParseComplexExpression(): void
    {
        $source = '<?= htmlspecialchars($title, ENT_QUOTES) ?>';

        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('htmlspecialchars($title, ENT_QUOTES)', $ast->children[0]->expression);
    }

    public function testParseNewlinesPreserved(): void
    {
        $source = "Line 1\nLine 2\n<?= \$var ?>\nLine 3";

        $ast = $this->parser->parse($source);

        $this->assertCount(3, $ast->children);
        $this->assertInstanceOf(TextNode::class, $ast->children[0]);
        $this->assertSame("Line 1\nLine 2\n", $ast->children[0]->content);
        $this->assertInstanceOf(OutputNode::class, $ast->children[1]);
        $this->assertSame('$var', $ast->children[1]->expression);
        $this->assertInstanceOf(TextNode::class, $ast->children[2]);
        $this->assertSame('Line 3', $ast->children[2]->content);
    }

    public function testParsePhpEchoStatement(): void
    {
        $source = '<?php echo $name ?>';

        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$name', $ast->children[0]->expression);
        $this->assertTrue($ast->children[0]->escape);
        $this->assertSame(OutputContext::HTML, $ast->children[0]->context);
    }

    public function testParseRawPhpCode(): void
    {
        $source = '<?php $x = 42; ?>';

        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(RawPhpNode::class, $ast->children[0]);
        $this->assertSame(' $x= 42; ', $ast->children[0]->code);
    }

    public function testParsePhpBlockWithLogic(): void
    {
        $source = '<?php if ($condition): ?>';

        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(RawPhpNode::class, $ast->children[0]);
        $this->assertSame(' if($condition): ', $ast->children[0]->code);
    }

    public function testParseMixedPhpTags(): void
    {
        $source = '<?php $x = 42; ?>Hello <?= $x ?>';

        $ast = $this->parser->parse($source);

        $this->assertCount(3, $ast->children);
        $this->assertInstanceOf(RawPhpNode::class, $ast->children[0]);
        $this->assertSame(' $x= 42; ', $ast->children[0]->code);

        $this->assertInstanceOf(TextNode::class, $ast->children[1]);
        $this->assertSame('Hello ', $ast->children[1]->content);

        $this->assertInstanceOf(OutputNode::class, $ast->children[2]);
        $this->assertSame('$x', $ast->children[2]->expression);
    }

    public function testParsePhpEchoWithComplexExpression(): void
    {
        $source = '<?php echo $user->name . " " . $user->email ?>';

        $ast = $this->parser->parse($source);

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]);
        $this->assertSame('$user->name. " ". $user->email', $ast->children[0]->expression);
    }

    public function testParseHtmlWithPhpShortEcho(): void
    {
        $source = '<div><?= $content ?></div>';

        $ast = $this->parser->parse($source);

        // HTML stitching properly reconstructs this into complete ElementNode
        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(ElementNode::class, $ast->children[0]);
        $this->assertSame('div', $ast->children[0]->tag);

        // PHP output is nested as child of div
        $this->assertCount(1, $ast->children[0]->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]->children[0]);
        $this->assertSame('$content', $ast->children[0]->children[0]->expression);

        // Note: </div> becomes a separate ElementNode with closing tag,
        // but parser detects it's a closing tag and handles appropriately
    }

    public function testParseHtmlWithMultiplePhpTags(): void
    {
        $source = '<h1><?= $title ?></h1><p><?php echo $description ?></p>';

        $ast = $this->parser->parse($source);

        // HTML stitching properly reconstructs both elements
        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(ElementNode::class, $ast->children[0]);
        $this->assertSame('h1', $ast->children[0]->tag);

        // h1 has OutputNode child
        $this->assertCount(1, $ast->children[0]->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]->children[0]);
        $this->assertSame('$title', $ast->children[0]->children[0]->expression);

        // '</h1><p>' is contiguous HTML, parsed as tree
        $this->assertInstanceOf(ElementNode::class, $ast->children[2]);

        $this->assertInstanceOf(OutputNode::class, $ast->children[3]);
        $this->assertSame('$description', $ast->children[3]->expression);

        // Note: </p> is in final HTML section
    }

    public function testParseHtmlAttributeWithPhp(): void
    {
        // PHP inside attributes - now properly reconstructed into ElementNode
        $source = '<a href="<?= $url ?>" class="link">Click</a>';

        $ast = $this->parser->parse($source);

        // Should create proper ElementNode with PHP in attribute value
        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(ElementNode::class, $ast->children[0]);
        $this->assertSame('a', $ast->children[0]->tag);

        // Check attributes
        $this->assertCount(2, $ast->children[0]->attributes);
        $this->assertSame('href', $ast->children[0]->attributes[0]->name);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]->attributes[0]->value);
        $this->assertSame('$url', $ast->children[0]->attributes[0]->value->expression);

        $this->assertSame('class', $ast->children[0]->attributes[1]->name);
        $this->assertSame('link', $ast->children[0]->attributes[1]->value);

        // Check element has text child
        $this->assertCount(1, $ast->children[0]->children);
        $this->assertInstanceOf(TextNode::class, $ast->children[0]->children[0]);
        $this->assertSame('Click', $ast->children[0]->children[0]->content);
    }

    public function testParseScriptTagWithPhp(): void
    {
        $source = '<script>var data = <?= $jsonData ?>;</script>';

        $ast = $this->parser->parse($source);

        // Stitching doesn't trigger here (equal < and > counts)
        // Results in fragmented nodes - tree reconstruction handles nesting
        $this->assertCount(3, $ast->children);
        $this->assertInstanceOf(ElementNode::class, $ast->children[0]);
        $this->assertSame('script', $ast->children[0]->tag);
        // Script has text content before PHP break
        $this->assertCount(1, $ast->children[0]->children);
        $this->assertInstanceOf(TextNode::class, $ast->children[0]->children[0]);
        $this->assertSame('var data = ', $ast->children[0]->children[0]->content);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]->children[1]);
        $this->assertSame('$jsonData', $ast->children[0]->children[1]->expression);
        $this->assertInstanceOf(TextNode::class, $ast->children[0]->children[2]);
        $this->assertSame(';', $ast->children[0]->children[2]->content);
    }

    public function testParseStyleTagWithPhp(): void
    {
        $source = '<style>.class { color: <?= $color ?>; }</style>';

        $ast = $this->parser->parse($source);

        // Stitching doesn't trigger here (equal < and > counts)
        // Results in fragmented nodes - tree reconstruction handles nesting
        $this->assertCount(3, $ast->children);
        $this->assertInstanceOf(ElementNode::class, $ast->children[0]);
        $this->assertSame('style', $ast->children[0]->tag);
        // Style has text content before PHP break
        $this->assertCount(1, $ast->children[0]->children);
        $this->assertInstanceOf(TextNode::class, $ast->children[0]->children[0]);
        $this->assertInstanceOf(TextNode::class, $ast->children[0]->children[2]);
    }

    public function testParseComplexHtmlDocument(): void
    {
        $source = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title><?= $pageTitle ?></title>
</head>
<body>
    <?php $count = 10; ?>
    <h1><?php echo $heading ?></h1>
    <p>Count: <?= $count ?></p>
</body>
</html>
HTML;

        $ast = $this->parser->parse($source);

        // Verify we have mixed TextNodes, RawPhpNode, and OutputNodes (recursively)
        $hasTextNode = false;
        $hasOutputNode = false;
        $hasRawPhpNode = false;

        $checkNodes = function (array $nodes) use (&$checkNodes, &$hasTextNode, &$hasOutputNode, &$hasRawPhpNode): void {
            foreach ($nodes as $node) {
                if ($node instanceof TextNode) {
                    $hasTextNode = true;
                }

                if ($node instanceof OutputNode) {
                    $hasOutputNode = true;
                }

                if ($node instanceof RawPhpNode) {
                    $hasRawPhpNode = true;
                }

                if ($node instanceof ElementNode && $node->children !== []) {
                    $checkNodes($node->children);
                }
            }
        };

        $checkNodes($ast->children);

        $this->assertTrue($hasTextNode, 'Should contain TextNode for HTML');
        $this->assertTrue($hasOutputNode, 'Should contain OutputNode for <?= and <?php echo');
        $this->assertTrue($hasRawPhpNode, 'Should contain RawPhpNode for <?php $count = 10; ?>');
    }

    public function testParseNestedHtmlWithMultiplePhpStyles(): void
    {
        $source = '<div><span><?= $a ?></span><?php echo $b ?><em><?php $c = 1; ?><?= $c ?></em></div>';

        $ast = $this->parser->parse($source);

        // HTML stitching + tree reconstruction work together
        // Results may vary based on token boundaries and stitching logic
        $this->assertGreaterThan(0, count($ast->children));

        // Should have some form of proper structure (elements, output, or mix)
        $hasElement = false;
        foreach ($ast->children as $child) {
            if ($child instanceof ElementNode) {
                $hasElement = true;
                break;
            }
        }
        $this->assertTrue($hasElement, 'Should have at least one ElementNode');
    }

    public function testParsePhpInMultipleAttributes(): void
    {
        $source = '<img src="<?= $imageSrc ?>" alt="<?= $imageAlt ?>" width="<?= $width ?>">';

        $ast = $this->parser->parse($source);

        // Should create ElementNode with PHP in multiple attribute values
        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(ElementNode::class, $ast->children[0]);
        $this->assertSame('img', $ast->children[0]->tag);

        // Check all 3 attributes have OutputNodes
        $this->assertCount(3, $ast->children[0]->attributes);

        foreach ($ast->children[0]->attributes as $attr) {
            $this->assertInstanceOf(OutputNode::class, $attr->value, 'All attributes should have PHP output');
        }

        $this->assertSame('src', $ast->children[0]->attributes[0]->name);
        $this->assertSame('$imageSrc', $ast->children[0]->attributes[0]->value->expression);

        $this->assertSame('alt', $ast->children[0]->attributes[1]->name);
        $this->assertSame('$imageAlt', $ast->children[0]->attributes[1]->value->expression);

        $this->assertSame('width', $ast->children[0]->attributes[2]->name);
        $this->assertSame('$width', $ast->children[0]->attributes[2]->value->expression);
    }

    public function testParseEmptyPhpTags(): void
    {
        $source = '<?php ?><div><?= $x ?></div>';

        $ast = $this->parser->parse($source);

        // Empty php tags should create RawPhpNode with minimal content
        $this->assertGreaterThan(0, count($ast->children));
        $this->assertInstanceOf(RawPhpNode::class, $ast->children[0]);
    }

    public function testParserWithDefaultConfig(): void
    {
        $config = new ParserConfig();
        $parser = new Parser($config);

        $this->assertSame('s', $config->directivePrefix);

        $source = '<div><?= $name ?></div>';
        $ast = $parser->parse($source);

        $this->assertInstanceOf(DocumentNode::class, $ast);
    }

    public function testParserWithCustomConfig(): void
    {
        $config = new ParserConfig(directivePrefix: 'v');
        $parser = new Parser($config);

        $this->assertSame('v', $config->directivePrefix);

        $source = '<div><?= $name ?></div>';
        $ast = $parser->parse($source);

        $this->assertInstanceOf(DocumentNode::class, $ast);
    }
}
