<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Template;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Template\BlockMerger;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

final class BlockMergerTest extends TestCase
{
    use NodeBuildersTrait;

    public function testExtractBlocksInTemplateOrder(): void
    {
        $merger = new BlockMerger(new DirectivePrefixHelper('s'));
        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:block', 'sidebar')
                    ->withChild($this->text('Side'))
                    ->build(),
                $this->element('div')
                    ->attribute('s:block', 'content')
                    ->withChild($this->text('Main'))
                    ->build(),
            ])
            ->build();

        $context = new CompilationContext('page.sugar.php', '');
        $result = $merger->extractBlocks($document, ['content', 'sidebar'], $context);

        $this->assertCount(2, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertInstanceOf(ElementNode::class, $result->children[1]);
        $this->assertInstanceOf(TextNode::class, $result->children[0]->children[0]);
        $this->assertInstanceOf(TextNode::class, $result->children[1]->children[0]);
        $this->assertSame('Side', $result->children[0]->children[0]->content);
        $this->assertSame('Main', $result->children[1]->children[0]->content);
    }

    public function testReplaceBlocksUsesAppendMergeMode(): void
    {
        $merger = new BlockMerger(new DirectivePrefixHelper('s'));

        $parent = $this->document()
            ->withChild(
                $this->element('main')
                    ->attribute('s:block', 'content')
                    ->withChild($this->text('Base'))
                    ->build(),
            )
            ->build();

        $child = $this->document()
            ->withChild(
                $this->element('main')
                    ->attribute('s:append', 'content')
                    ->withChild($this->text('Child'))
                    ->build(),
            )
            ->build();

        $context = new CompilationContext('child.sugar.php', '');
        $childBlocks = $merger->collectBlocks($child, $context);
        $result = $merger->replaceBlocks($parent, $childBlocks);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertCount(2, $result->children[0]->children);
        $this->assertInstanceOf(TextNode::class, $result->children[0]->children[0]);
        $this->assertInstanceOf(TextNode::class, $result->children[0]->children[1]);
        $this->assertSame('Base', $result->children[0]->children[0]->content);
        $this->assertSame('Child', $result->children[0]->children[1]->content);
    }

    public function testBlockDirectiveConflictMessageUsesConfiguredPrefix(): void
    {
        $merger = new BlockMerger(new DirectivePrefixHelper('x'));

        $document = $this->document()
            ->withChild(
                $this->element('div')
                    ->attribute('x:block', 'content')
                    ->attribute('x:append', 'content')
                    ->build(),
            )
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Only one of x:block, x:append, or x:prepend is allowed on a single element.');

        $merger->collectBlocks($document, new CompilationContext('test.sugar.php', ''));
    }
}
