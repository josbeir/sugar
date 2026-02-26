<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Extension\Component\Runtime\SlotOutletHelper;

/**
 * Tests for the SlotOutletHelper runtime utility.
 */
final class SlotOutletHelperTest extends TestCase
{
    /**
     * Test slot outlet with caller metadata swaps tag and merges attributes.
     */
    public function testRenderSwapsTagAndMergesAttrs(): void
    {
        $result = SlotOutletHelper::render(
            'My Title',
            ['tag' => 'h3', 'attrs' => ['class' => 'extra']],
            'h2',
            ['class' => 'card-title'],
        );

        $this->assertSame('<h3 class="card-title extra">My Title</h3>', $result);
    }

    /**
     * Test slot outlet without metadata preserves outlet tag and attrs.
     */
    public function testRenderWithoutMetaPreservesOutlet(): void
    {
        $result = SlotOutletHelper::render(
            'Title',
            null,
            'h2',
            ['class' => 'card-title'],
        );

        $this->assertSame('<h2 class="card-title">Title</h2>', $result);
    }

    /**
     * Test slot outlet merges non-class attributes from caller.
     */
    public function testRenderMergesNonClassAttrs(): void
    {
        $result = SlotOutletHelper::render(
            'Content',
            ['tag' => 'h3', 'attrs' => ['id' => 'custom', 'data-role' => 'title']],
            'h2',
            ['class' => 'heading'],
        );

        $this->assertSame('<h3 class="heading" id="custom" data-role="title">Content</h3>', $result);
    }

    /**
     * Test slot outlet with empty attrs on both sides.
     */
    public function testRenderWithNoAttrs(): void
    {
        $result = SlotOutletHelper::render(
            'Text',
            ['tag' => 'span', 'attrs' => []],
            'div',
            [],
        );

        $this->assertSame('<span>Text</span>', $result);
    }

    /**
     * Test slot outlet caller attribute overrides outlet same-name attribute.
     */
    public function testRenderCallerOverridesOutletAttr(): void
    {
        $result = SlotOutletHelper::render(
            'Content',
            ['tag' => 'div', 'attrs' => ['id' => 'caller-id']],
            'div',
            ['id' => 'outlet-id', 'class' => 'panel'],
        );

        $this->assertSame('<div id="caller-id" class="panel">Content</div>', $result);
    }

    /**
     * Test slot outlet with boolean attribute (null value).
     */
    public function testRenderWithBooleanAttr(): void
    {
        $result = SlotOutletHelper::render(
            'Content',
            null,
            'button',
            ['disabled' => null, 'class' => 'btn'],
        );

        $this->assertSame('<button disabled class="btn">Content</button>', $result);
    }

    /**
     * Test slot outlet escapes attribute values properly.
     */
    public function testRenderEscapesAttributes(): void
    {
        $result = SlotOutletHelper::render(
            'Content',
            ['tag' => 'div', 'attrs' => ['data-value' => 'a"b']],
            'div',
            [],
        );

        $this->assertStringContainsString('data-value="a&quot;b"', $result);
    }
}
