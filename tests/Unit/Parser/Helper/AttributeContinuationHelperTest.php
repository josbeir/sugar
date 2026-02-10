<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\OutputNode;
use Sugar\Enum\OutputContext;
use Sugar\Parser\Helper\AttributeContinuationHelper;

final class AttributeContinuationHelperTest extends TestCase
{
    public function testAppendAttributeValuePartToNullValue(): void
    {
        $attr = new AttributeNode('data-value', null, 1, 1);

        AttributeContinuationHelper::appendAttributeValuePart($attr, 'test');

        $this->assertIsArray($attr->value);
        $this->assertSame(['test'], $attr->value);
    }

    public function testAppendAttributeValuePartToEmptyStringValue(): void
    {
        $attr = new AttributeNode('data-value', '', 1, 1);

        AttributeContinuationHelper::appendAttributeValuePart($attr, 'test');

        $this->assertIsArray($attr->value);
        $this->assertSame(['test'], $attr->value);
    }

    public function testAppendAttributeValuePartIgnoresEmptyString(): void
    {
        $attr = new AttributeNode('data-value', 'existing', 1, 1);

        AttributeContinuationHelper::appendAttributeValuePart($attr, '');

        $this->assertSame('existing', $attr->value);
    }

    public function testAppendAttributeValuePartToExistingStringCreatesArray(): void
    {
        $attr = new AttributeNode('data-value', 'existing', 1, 1);

        AttributeContinuationHelper::appendAttributeValuePart($attr, 'more');

        $this->assertIsArray($attr->value);
        $this->assertSame(['existing', 'more'], $attr->value);
    }

    public function testAppendAttributeValuePartToExistingArray(): void
    {
        $attr = new AttributeNode('data-value', ['existing'], 1, 1);

        AttributeContinuationHelper::appendAttributeValuePart($attr, 'more');

        $this->assertIsArray($attr->value);
        $this->assertSame(['existing', 'more'], $attr->value);
    }

    public function testAppendAttributeValuePartWithOutputNode(): void
    {
        $attr = new AttributeNode('data-value', 'existing', 1, 1);
        $outputNode = new OutputNode('$var', true, OutputContext::HTML, 1, 1);

        AttributeContinuationHelper::appendAttributeValuePart($attr, $outputNode);

        $this->assertIsArray($attr->value);
        $this->assertCount(2, $attr->value);
        $this->assertSame('existing', $attr->value[0]);
        $this->assertSame($outputNode, $attr->value[1]);
    }

    public function testNormalizeAttributeValueWithSingleItemArray(): void
    {
        $attr = new AttributeNode('data-value', ['single'], 1, 1);

        AttributeContinuationHelper::normalizeAttributeValue($attr);

        $this->assertSame('single', $attr->value);
    }

    public function testNormalizeAttributeValueWithMultipleItems(): void
    {
        $attr = new AttributeNode('data-value', ['first', 'second'], 1, 1);

        AttributeContinuationHelper::normalizeAttributeValue($attr);

        $this->assertIsArray($attr->value);
        $this->assertSame(['first', 'second'], $attr->value);
    }

    public function testNormalizeAttributeValueWithString(): void
    {
        $attr = new AttributeNode('data-value', 'existing', 1, 1);

        AttributeContinuationHelper::normalizeAttributeValue($attr);

        $this->assertSame('existing', $attr->value);
    }

    public function testDetectOpenAttributeWithTrailingEquals(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('x-data', '', 1, 5);

        $result = AttributeContinuationHelper::detectOpenAttribute('x-data=', [$element]);

        $this->assertIsArray($result);
        $this->assertSame($element, $result['element']);
        $this->assertSame(0, $result['attrIndex']);
        $this->assertNull($result['quote']);
    }

    public function testDetectOpenAttributeWithOpenQuote(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('x-data', '', 1, 5);

        $result = AttributeContinuationHelper::detectOpenAttribute('x-data="', [$element]);

        $this->assertIsArray($result);
        $this->assertSame($element, $result['element']);
        $this->assertSame(0, $result['attrIndex']);
        $this->assertSame('"', $result['quote']);
    }

    public function testDetectOpenAttributeWithOpenSingleQuote(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('data-test', '', 1, 5);

        $result = AttributeContinuationHelper::detectOpenAttribute("data-test='", [$element]);

        $this->assertIsArray($result);
        $this->assertSame($element, $result['element']);
        $this->assertSame(0, $result['attrIndex']);
        $this->assertSame("'", $result['quote']);
    }

    public function testDetectOpenAttributeReturnsNullIfAttributeNotFound(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);

        $result = AttributeContinuationHelper::detectOpenAttribute('x-data="', [$element]);

        $this->assertNull($result);
    }

    public function testDetectOpenAttributeReturnsNullIfStringIsComplete(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('x-data', '', 1, 5);

        $result = AttributeContinuationHelper::detectOpenAttribute('x-data="value"', [$element]);

        $this->assertNull($result);
    }

    public function testDetectOpenAttributeReturnsNullIfNoAttributeInHtml(): void
    {
        $result = AttributeContinuationHelper::detectOpenAttribute('just text', []);

        $this->assertNull($result);
    }

    public function testDetectOpenAttributeIgnoresElementsWithoutNodes(): void
    {
        $result = AttributeContinuationHelper::detectOpenAttribute('x-data="', []);

        $this->assertNull($result);
    }

    public function testConsumeAttributeContinuationWithUnquotedValue(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('attr', '', 1, 5);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => null];

        [$html, $pendingAttr] = AttributeContinuationHelper::consumeAttributeContinuation('value >', $pending);

        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
        $this->assertSame('value', $element->attributes[0]->value);
    }

    public function testConsumeAttributeContinuationWithQuotedValue(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('attr', '', 1, 5);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuationHelper::consumeAttributeContinuation('value"', $pending);

        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
        $this->assertSame('value', $element->attributes[0]->value);
    }

    public function testConsumeAttributeContinuationWithUnclosedQuote(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('attr', '', 1, 5);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuationHelper::consumeAttributeContinuation('no close', $pending);

        $this->assertSame('', $html);
        $this->assertIsArray($pendingAttr);
        $this->assertSame($pending['element'], $pendingAttr['element']);
    }

    public function testConsumeAttributeContinuationWithInvalidAttributeIndex(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 99, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuationHelper::consumeAttributeContinuation('html', $pending);

        $this->assertSame('html', $html);
        $this->assertNull($pendingAttr);
    }

    public function testConsumeAttributeContinuationContinuesToNextAttribute(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('first', '', 1, 5);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuationHelper::consumeAttributeContinuation('val" other="test"', $pending);

        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
        $this->assertCount(2, $element->attributes);
        $this->assertSame('other', $element->attributes[1]->name);
    }

    public function testConsumeAttributeContinuationSelfClosingTag(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('attr', '', 1, 5);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuationHelper::consumeAttributeContinuation('value"/>', $pending);

        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
        $this->assertTrue($element->selfClosing);
    }

    public function testDetectOpenAttributeWithWhitespaceAroundEquals(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('data-test', '', 1, 5);

        $result = AttributeContinuationHelper::detectOpenAttribute('  data-test  =  "', [$element]);

        $this->assertIsArray($result);
        $this->assertSame('"', $result['quote']);
    }

    public function testConsumeAttributeContinuationWithMultipleAttributes(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('x-data', '', 1, 5);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuationHelper::consumeAttributeContinuation('value" x-effect="load()" another-attr>', $pending);

        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
        $this->assertCount(3, $element->attributes);
        $this->assertSame('x-effect', $element->attributes[1]->name);
        $this->assertSame('another-attr', $element->attributes[2]->name);
    }

    public function testConsumeAttributeContinuationUnquotedAttributeValue(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $element->attributes[] = new AttributeNode('class', '', 1, 5);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => null];

        [$html, $pendingAttr] = AttributeContinuationHelper::consumeAttributeContinuation('my-class id="test">', $pending);

        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
        $this->assertCount(2, $element->attributes);
        $this->assertSame('my-class', $element->attributes[0]->value);
    }

    public function testNormalizeAttributeValueWithEmptyArray(): void
    {
        $attr = new AttributeNode('data-value', [], 1, 1);

        AttributeContinuationHelper::normalizeAttributeValue($attr);

        $this->assertIsArray($attr->value);
    }

    public function testAppendAttributeValuePartToArrayWithMultipleItems(): void
    {
        $attr = new AttributeNode('data-value', ['first', 'second'], 1, 1);

        AttributeContinuationHelper::appendAttributeValuePart($attr, 'third');

        $this->assertIsArray($attr->value);
        $this->assertCount(3, $attr->value);
        $this->assertSame('third', $attr->value[2]);
    }
}
