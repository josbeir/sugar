<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Component;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\TextNode;
use Sugar\Enum\OutputContext;
use Sugar\Pass\Component\Helper\SlotResolver;

final class SlotResolverTest extends TestCase
{
    public function testExtractsSlotsAndBuildsExpressions(): void
    {
        $slotResolver = new SlotResolver('s:slot');

        $header = new ElementNode(
            tag: 'h1',
            attributes: [
                new AttributeNode('s:slot', 'header', 1, 1),
                new AttributeNode('class', 'title', 1, 1),
            ],
            children: [new TextNode('Header', 1, 1)],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $footer = new FragmentNode(
            attributes: [new AttributeNode('s:slot', 'footer', 1, 1)],
            children: [new TextNode('Footer', 1, 1)],
            line: 1,
            column: 1,
        );

        $default = new TextNode('Body', 1, 1);

        $slots = $slotResolver->extract([$header, $default, $footer]);

        $this->assertSame(['header', 'footer'], array_keys($slots->namedSlots));
        $this->assertSame([$default], $slots->defaultSlot);

        $headerSlot = $slots->namedSlots['header'][0];
        $this->assertInstanceOf(ElementNode::class, $headerSlot);
        foreach ($headerSlot->attributes as $attribute) {
            $this->assertNotSame('s:slot', $attribute->name);
        }

        $this->assertSame(['slot', 'header', 'footer'], $slotResolver->buildSlotVars($slots));

        $expression = $slotResolver->buildSlotsExpression($slots);
        $this->assertStringContainsString("'slot' => 'Body'", $expression);
        $this->assertStringContainsString("'header' =>", $expression);
        $this->assertStringContainsString("'footer' =>", $expression);
        $this->assertStringNotContainsString('s:slot', $expression);
    }

    public function testBuildsSlotExpressionsWithOutputNodes(): void
    {
        $slotResolver = new SlotResolver('s:slot');

        $header = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('s:slot', 'header', 1, 1),
                new AttributeNode(
                    'data-id',
                    new OutputNode('$id', true, OutputContext::HTML_ATTRIBUTE, 1, 1),
                    1,
                    1,
                ),
            ],
            children: [new OutputNode('$title', true, OutputContext::HTML, 1, 1)],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $slots = $slotResolver->extract([$header]);

        $expression = $slotResolver->buildSlotsExpression($slots);
        $this->assertStringContainsString("'header' =>", $expression);
        $this->assertStringContainsString('<?= $title ?>', $expression);
        $this->assertStringContainsString('data-id', $expression);
        $this->assertStringContainsString('<?= $id ?>', $expression);
    }
}
