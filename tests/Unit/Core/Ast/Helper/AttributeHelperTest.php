<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Ast\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Enum\OutputContext;
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

        $idValue = AttributeHelper::getAttributeValue($node, 'id');
        $classValue = AttributeHelper::getAttributeValue($node, 'class');

        $this->assertInstanceOf(AttributeValue::class, $idValue);
        $this->assertInstanceOf(AttributeValue::class, $classValue);
        $this->assertSame('main', $idValue->static);
        $this->assertSame('container', $classValue->static);
    }

    public function testGetAttributeValueReturnsDefault(): void
    {
        $node = $this->element('div')->build();

        $this->assertNotInstanceOf(AttributeValue::class, AttributeHelper::getAttributeValue($node, 'missing'));
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

    public function testCollectNamedAttributeNamesSkipsSpreadPlaceholdersAndDeduplicates(): void
    {
        $attributes = [
            $this->attribute('id', 'main', 1, 1),
            $this->attribute('class', 'btn', 1, 10),
            $this->attribute('id', 'duplicate', 1, 20),
            $this->attributeNode('', $this->outputNode('$attrs', false, OutputContext::HTML_ATTRIBUTE, 1, 30), 1, 30),
        ];

        $result = AttributeHelper::collectNamedAttributeNames($attributes);

        $this->assertSame(['id', 'class'], $result);
    }

    public function testAttributeValueToPhpExpressionOutputWithWrapping(): void
    {
        $value = AttributeValue::output(new OutputNode('$name', false, OutputContext::HTML_ATTRIBUTE, 1, 1));

        $this->assertSame('$name', AttributeHelper::attributeValueToPhpExpression($value));
        $this->assertSame('($name)', AttributeHelper::attributeValueToPhpExpression($value, wrapOutputExpressions: true));
    }

    public function testAttributeValueToPhpExpressionStaticAndBooleanLiterals(): void
    {
        $this->assertSame("'card'", AttributeHelper::attributeValueToPhpExpression(AttributeValue::static('card')));
        $this->assertSame('null', AttributeHelper::attributeValueToPhpExpression(AttributeValue::boolean(), booleanLiteral: 'null'));
    }

    public function testAttributeValueToPhpExpressionParts(): void
    {
        $value = AttributeValue::parts([
            'pre-',
            new OutputNode('$value', false, OutputContext::HTML_ATTRIBUTE, 1, 1),
            '-post',
        ]);

        $this->assertSame("'pre-' . \$value . '-post'", AttributeHelper::attributeValueToPhpExpression($value));
        $this->assertSame("'pre-' . (\$value) . '-post'", AttributeHelper::attributeValueToPhpExpression($value, wrapOutputExpressions: true));
    }

    public function testNormalizeCompiledPhpExpression(): void
    {
        $this->assertSame('$value', AttributeHelper::normalizeCompiledPhpExpression('<?= $value ?>'));
        $this->assertSame('$value', AttributeHelper::normalizeCompiledPhpExpression('<?php echo $value ?>'));
        $this->assertSame('HtmlAttributeHelper::spreadAttrs($attrs)', AttributeHelper::normalizeCompiledPhpExpression('<?= HtmlAttributeHelper::spreadAttrs($attrs) ?>'));
    }
}
