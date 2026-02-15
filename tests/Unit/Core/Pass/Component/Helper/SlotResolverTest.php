<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Component\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Enum\OutputContext;
use Sugar\Core\Pass\Component\Helper\SlotResolver;
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
        $element = $this->element('div')
            ->attributeNode($this->attributeNode('title', $attributeOutput))
            ->build();

        $fragment = $this->fragment(
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
