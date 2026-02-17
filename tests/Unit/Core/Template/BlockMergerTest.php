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

    public function testReplaceBlocksResolvesParentPlaceholder(): void
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

        $parentPlaceholder = $this->fragment(
            attributes: [$this->attribute('s:parent', '')],
            children: [],
        );

        $child = $this->document()
            ->withChild(
                $this->fragment(
                    attributes: [$this->attribute('s:block', 'content')],
                    children: [$this->text('Before'), $parentPlaceholder, $this->text('After')],
                ),
            )
            ->build();

        $context = new CompilationContext('child.sugar.php', '');
        $childBlocks = $merger->collectBlocks($child, $context);
        $result = $merger->replaceBlocks($parent, $childBlocks);

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertCount(3, $result->children[0]->children);
        $this->assertInstanceOf(TextNode::class, $result->children[0]->children[0]);
        $this->assertInstanceOf(TextNode::class, $result->children[0]->children[1]);
        $this->assertInstanceOf(TextNode::class, $result->children[0]->children[2]);
        $this->assertSame('Before', $result->children[0]->children[0]->content);
        $this->assertSame('Base', $result->children[0]->children[1]->content);
        $this->assertSame('After', $result->children[0]->children[2]->content);
    }

    public function testCollectBlocksThrowsWhenParentOutsideBlock(): void
    {
        $merger = new BlockMerger(new DirectivePrefixHelper('s'));

        $document = $this->document()
            ->withChild(
                $this->fragment(
                    attributes: [$this->attribute('s:parent', '')],
                    children: [],
                ),
            )
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:parent is only allowed inside s:block.');

        $merger->collectBlocks($document, new CompilationContext('test.sugar.php', ''));
    }

    public function testCollectBlocksThrowsOnDuplicateBlockDefinitions(): void
    {
        $merger = new BlockMerger(new DirectivePrefixHelper('s'));

        $document = $this->document()
            ->withChildren([
                $this->element('div')
                    ->attribute('s:block', 'content')
                    ->build(),
                $this->element('section')
                    ->attribute('s:append', 'content')
                    ->build(),
            ])
            ->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Block "content" is defined multiple times in the same child template.');

        $merger->collectBlocks($document, new CompilationContext('test.sugar.php', ''));
    }
}
