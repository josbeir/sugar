<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Component\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Enum\OutputContext;
use Sugar\Pass\Component\Helper\SlotResolver;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

final class SlotResolverTest extends TestCase
{
    use NodeBuildersTrait;

    public function testDisablesEscapingForSlotVariables(): void
    {
        $slotOutput = $this->outputNode('$slot', true, OutputContext::HTML, 1, 1);
        $headerOutput = $this->outputNode('$header ?? ""', true, OutputContext::HTML, 1, 1);
        $otherOutput = $this->outputNode('$content', true, OutputContext::HTML, 1, 1);

        $attributeOutput = $this->outputNode('$slot', true, OutputContext::HTML_ATTRIBUTE, 1, 1);
        $element = new ElementNode(
            tag: 'div',
            attributes: [new AttributeNode('title', $attributeOutput, 1, 1)],
            children: [],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $fragment = new FragmentNode(
            attributes: [],
            children: [$headerOutput, $this->text('x', 1, 1)],
            line: 1,
            column: 1,
        );

        $document = $this->document()
            ->withChildren([$slotOutput, $element, $otherOutput, $fragment])
            ->build();

        SlotResolver::disableEscaping($document, ['slot', 'header']);

        $this->assertFalse($slotOutput->escape);
        $this->assertFalse($headerOutput->escape);
        $this->assertFalse($attributeOutput->escape);
        $this->assertTrue($otherOutput->escape);
    }

    public function testDoesNotDisableEscapingForSimilarVariableNames(): void
    {
        $slotOutput = $this->outputNode('$slot', true, OutputContext::HTML, 1, 1);
        $slotNameOutput = $this->outputNode('$slotName', true, OutputContext::HTML, 1, 1);

        $document = $this->document()
            ->withChildren([$slotOutput, $slotNameOutput])
            ->build();

        SlotResolver::disableEscaping($document, ['slot']);

        $this->assertFalse($slotOutput->escape);
        $this->assertTrue($slotNameOutput->escape);
    }
}
