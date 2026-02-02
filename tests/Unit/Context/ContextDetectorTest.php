<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Context\ContextDetector;
use Sugar\Core\Enum\OutputContext;

/**
 * Test context detection (security-critical - 100% coverage required)
 */
final class ContextDetectorTest extends TestCase
{
    private ContextDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new ContextDetector();
    }

    // HTML Context Tests

    public function testDetectsHtmlContent(): void
    {
        $source = '<div>Hello <?= $name ?></div>';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::HTML, $context);
    }

    public function testDetectsHtmlBetweenTags(): void
    {
        $source = '<p>Some text <?= $content ?> more text</p>';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::HTML, $context);
    }

    // HTML Attribute Tests

    public function testDetectsHtmlAttribute(): void
    {
        $source = '<div title="<?= $value ?>">content</div>';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $context);
    }

    public function testDetectsAttributeWithSingleQuotes(): void
    {
        $source = '<div title=\'<?= $value ?>\'>content</div>';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);
        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $context);
    }

    public function testDetectsClassAttribute(): void
    {
        $source = '<div class="btn <?= $class ?>">button</div>';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::HTML_ATTRIBUTE, $context);
    }

    // JavaScript Context Tests

    public function testDetectsJavaScriptInScriptTag(): void
    {
        $source = '<script>const x = <?= $value ?>;</script>';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::JAVASCRIPT, $context);
    }

    public function testDetectsJavaScriptInStringContext(): void
    {
        $source = '<script>const msg = "Hello <?= $name ?>";</script>';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::JAVASCRIPT, $context);
    }

    public function testDetectsJavaScriptMultiline(): void
    {
        $source = <<<'HTML'
        <script>
        function test() {
            var x = <?= $value ?>;
        }
        </script>
        HTML;
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::JAVASCRIPT, $context);
    }

    // CSS Context Tests

    public function testDetectsCssInStyleTag(): void
    {
        $source = '<style>.class { color: <?= $color ?>; }</style>';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::CSS, $context);
    }

    public function testDetectsCssMultiline(): void
    {
        $source = <<<'HTML'
        <style>
        .button {
            background: <?= $bg ?>;
        }
        </style>
        HTML;
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::CSS, $context);
    }

    // URL Context Tests

    public function testDetectsUrlInHref(): void
    {
        $source = '<a href="<?= $url ?>">link</a>';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::URL, $context);
    }

    public function testDetectsUrlInSrc(): void
    {
        $source = '<img src="<?= $imageUrl ?>" alt="image">';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::URL, $context);
    }

    public function testDetectsUrlInAction(): void
    {
        $source = '<form action="<?= $action ?>">...</form>';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::URL, $context);
    }

    // Edge Cases

    public function testDetectsNestedContext(): void
    {
        $source = '<div onclick="alert(\'<?= $msg ?>\')">click</div>';
        $position = strpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        // JavaScript inside attribute
        $this->assertSame(OutputContext::JAVASCRIPT, $context);
    }

    public function testDetectsAtStartOfDocument(): void
    {
        $source = '<?= $content ?><div>test</div>';
        $position = 0;

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::HTML, $context);
    }

    public function testDetectsMultipleScriptTags(): void
    {
        $source = '<script>var a = 1;</script><div><?= $x ?></div><script>var b = <?= $y ?>;</script>';
        $position = strrpos($source, '<?=');
        $this->assertNotFalse($position);

        $context = $this->detector->detect($position, $source);

        $this->assertSame(OutputContext::JAVASCRIPT, $context);
    }
}
