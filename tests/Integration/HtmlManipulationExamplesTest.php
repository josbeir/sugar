<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

/**
 * Integration tests for s:tag and s:ifcontent directives
 *
 * Tests real-world usage patterns and expected output behavior.
 * For validation logic unit tests, see Unit/Runtime/HtmlTagHelperTest.
 */
final class HtmlManipulationExamplesTest extends TestCase
{
    use CompilerTestTrait;
    use ExecuteTemplateTrait;

    protected function setUp(): void
    {
        $this->setUpCompiler();
    }

    public function testTagDirectiveWithDynamicHeadings(): void
    {
        $template = '<h1 s:tag="\'h\' . $level">Section Title</h1>';

        // Level 1 heading
        $result = $this->executeTemplate($this->compiler->compile($template), ['level' => 1]);
        $this->assertStringContainsString('<h1>Section Title</h1>', $result);

        // Level 3 heading
        $result = $this->executeTemplate($this->compiler->compile($template), ['level' => 3]);
        $this->assertStringContainsString('<h3>Section Title</h3>', $result);

        // Level 5 heading
        $result = $this->executeTemplate($this->compiler->compile($template), ['level' => 5]);
        $this->assertStringContainsString('<h5>Section Title</h5>', $result);
    }

    public function testTagDirectiveWithSemanticElements(): void
    {
        $template = '<div s:tag="$semantic ? \'section\' : \'div\'" class="wrapper">Content</div>';
        $compiled = $this->compiler->compile($template);

        // With semantic = true
        $result = $this->executeTemplate($compiled, ['semantic' => true]);
        $this->assertStringContainsString('<section class="wrapper">Content</section>', $result);

        // With semantic = false
        $result = $this->executeTemplate($compiled, ['semantic' => false]);
        $this->assertStringContainsString('<div class="wrapper">Content</div>', $result);
    }

    public function testIfContentDirectiveHidesEmptyWrapper(): void
    {
        $template = '<div class="card" s:ifcontent><?= $content ?></div>';
        $compiled = $this->compiler->compile($template);

        // With content
        $result = $this->executeTemplate($compiled, ['content' => 'Hello World']);
        $this->assertStringContainsString('<div class="card">Hello World</div>', $result);

        // Without content (empty string)
        $result = $this->executeTemplate($compiled, ['content' => '']);
        $this->assertStringNotContainsString('<div', $result);
        $this->assertStringNotContainsString('class="card"', $result);

        // With whitespace only
        $result = $this->executeTemplate($compiled, ['content' => '   ']);
        $this->assertStringNotContainsString('<div', $result);
    }

    public function testIfContentDirectiveWithZeroValue(): void
    {
        $template = '<div class="result" s:ifcontent><?= $value ?></div>';
        $compiled = $this->compiler->compile($template);

        // Zero is content (not empty)
        $result = $this->executeTemplate($compiled, ['value' => 0]);
        $this->assertStringContainsString('<div class="result">0</div>', $result);

        // String "0" is also content
        $result = $this->executeTemplate($compiled, ['value' => '0']);
        $this->assertStringContainsString('<div class="result">0</div>', $result);
    }

    public function testIfContentDirectiveWithListWrapper(): void
    {
        $template = <<<'SUGAR'
<ul class="items" s:ifcontent>
    <?php foreach ($items as $item): ?>
        <li><?= $item ?></li>
    <?php endforeach; ?>
</ul>
SUGAR;
        $compiled = $this->compiler->compile($template);

        // With items
        $result = $this->executeTemplate($compiled, ['items' => ['Apple', 'Banana', 'Cherry']]);
        $this->assertStringContainsString('<ul class="items">', $result);
        $this->assertStringContainsString('<li>Apple</li>', $result);
        $this->assertStringContainsString('<li>Banana</li>', $result);
        $this->assertStringContainsString('<li>Cherry</li>', $result);

        // Without items (empty array)
        $result = $this->executeTemplate($compiled, ['items' => []]);
        $this->assertStringNotContainsString('<ul', $result);
        $this->assertStringNotContainsString('class="items"', $result);
    }

    public function testCombiningTagAndIfContent(): void
    {
        $template = '<div s:tag="$tag" class="wrapper" s:ifcontent><?= $content ?></div>';
        $compiled = $this->compiler->compile($template);

        // With content and custom tag
        $result = $this->executeTemplate($compiled, [
            'tag' => 'section',
            'content' => 'Hello',
        ]);
        $this->assertStringContainsString('<section class="wrapper">Hello</section>', $result);

        // Without content (should hide entire element)
        $result = $this->executeTemplate($compiled, [
            'tag' => 'section',
            'content' => '',
        ]);
        $this->assertStringNotContainsString('<section', $result);
        $this->assertStringNotContainsString('class="wrapper"', $result);
    }

    public function testRealWorldCardComponent(): void
    {
        $template = <<<'SUGAR'
<div s:tag="$cardTag ?? 'div'" class="card" s:ifcontent>
    <h2 s:tag="'h' . ($titleLevel ?? 2)" class="card-title" s:ifcontent>
        <?= $title ?? '' ?>
    </h2>
    <div class="card-body" s:ifcontent>
        <?= $body ?? '' ?>
    </div>
</div>
SUGAR;
        $compiled = $this->compiler->compile($template);

        // Full card
        $result = $this->executeTemplate($compiled, [
            'cardTag' => 'article',
            'titleLevel' => 3,
            'title' => 'My Title',
            'body' => 'Card content here',
        ]);

        $this->assertStringContainsString('<article class="card">', $result);
        $this->assertStringContainsString('<h3 class="card-title">', $result);
        $this->assertStringContainsString('My Title', $result);
        $this->assertStringContainsString('<div class="card-body">', $result);
        $this->assertStringContainsString('Card content here', $result);

        // Card with no body (only title)
        $result = $this->executeTemplate($compiled, [
            'title' => 'Just Title',
            'body' => '',
        ]);

        $this->assertStringContainsString('<h2 class="card-title">', $result);
        $this->assertStringContainsString('Just Title', $result);
        $this->assertStringNotContainsString('card-body', $result);

        // Completely empty card (should not render at all)
        $result = $this->executeTemplate($compiled, [
            'title' => '',
            'body' => '',
        ]);

        $this->assertStringNotContainsString('card', $result);
    }
}
