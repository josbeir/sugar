<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Helper\AttributeHelper;
use Sugar\Ast\OutputNode;
use Sugar\Enum\OutputContext;

final class AttributeHelperTest extends TestCase
{
    public function testFindAttributesByPrefix(): void
    {
        $attributes = [
            new AttributeNode('s:if', '$show', 1, 1),
            new AttributeNode('s:foreach', '$items as $item', 1, 10),
            new AttributeNode('class', 'btn', 1, 20),
            new AttributeNode('id', 'main-btn', 1, 30),
        ];

        $result = AttributeHelper::findAttributesByPrefix($attributes, 's:');

        $this->assertCount(2, $result);
        $this->assertSame('s:if', $result[0]->name);
        $this->assertSame('s:foreach', $result[1]->name);
    }

    public function testFindAttributesByPrefixReturnsEmpty(): void
    {
        $attributes = [
            new AttributeNode('class', 'btn', 1, 1),
            new AttributeNode('id', 'main', 1, 10),
        ];

        $result = AttributeHelper::findAttributesByPrefix($attributes, 's:');

        $this->assertCount(0, $result);
    }

    public function testHasAttributeWithPrefix(): void
    {
        $node = new ElementNode(
            'div',
            [
                new AttributeNode('s:if', '$show', 1, 1),
                new AttributeNode('class', 'btn', 1, 10),
            ],
            [],
            false,
            1,
            1,
        );

        $this->assertTrue(AttributeHelper::hasAttributeWithPrefix($node, 's:'));
        $this->assertFalse(AttributeHelper::hasAttributeWithPrefix($node, 'x:'));
    }

    public function testHasAttributeWithPrefixOnFragmentNode(): void
    {
        $node = new FragmentNode(
            [
                new AttributeNode('s:if', '$show', 1, 1),
            ],
            [],
            1,
            1,
        );

        $this->assertTrue(AttributeHelper::hasAttributeWithPrefix($node, 's:'));
    }

    public function testGetAttributeValue(): void
    {
        $node = new ElementNode(
            'div',
            [
                new AttributeNode('id', 'main', 1, 1),
                new AttributeNode('class', 'container', 1, 10),
            ],
            [],
            false,
            1,
            1,
        );

        $this->assertSame('main', AttributeHelper::getAttributeValue($node, 'id'));
        $this->assertSame('container', AttributeHelper::getAttributeValue($node, 'class'));
    }

    public function testGetAttributeValueReturnsDefault(): void
    {
        $node = new ElementNode('div', [], [], false, 1, 1);

        $this->assertNull(AttributeHelper::getAttributeValue($node, 'missing'));
        $this->assertSame('default', AttributeHelper::getAttributeValue($node, 'missing', 'default'));
    }

    public function testGetStringAttributeValueReturnsDefaultForNonString(): void
    {
        $node = new ElementNode(
            'div',
            [
                new AttributeNode(
                    'data',
                    new OutputNode('($value)', true, OutputContext::HTML, 1, 1),
                    1,
                    1,
                ),
            ],
            [],
            false,
            1,
            1,
        );

        $this->assertSame('fallback', AttributeHelper::getStringAttributeValue($node, 'data', 'fallback'));
    }

    public function testRemoveAttribute(): void
    {
        $attributes = [
            new AttributeNode('id', 'main', 1, 1),
            new AttributeNode('class', 'btn', 1, 10),
            new AttributeNode('disabled', null, 1, 20),
        ];

        $result = AttributeHelper::removeAttribute($attributes, 'class');

        $this->assertCount(2, $result);
        $this->assertSame('id', $result[0]->name);
        $this->assertSame('disabled', $result[1]->name);
    }

    public function testFilterAttributes(): void
    {
        $attributes = [
            new AttributeNode('s:if', '$show', 1, 1),
            new AttributeNode('class', 'btn', 1, 10),
            new AttributeNode('s:foreach', '$items as $item', 1, 20),
        ];

        $result = AttributeHelper::filterAttributes(
            $attributes,
            fn(AttributeNode $attr): bool => str_starts_with($attr->name, 's:'),
        );

        $this->assertCount(2, $result);
        $this->assertSame('s:if', $result[0]->name);
        $this->assertSame('s:foreach', $result[1]->name);
    }

    public function testFindAttributeIndex(): void
    {
        $attributes = [
            new AttributeNode('id', 'main', 1, 1),
            new AttributeNode('class', 'btn', 1, 10),
            new AttributeNode('disabled', null, 1, 20),
        ];

        $this->assertSame(0, AttributeHelper::findAttributeIndex($attributes, 'id'));
        $this->assertSame(1, AttributeHelper::findAttributeIndex($attributes, 'class'));
        $this->assertSame(2, AttributeHelper::findAttributeIndex($attributes, 'disabled'));
        $this->assertNull(AttributeHelper::findAttributeIndex($attributes, 'missing'));
    }
}
