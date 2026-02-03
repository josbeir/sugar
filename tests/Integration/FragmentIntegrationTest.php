<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Compiler;
use Sugar\Escape\Escaper;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\Tests\ExecuteTemplateTrait;

final class FragmentIntegrationTest extends TestCase
{
    use ExecuteTemplateTrait;

    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler(
            parser: new Parser(),
            contextPass: new ContextAnalysisPass(),
            escaper: new Escaper(),
        );
    }

    public function testCompilesFragmentWithForeach(): void
    {
        $template = '<s-template s:foreach="$posts as $post">
            <article><?= $post->title ?></article>
            <p><?= $post->excerpt ?></p>
        </s-template>';

        $compiled = $this->compiler->compile($template);

        // Assert no <s-template> in output
        $this->assertStringNotContainsString('<s-template', $compiled);
        $this->assertStringNotContainsString('</s-template>', $compiled);

        // Assert foreach logic present
        $this->assertStringContainsString('foreach ($posts as $post)', $compiled);

        // Execute and verify
        $posts = [
            (object)['title' => 'Post A', 'excerpt' => 'Excerpt A'],
            (object)['title' => 'Post B', 'excerpt' => 'Excerpt B'],
        ];

        $output = $this->executeTemplate($compiled, ['posts' => $posts]);

        $this->assertStringContainsString('<article>Post A</article>', $output);
        $this->assertStringContainsString('<p>Excerpt A</p>', $output);
        $this->assertStringContainsString('<article>Post B</article>', $output);
        $this->assertStringContainsString('<p>Excerpt B</p>', $output);
    }

    public function testCompilesFragmentWithIf(): void
    {
        $template = '<s-template s:if="$showHeader">
            <header>Header</header>
            <nav>Navigation</nav>
        </s-template>';

        $compiled = $this->compiler->compile($template);

        // Test with condition true
        $output = $this->executeTemplate($compiled, ['showHeader' => true]);

        $this->assertStringContainsString('<header>Header</header>', $output);
        $this->assertStringContainsString('<nav>Navigation</nav>', $output);

        // Test with condition false
        $output = $this->executeTemplate($compiled, ['showHeader' => false]);

        $this->assertStringNotContainsString('<header>', $output);
        $this->assertStringNotContainsString('<nav>', $output);
    }

    public function testCompilesNestedFragments(): void
    {
        $template = '<s-template s:if="$showList">
            <s-template s:foreach="$items as $item">
                <div><?= $item ?></div>
            </s-template>
        </s-template>';

        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, [
            'showList' => true,
            'items' => ['A', 'B', 'C'],
        ]);

        $this->assertStringContainsString('<div>A</div>', $output);
        $this->assertStringContainsString('<div>B</div>', $output);
        $this->assertStringContainsString('<div>C</div>', $output);
    }

    public function testFragmentWithForelseAndEmpty(): void
    {
        $template = '<s-template s:forelse="$items as $item">
            <div><?= $item ?></div>
        </s-template>
        <p s:empty>No items found</p>';

        $compiled = $this->compiler->compile($template);

        // Test with items
        $output = $this->executeTemplate($compiled, ['items' => ['A', 'B']]);

        $this->assertStringContainsString('<div>A</div>', $output);
        $this->assertStringNotContainsString('No items found', $output);

        // Test without items
        $output = $this->executeTemplate($compiled, ['items' => []]);

        $this->assertStringNotContainsString('<div>', $output);
        $this->assertStringContainsString('No items found', $output);
    }

    public function testFragmentDoesNotRenderWrapper(): void
    {
        $template = '<div class="container">
            <s-template s:foreach="$items as $item">
                <span><?= $item ?></span>
            </s-template>
        </div>';

        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['items' => ['A', 'B']]);

        // Should have container div with spans directly inside (no intermediate wrapper)
        $this->assertStringContainsString('<div class="container">', $output);
        $this->assertStringContainsString('<span>A</span>', $output);
        $this->assertStringContainsString('<span>B</span>', $output);
        $this->assertStringNotContainsString('<s-template', $output);
    }

    public function testEmptyFragmentRendersNothing(): void
    {
        $template = '<s-template></s-template>';

        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled);

        // Should render literally nothing (except whitespace from compilation)
        $this->assertSame('', trim($output));
    }

    public function testFragmentWithTextDirective(): void
    {
        $template = '<s-template s:foreach="$names as $name">
            <span s:text="$name"></span>
        </s-template>';

        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['names' => ['<John>', 'Jane']]);

        // Text should be escaped
        $this->assertStringContainsString('&lt;John&gt;', $output);
        $this->assertStringContainsString('Jane', $output);
    }
}
