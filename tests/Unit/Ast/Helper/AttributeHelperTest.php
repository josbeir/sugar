<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Ast\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\Helper\AttributeHelper;
use Sugar\Enum\OutputContext;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

final class AttributeHelperTest extends TestCase
{
    use NodeBuildersTrait;

    public function testFindAttributesByPrefix(): void
    {
        $attributes = [
            $this->attribute('s:if', '$show', 1, 1),
            $this->attribute('s:foreach', '$items as $item', 1, 10),
            $this->attribute('class', 'btn', 1, 20),
            $this->attribute('id', 'main-btn', 1, 30),
        ];

        $result = AttributeHelper::findAttributesByPrefix($attributes, 's:');

        $this->assertCount(2, $result);
        $this->assertSame('s:if', $result[0]->name);
        $this->assertSame('s:foreach', $result[1]->name);
    }

    public function testFindAttributesByPrefixReturnsEmpty(): void
    {
        $attributes = [
            $this->attribute('class', 'btn', 1, 1),
            $this->attribute('id', 'main', 1, 10),
        ];

        $result = AttributeHelper::findAttributesByPrefix($attributes, 's:');

        $this->assertCount(0, $result);
    }

    public function testHasAttributeWithPrefix(): void
    {
        $node = $this->element('div')
            ->attribute('s:if', '$show')
            ->attribute('class', 'btn')
            ->at(1, 1)
            ->build();

        $this->assertTrue(AttributeHelper::hasAttributeWithPrefix($node, 's:'));
        $this->assertFalse(AttributeHelper::hasAttributeWithPrefix($node, 'x:'));
    }

    public function testHasAttributeWithPrefixOnFragmentNode(): void
    {
        $node = $this->fragment(
            attributes: [
                $this->attribute('s:if', '$show', 1, 1),
            ],
            children: [],
            line: 1,
            column: 1,
        );

        $this->assertTrue(AttributeHelper::hasAttributeWithPrefix($node, 's:'));
    }

    public function testGetAttributeValue(): void
    {
        $node = $this->element('div')
            ->attribute('id', 'main')
            ->attribute('class', 'container')
            ->at(1, 1)
            ->build();

        $this->assertSame('main', AttributeHelper::getAttributeValue($node, 'id'));
        $this->assertSame('container', AttributeHelper::getAttributeValue($node, 'class'));
    }

    public function testGetAttributeValueReturnsDefault(): void
    {
        $node = $this->element('div')->build();

        $this->assertNull(AttributeHelper::getAttributeValue($node, 'missing'));
        $this->assertSame('default', AttributeHelper::getAttributeValue($node, 'missing', 'default'));
    }

    public function testGetStringAttributeValueReturnsDefaultForNonString(): void
    {
        $node = $this->element('div')
            ->attributeNode(
                $this->attributeNode(
                    'data',
                    $this->outputNode('($value)', true, OutputContext::HTML, 1, 1),
                    1,
                    1,
                ),
            )
            ->at(1, 1)
            ->build();

        $this->assertSame('fallback', AttributeHelper::getStringAttributeValue($node, 'data', 'fallback'));
    }

    public function testRemoveAttribute(): void
    {
        $attributes = [
            $this->attribute('id', 'main', 1, 1),
            $this->attribute('class', 'btn', 1, 10),
            $this->attributeNode('disabled', null, 1, 20),
        ];

        $result = AttributeHelper::removeAttribute($attributes, 'class');

        $this->assertCount(2, $result);
        $this->assertSame('id', $result[0]->name);
        $this->assertSame('disabled', $result[1]->name);
    }

    public function testFilterAttributes(): void
    {
        $attributes = [
            $this->attribute('s:if', '$show', 1, 1),
            $this->attribute('class', 'btn', 1, 10),
            $this->attribute('s:foreach', '$items as $item', 1, 20),
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
            $this->attribute('id', 'main', 1, 1),
            $this->attribute('class', 'btn', 1, 10),
            $this->attributeNode('disabled', null, 1, 20),
        ];

        $this->assertSame(0, AttributeHelper::findAttributeIndex($attributes, 'id'));
        $this->assertSame(1, AttributeHelper::findAttributeIndex($attributes, 'class'));
        $this->assertSame(2, AttributeHelper::findAttributeIndex($attributes, 'disabled'));
        $this->assertNull(AttributeHelper::findAttributeIndex($attributes, 'missing'));
    }
}
