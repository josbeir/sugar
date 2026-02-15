<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Parser\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Enum\OutputContext;
use Sugar\Core\Parser\Helper\AttributeContinuation;
use Sugar\Core\Parser\Helper\NodeFactory;

final class AttributeContinuationTest extends TestCase
{
    public function testAppendAttributeValuePartCreatesArray(): void
    {
        $attr = new AttributeNode('x-data', AttributeValue::static(''), 1, 1);

        AttributeContinuation::appendAttributeValuePart($attr, 'test');

        $this->assertSame(['test'], $attr->value->toParts());
    }

    public function testAppendAttributeValuePartAppendsToArray(): void
    {
        $attr = new AttributeNode('x-data', AttributeValue::parts(['test']), 1, 1);

        AttributeContinuation::appendAttributeValuePart($attr, 'test');

        $this->assertSame(['test', 'test'], $attr->value->toParts());
    }

    public function testAppendAttributeValuePartSkipsEmpty(): void
    {
        $attr = new AttributeNode('x-data', AttributeValue::parts(['test']), 1, 1);

        AttributeContinuation::appendAttributeValuePart($attr, '');

        $this->assertSame(['test'], $attr->value->toParts());
    }

    public function testAppendAttributeValuePartCreatesArrayFromString(): void
    {
        $attr = new AttributeNode('x-data', AttributeValue::static('test'), 1, 1);

        AttributeContinuation::appendAttributeValuePart($attr, 'more');

        $this->assertSame(['test', 'more'], $attr->value->toParts());
    }

    public function testAppendAttributeValuePartAppendsOutputNode(): void
    {
        $attr = new AttributeNode('x-data', AttributeValue::static('test'), 1, 1);
        $outputNode = new OutputNode('$value', true, OutputContext::HTML, 1, 1);

        AttributeContinuation::appendAttributeValuePart($attr, $outputNode);

        $this->assertSame(['test', $outputNode], $attr->value->toParts());
    }

    public function testNormalizeAttributeValueCollapsesSingleItem(): void
    {
        $attr = new AttributeNode('x-data', AttributeValue::parts(['test']), 1, 1);

        AttributeContinuation::normalizeAttributeValue($attr);

        $this->assertTrue($attr->value->isStatic());
        $this->assertSame('test', $attr->value->static);
    }

    public function testNormalizeAttributeValueLeavesMultipleItems(): void
    {
        $attr = new AttributeNode('x-data', AttributeValue::parts(['test', 'more']), 1, 1);

        AttributeContinuation::normalizeAttributeValue($attr);

        $this->assertSame(['test', 'more'], $attr->value->toParts());
    }

    public function testDetectOpenAttributeWithEquals(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $result = AttributeContinuation::detectOpenAttribute('x-data=', [$element]);

        $this->assertNotNull($result);
        $this->assertSame('x-data', $element->attributes[$result['attrIndex']]->name);
    }

    public function testDetectOpenAttributeWithQuote(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $result = AttributeContinuation::detectOpenAttribute('x-data="', [$element]);

        $this->assertNotNull($result);
        $this->assertSame('x-data', $element->attributes[$result['attrIndex']]->name);
    }

    public function testDetectOpenAttributeWithSingleQuote(): void
    {
        $element = new ElementNode('div', [new AttributeNode('data-test', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $result = AttributeContinuation::detectOpenAttribute("data-test='", [$element]);

        $this->assertNotNull($result);
        $this->assertSame('data-test', $element->attributes[$result['attrIndex']]->name);
    }

    public function testDetectOpenAttributePrefersLastMatch(): void
    {
        $element = new ElementNode(
            'div',
            [
                new AttributeNode('x-data', AttributeValue::static(''), 1, 1),
                new AttributeNode('x-other', AttributeValue::static(''), 1, 1),
            ],
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
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
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

    public function testDetectOpenAttributeReturnsNullWhenAttributeMissing(): void
    {
        $element = new ElementNode('div', [new AttributeNode('data-one', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $result = AttributeContinuation::detectOpenAttribute('data-two=', [$element]);

        $this->assertNull($result);
    }

    public function testConsumeAttributeContinuationUnquotedValue(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => null];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value >',
            $pending,
            $this->factory(),
        );

        $this->assertTrue($element->attributes[0]->value->isStatic());
        $this->assertSame('value', $element->attributes[0]->value->static);
        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
    }

    public function testConsumeAttributeContinuationQuotedValue(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value"',
            $pending,
            $this->factory(),
        );

        $this->assertTrue($element->attributes[0]->value->isStatic());
        $this->assertSame('value', $element->attributes[0]->value->static);
        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
    }

    public function testConsumeAttributeContinuationNoCloseQuote(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'no close',
            $pending,
            $this->factory(),
        );

        $this->assertSame(['no close'], $element->attributes[0]->value->toParts());
        $this->assertSame('', $html);
        $this->assertSame($pending, $pendingAttr);
    }

    public function testConsumeAttributeContinuationParsesAdditionalAttributesAndSelfClosing(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => null];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value class="btn" disabled/>',
            $pending,
            $this->factory(),
        );

        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
        $this->assertTrue($element->selfClosing);
        $this->assertSame('value', $element->attributes[0]->value->static);
        $this->assertSame('class', $element->attributes[1]->name);
        $this->assertSame('btn', $element->attributes[1]->value->static);
        $this->assertSame('disabled', $element->attributes[2]->name);
        $this->assertTrue($element->attributes[2]->value->isBoolean());
    }

    public function testConsumeAttributeContinuationQuotedValueParsesTrailingAttributes(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value" data-id="5">',
            $pending,
            $this->factory(),
        );

        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
        $this->assertSame('value', $element->attributes[0]->value->static);
        $this->assertSame('data-id', $element->attributes[1]->name);
        $this->assertSame('5', $element->attributes[1]->value->static);
    }

    public function testConsumeAttributeContinuationParsesEscapedQuotes(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => null];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value data-title="say \\"hi\\"">',
            $pending,
            $this->factory(),
        );

        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
        $this->assertSame('data-title', $element->attributes[1]->name);
        $this->assertSame('say "hi"', $element->attributes[1]->value->static);
    }

    /**
     * Ensures continuation skips an extra opening quote before parsing attributes.
     */
    public function testConsumeAttributeContinuationSkipsLeadingQuoteInContinuation(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            '"" data-id="5">',
            $pending,
            $this->factory(),
        );

        $this->assertSame('', $html);
        $this->assertNull($pendingAttr);
        $this->assertTrue($element->attributes[0]->value->isStatic());
        $this->assertSame('', $element->attributes[0]->value->static);
        $this->assertSame('data-id', $element->attributes[1]->name);
        $this->assertSame('5', $element->attributes[1]->value->static);
    }

    /**
     * Ensures invalid attribute starts do not consume content.
     */
    public function testConsumeAttributeContinuationStopsOnInvalidAttributeStart(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => null];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value =oops',
            $pending,
            $this->factory(),
        );

        $this->assertSame('=oops', $html);
        $this->assertNull($pendingAttr);
        $this->assertSame('value', $element->attributes[0]->value->static);
    }

    /**
     * Ensures whitespace around equals and unquoted values set pending attributes.
     */
    public function testConsumeAttributeContinuationUnquotedValueWithWhitespaceSetsPendingAttribute(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => '"'];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value" data-id  =   unclosed',
            $pending,
            $this->factory(),
        );

        $this->assertSame('', $html);
        $this->assertNotNull($pendingAttr);
        $this->assertSame('value', $element->attributes[0]->value->static);
        $this->assertSame('data-id', $element->attributes[1]->name);
        $this->assertSame('unclosed', $element->attributes[1]->value->static);
        $this->assertSame(1, $pendingAttr['attrIndex']);
        $this->assertNull($pendingAttr['quote']);
    }

    public function testConsumeAttributeContinuationReturnsOriginalHtmlWhenAttributeMissing(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 3, 'quote' => null];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value >',
            $pending,
            $this->factory(),
        );

        $this->assertSame('value >', $html);
        $this->assertNull($pendingAttr);
    }

    public function testConsumeAttributeContinuationSetsPendingAttributeWhenValueMissing(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => null];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value data-id=',
            $pending,
            $this->factory(),
        );

        $this->assertSame('', $html);
        $this->assertNotNull($pendingAttr);
        $this->assertSame('data-id', $element->attributes[1]->name);
        $this->assertTrue($element->attributes[1]->value->isStatic());
        $this->assertSame('', $element->attributes[1]->value->static);
        $this->assertSame(1, $pendingAttr['attrIndex']);
        $this->assertNull($pendingAttr['quote']);
    }

    public function testConsumeAttributeContinuationSetsPendingAttributeForUnclosedQuote(): void
    {
        $element = new ElementNode('div', [new AttributeNode('x-data', AttributeValue::static(''), 1, 1)], [], false, 1, 1);
        $pending = ['element' => $element, 'attrIndex' => 0, 'quote' => null];

        [$html, $pendingAttr] = AttributeContinuation::consumeAttributeContinuation(
            'value data-id="nope',
            $pending,
            $this->factory(),
        );

        $this->assertSame('', $html);
        $this->assertNotNull($pendingAttr);
        $this->assertSame('data-id', $element->attributes[1]->name);
        $this->assertSame('nope', $element->attributes[1]->value->static);
        $this->assertSame(1, $pendingAttr['attrIndex']);
        $this->assertSame('"', $pendingAttr['quote']);
    }

    private function factory(): NodeFactory
    {
        return new NodeFactory();
    }
}
