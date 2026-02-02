<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Parser\Parser;

/**
 * Test PHP expressions inside HTML attributes
 *
 * IMPORTANT: token_get_all() splits templates at PHP tag boundaries.
 * This means PHP INSIDE attributes creates fragmented HTML that HtmlParser
 * cannot properly reconstruct.
 *
 * Example: <div class="prefix-<?= $var ?>-suffix">
 * Becomes: '<div class="prefix-' + OutputNode($var) + '-suffix">'
 *
 * The opening tag is incomplete, so it's treated as text, not an element.
 * This is a fundamental limitation of using token_get_all() for parsing.
 *
 * WORKAROUND: Use the full expression outside attributes:
 * - Instead of: <div class="prefix-<?= $var ?>">
 * - Use: <div class="<?= 'prefix-' . $var ?>">
 * - Or use directives: <div s:class="'prefix-' . $var">
 */
final class PhpInAttributesTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    public function testPhpInAttributeValue(): void
    {
        // Test case: PHP expression inside HTML attribute
        $source = '<div class="test-<?= $variable ?>-end">content</div>';

        $ast = $this->parser->parse($source);

        // HTML stitching now properly reconstructs this into an ElementNode
        $this->assertInstanceOf(DocumentNode::class, $ast);
        $this->assertCount(1, $ast->children);

        // Should create proper ElementNode
        $this->assertInstanceOf(ElementNode::class, $ast->children[0]);
        $this->assertSame('div', $ast->children[0]->tag);

        // The class attribute should have been parsed, but its value will contain placeholder
        // The actual behavior depends on how HtmlParser handles the stitched result
        $this->assertGreaterThanOrEqual(1, count($ast->children[0]->attributes));
    }

    public function testSimplePhpOutput(): void
    {
        $source = '<div><?= $name ?></div>';

        $ast = $this->parser->parse($source);

        // This works because token_get_all sees:
        // T_INLINE_HTML: '<div>'
        // T_OPEN_TAG_WITH_ECHO
        // T_VARIABLE: '$name'
        // T_WHITESPACE: ' '
        // T_CLOSE_TAG
        // T_INLINE_HTML: '</div>'

        // HTML stitching now properly reconstructs this into a single ElementNode
        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(ElementNode::class, $ast->children[0]);
        $this->assertSame('div', $ast->children[0]->tag);

        // Output is nested inside
        $this->assertCount(1, $ast->children[0]->children);
        $this->assertInstanceOf(OutputNode::class, $ast->children[0]->children[0]);
    }

    public function testLimitationDocumented(): void
    {
        // THE LIMITATION: token_get_all() splits at PHP boundaries
        // This means you CANNOT have PHP expressions INSIDE attribute values
        // while maintaining proper HTML structure parsing

        // BROKEN SYNTAX (splits into fragments):
        // <div class="prefix-{PHP_HERE}">

        // SOLUTION 1: Full attribute value in PHP variable
        $var = 'dynamic-value';
        $class = 'prefix-' . $var . '-suffix';
        $solution1 = '<div class="' . htmlspecialchars($class) . '">content</div>';

        // SOLUTION 2: Use template directives (best approach, coming in Step 4)
        // This will allow: <div s:class="'prefix-' . $var . '-suffix'">

        $this->assertTrue(true, 'This test documents the limitation');
        $this->assertStringContainsString('content', $solution1);
    }
}
