<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Helper;

use Closure;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawBodyNode;
use Sugar\Core\Ast\RuntimeCallNode;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Extension\Component\Helper\ComponentSlots;
use Sugar\Extension\Component\Helper\SlotResolver;
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

    public function testExtractSeparatesNamedAndDefaultSlots(): void
    {
        $resolver = new SlotResolver('s:slot');

        $headerElement = $this->element('header')
            ->attribute('s:slot', 'header')
            ->attribute('class', 'hero')
            ->withChild($this->text('Title'))
            ->build();

        $footerFragment = $this->fragment(
            attributes: [$this->attribute('s:slot', 'footer')],
            children: [$this->text('Footer')],
        );

        $dynamicSlotElement = $this->element('aside')
            ->attributeNode($this->attributeNode('s:slot', $this->outputNode('$dynamicSlot', true, OutputContext::HTML)))
            ->withChild($this->text('Dynamic slot name'))
            ->build();

        $slots = $resolver->extract([
            $headerElement,
            $this->text('Body'),
            $footerFragment,
            $dynamicSlotElement,
        ]);

        $this->assertArrayHasKey('header', $slots->namedSlots);
        $this->assertArrayHasKey('footer', $slots->namedSlots);
        $this->assertCount(2, $slots->namedSlots);
        $this->assertCount(2, $slots->defaultSlot);

        $headerSlotNode = $slots->namedSlots['header'][0];
        $this->assertInstanceOf(ElementNode::class, $headerSlotNode);
        $this->assertSame('header', $headerSlotNode->tag);
        $this->assertCount(1, $headerSlotNode->attributes);
        $this->assertSame('class', $headerSlotNode->attributes[0]->name);
        $this->assertSame('hero', $headerSlotNode->attributes[0]->value->static);
    }

    public function testBuildSlotVarsIncludesDefaultAndNamedSlotKeys(): void
    {
        $resolver = new SlotResolver('s:slot');

        $vars = $resolver->buildSlotVars(new ComponentSlots(
            namedSlots: [
                'header' => [$this->text('H')],
                'footer' => [$this->text('F')],
            ],
            defaultSlot: [],
        ));

        $this->assertSame(['slot', 'header', 'footer'], $vars);
    }

    public function testBuildSlotItemsRendersEmptyDefaultSlot(): void
    {
        $resolver = new SlotResolver('s:slot');

        $items = $resolver->buildSlotItems(new ComponentSlots(
            namedSlots: [
                'header' => [$this->text('Header')],
            ],
            defaultSlot: [],
        ));

        $this->assertSame("'slot' => ''", $items[0]);
        $this->assertSame("'header' => 'Header'", $items[1]);
    }

    public function testBuildSlotsExpressionSerializesDynamicNodes(): void
    {
        $resolver = new SlotResolver('s:slot');

        $dynamicElement = $this->element('div')
            ->attributeNode($this->attributeNode('data-id', AttributeValue::parts([
                'item-',
                $this->outputNode('$id', false, OutputContext::RAW),
            ])))
            ->withChild($this->outputNode('$content', false, OutputContext::RAW))
            ->build();

        $staticElementWithOutputAttribute = $this->element('p')
            ->attributeNode($this->attributeNode('title', AttributeValue::output(
                $this->outputNode('$title', false, OutputContext::RAW),
            )))
            ->withChild($this->text('Static body'))
            ->build();

        $slots = new ComponentSlots(
            namedSlots: [
                'header' => [
                    $this->outputNode('$header', false, OutputContext::RAW),
                    new RuntimeCallNode('renderPartial', ['1', '2'], 1, 1),
                ],
            ],
            defaultSlot: [
                $dynamicElement,
                $staticElementWithOutputAttribute,
                new RawBodyNode('raw-body', 1, 1),
            ],
        );

        $expression = $resolver->buildSlotsExpression($slots);

        $this->assertStringContainsString("'slot' =>", $expression);
        $this->assertStringContainsString('"item-$id"', $expression);
        $this->assertStringContainsString('($content)', $expression);
        $this->assertStringContainsString("'</div>'", $expression);
        $this->assertStringContainsString('<p title="<?= $title ?>">Static body</p>', $expression);
        $this->assertStringContainsString('raw-body', $expression);
        $this->assertStringContainsString("'header' => (", $expression);
        $this->assertStringContainsString('(renderPartial(1, 2))', $expression);
    }

    public function testDisableEscapingHandlesBooleanAndPartsAttributes(): void
    {
        $slotInParts = $this->outputNode('$slot', true, OutputContext::HTML_ATTRIBUTE);
        $titleInParts = $this->outputNode('$title', true, OutputContext::HTML_ATTRIBUTE);

        $node = $this->element('div')
            ->attributeNode($this->attributeNode('disabled', null))
            ->attributeNode($this->attributeNode('data-part', AttributeValue::parts(['x-', $slotInParts, '-', $titleInParts])))
            ->build();

        SlotResolver::disableEscaping($node, ['slot']);

        $this->assertFalse($slotInParts->escape);
        $this->assertTrue($titleInParts->escape);
    }

    public function testPrivateSerializationBranchesAreReachable(): void
    {
        $resolver = new SlotResolver('s:slot');

        $nodeToPhpExpression = Closure::bind(
            fn(Node $node): string => $this->nodeToPhpExpression($node),
            $resolver,
            $resolver::class,
        );

        $nodeToString = Closure::bind(
            fn(Node $node): string => $this->nodeToString($node),
            $resolver,
            $resolver::class,
        );

        $runtimeCall = new RuntimeCallNode('resolverCall', ['1'], 1, 1);
        $this->assertSame('(resolverCall(1))', $nodeToPhpExpression($runtimeCall));

        $nestedDynamic = $this->element('section')
            ->withChild($this->element('span')->withChild($this->outputNode('$nested', false, OutputContext::RAW))->build())
            ->build();
        $nestedExpression = $nodeToPhpExpression($nestedDynamic);
        $this->assertStringContainsString('($nested)', $nestedExpression);

        $partsElement = $this->element('p')
            ->attributeNode($this->attributeNode('title', AttributeValue::parts([
                'before-',
                $this->outputNode('$name', false, OutputContext::RAW),
                '-after',
            ])))
            ->withChild($this->text('Body'))
            ->build();
        $this->assertSame('<p title="before-<?= $name ?>-after">Body</p>', $nodeToString($partsElement));

        $this->assertSame('<?= $value ?>', $nodeToString($this->outputNode('$value', false, OutputContext::RAW)));
        $this->assertSame('<?= callX(1, 2) ?>', $nodeToString(new RuntimeCallNode('callX', ['1', '2'], 1, 1)));

        $unknownNode = new class (1, 1) extends Node {
        };
        $this->assertSame('', $nodeToString($unknownNode));
    }
}
