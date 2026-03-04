<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Config\SugarConfig;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\EngineTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

final class FragmentIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use EngineTestTrait;
    use ExecuteTemplateTrait;

    protected function setUp(): void
    {
        $this->setUpCompiler();
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

    public function testCustomFragmentElementOverride(): void
    {
        $config = (new SugarConfig())->withFragmentElement('s-fragment');
        $this->setUpCompiler(config: $config);

        $template = '<s-fragment s:foreach="$items as $item"><li><?= $item ?></li></s-fragment>';
        $compiled = $this->compiler->compile($template);

        $output = $this->executeTemplate($compiled, ['items' => [1, 2]]);

        $this->assertStringContainsString('<li>1</li>', $output);
        $this->assertStringContainsString('<li>2</li>', $output);
        $this->assertStringNotContainsString('s-fragment', $output);
    }

    public function testForeachWithIncludeAndWith(): void
    {
        // <s-template s:foreach s:include s:with /> should include the partial once per iteration,
        // passing per-iteration variables via s:with.
        $engine = $this->createStringEngine(
            templates: [
                'list.sugar.php' => '<s-template
                    s:foreach="$posts as $post"
                    s:include="partials/post-teaser.sugar.php"
                    s:with="[\'post\' => $post]"
                />',
                'partials/post-teaser.sugar.php' => '<article><?= $post->title ?></article>',
            ],
        );

        $posts = [
            (object)['title' => 'Post A'],
            (object)['title' => 'Post B'],
            (object)['title' => 'Post C'],
        ];

        $result = $engine->render('list.sugar.php', ['posts' => $posts]);

        $this->assertStringContainsString('<article>Post A</article>', $result);
        $this->assertStringContainsString('<article>Post B</article>', $result);
        $this->assertStringContainsString('<article>Post C</article>', $result);
    }

    public function testIfWithIncludeAndWith(): void
    {
        // <s-template s:if s:include s:with /> should conditionally include the partial.
        $engine = $this->createStringEngine(
            templates: [
                'page.sugar.php' => '<s-template
                    s:if="$show"
                    s:include="partials/header.sugar.php"
                    s:with="[\'title\' => $title]"
                />',
                'partials/header.sugar.php' => '<header><?= $title ?></header>',
            ],
        );

        $result = $engine->render('page.sugar.php', ['show' => true, 'title' => 'Hello']);
        $this->assertStringContainsString('<header>Hello</header>', $result);

        $result = $engine->render('page.sugar.php', ['show' => false, 'title' => 'Hello']);
        $this->assertStringNotContainsString('<header>', $result);
    }

    public function testForeachWithIncludeUsesCurrentScopeWhenNoWith(): void
    {
        // Without s:with the partial receives all currently defined variables (get_defined_vars()).
        $engine = $this->createStringEngine(
            templates: [
                'list.sugar.php' => '<s-template
                    s:foreach="$items as $item"
                    s:include="partials/item.sugar.php"
                />',
                'partials/item.sugar.php' => '<li><?= $item ?></li>',
            ],
        );

        $result = $engine->render('list.sugar.php', ['items' => ['One', 'Two', 'Three']]);

        $this->assertStringContainsString('<li>One</li>', $result);
        $this->assertStringContainsString('<li>Two</li>', $result);
        $this->assertStringContainsString('<li>Three</li>', $result);
    }
}
