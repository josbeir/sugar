<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Core\Runtime\HtmlTagHelper;

/**
 * Unit tests for HtmlTagHelper
 */
final class HtmlTagHelperTest extends TestCase
{
    public function testValidatesValidTags(): void
    {
        $this->assertSame('div', HtmlTagHelper::validateTagName('div'));
        $this->assertSame('h1', HtmlTagHelper::validateTagName('h1'));
        $this->assertSame('section', HtmlTagHelper::validateTagName('section'));
        $this->assertSame('article', HtmlTagHelper::validateTagName('article'));
        $this->assertSame('span', HtmlTagHelper::validateTagName('span'));
    }

    public function testRejectsInvalidTags(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid tag name');

        HtmlTagHelper::validateTagName('123invalid');
    }

    public function testRejectsForbiddenTags(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forbidden tag name');

        HtmlTagHelper::validateTagName('script');
    }

    public function testRejectsEmptyTag(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tag name cannot be empty');

        HtmlTagHelper::validateTagName('');
    }

    public function testRejectsSpecialCharacters(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid tag name');

        HtmlTagHelper::validateTagName('div<script>');
    }

    public function testRejectsTagsStartingWithNumbers(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid tag name');

        HtmlTagHelper::validateTagName('5div');
    }

    public function testDetectsSelfClosingTags(): void
    {
        // Self-closing tags
        $this->assertTrue(HtmlTagHelper::isSelfClosing('img'));
        $this->assertTrue(HtmlTagHelper::isSelfClosing('br'));
        $this->assertTrue(HtmlTagHelper::isSelfClosing('hr'));
        $this->assertTrue(HtmlTagHelper::isSelfClosing('input'));
        $this->assertTrue(HtmlTagHelper::isSelfClosing('meta'));
        $this->assertTrue(HtmlTagHelper::isSelfClosing('link'));

        // Non-self-closing tags
        $this->assertFalse(HtmlTagHelper::isSelfClosing('div'));
        $this->assertFalse(HtmlTagHelper::isSelfClosing('span'));
        $this->assertFalse(HtmlTagHelper::isSelfClosing('section'));
        $this->assertFalse(HtmlTagHelper::isSelfClosing('article'));
    }

    public function testAllForbiddenTags(): void
    {
        $forbiddenTags = ['script', 'iframe', 'object', 'embed', 'style'];

        foreach ($forbiddenTags as $tag) {
            try {
                HtmlTagHelper::validateTagName($tag);
                $this->fail('Expected exception for forbidden tag: ' . $tag);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Forbidden tag name', $e->getMessage());
            }
        }
    }
}
