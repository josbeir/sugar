<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\OutputNode;
use Sugar\Enum\OutputContext;
use Sugar\Parser\Helper\AttributeContinuation;
use Sugar\Parser\Helper\NodeFactory;

final class AttributeContinuationTest extends TestCase
{
    public function testAppendAttributeValuePartCreatesArray(): void
    {
        $attr = new AttributeNode('x-data', '', 1, 1);

        AttributeContinuation::appendAttributeValuePart($attr, 'test');

        $this->assertSame(['test'], $attr->value);
    }

    public function testAppendAttributeValuePartAppendsToArray(): void
    {
        $attr = new AttributeNode('x-data', ['test'], 1, 1);

        AttributeContinuation::appendAttributeValuePart($attr, 'test');

        $this->assertSame(['test', 'test'], $attr->value);
    }

    public function testAppendAttributeValuePartSkipsEmpty(): void
    {
        $attr = new AttributeNode('x-data', ['test'], 1, 1);

        AttributeContinuation::appendAttributeValuePart($attr, '');

        $this->assertSame(['test'], $attr->value);
    }

    public function testAppendAttributeValuePartCreatesArrayFromString(): void
    {
        $attr = new AttributeNode('x-data', 'test', 1, 1);

        AttributeContinuation::appendAttributeValuePart($attr, 'more');

        $this->assertSame(['test', 'more'], $attr->value);
    }

    public function testAppendAttributeValuePartAppendsOutputNode(): void
    {
        $attr = new AttributeNode('x-data', 'test', 1, 1);
        $outputNode = new OutputNode('$value', true, OutputContext::HTML, 1, 1);

        AttributeContinuation::appendAttributeValuePart($attr, $outputNode);

        $this->assertSame(['test', $outputNode], $attr->value);
    }

    public function testNormalizeAttributeValueCollapsesSingleItem(): void
    {
        $attr = new AttributeNode('x-data', ['test'], 1, 1);

        AttributeContinuation::normalizeAttributeValue($attr);

        $this->assertSame('test', $attr->value);
    }

    public function testNormalizeAttributeValueLeavesMultipleItems(): void
    {
        $attr = new AttributeNode('x-data', ['test', 'more'], 1, 1);

        AttributeContinuation::normalizeAttributeValue($attr);

        $this->assertSame(['test', 'more'], $attr->value);
    }

    public function testDetectOpenAttributeWithEquals(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', '', 1, 1)], [], false, 1, 1);
        $result = AttributeContinuation::detectOpenAttribute('x-data=', [$element]);

        $this->assertNotNull($result);
        $this->assertSame('x-data', $element->attributes[$result['attrIndex']]->name);
    }

    public function testDetectOpenAttributeWithQuote(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', '', 1, 1)], [], false, 1, 1);
        $result = AttributeContinuation::detectOpenAttribute('x-data="', [$element]);

        $this->assertNotNull($result);
        $this->assertSame('x-data', $element->attributes[$result['attrIndex']]->name);
    }

    public function testDetectOpenAttributeWithSingleQuote(): void
    {
        $element = new ElementNode('div', [new AttributeNode('data-test', '', 1, 1)], [], false, 1, 1);
        $result = AttributeContinuation::detectOpenAttribute("data-test='", [$element]);

        $this->assertNotNull($result);
        $this->assertSame('data-test', $element->attributes[$result['attrIndex']]->name);
    }

    public function testDetectOpenAttributePrefersLastMatch(): void
    {
        $element = new ElementNode(
            'div',
            [new AttributeNode('x-data', '', 1, 1), new AttributeNode('x-other', '', 1, 1)],
            [],
            false,
            1,
            1,
        );
        $result = AttributeContinuation::detectOpenAttribute('x-data="" x-other="', [$element]);

        $this->assertNotNull($result);
        $this->assertSame('x-other', $element->attributes[$result['attrIndex']]->name);
    }

    public function testDetectOpenAttributeReturnsNullWhenClosed(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', '', 1, 1)], [], false, 1, 1);
        $result = AttributeContinuation::detectOpenAttribute('x-data="value"', [$element]);

        $this->assertNull($result);
    }

    public function testDetectOpenAttributeReturnsNullWhenNoElement(): void
    {
        $result = AttributeContinuation::detectOpenAttribute('just text', []);

        $this->assertNull($result);
    }

    public function testDetectOpenAttributeReturnsNullWhenNoAttributes(): void
    {
        $element = new ElementNode('div', [], [], false, 1, 1);
        $result = AttributeContinuation::detectOpenAttribute('x-data="', [$element]);

        $this->assertNull($result);
    }

    public function testConsumeAttributeContinuationUnquotedValue(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', '', 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => null];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value >',
            $pending,
            $this->factory(),
        );

        $this->assertSame('value', $element->attributes[0]->value);
        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
    }

    public function testConsumeAttributeContinuationQuotedValue(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', '', 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value"',
            $pending,
            $this->factory(),
        );

        $this->assertSame('value', $element->attributes[0]->value);
        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
    }

    public function testConsumeAttributeContinuationNoCloseQuote(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', '', 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'no close',
            $pending,
            $this->factory(),
        );

        $this->assertSame(['no close'], $element->attributes[0]->value);
        $this->assertSame('', $html);
        $this->assertSame($pending, $pendingAttr);
    }

    private function factory(): NodeFactory
    {
        return new NodeFactory();
    }
}
