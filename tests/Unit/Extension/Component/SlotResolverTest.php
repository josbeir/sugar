<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Enum\OutputContext;
use Sugar\Extension\Component\Helper\SlotResolver;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

final class SlotResolverTest extends TestCase
{
    use NodeBuildersTrait;

    public function testExtractsSlotsAndBuildsExpressions(): void
    {
        $slotResolver = new SlotResolver('s:slot');

        $header = $this->element('h1')
            ->attribute('s:slot', 'header')
            ->attribute('class', 'title')
            ->withChild($this->text('Header', 1, 1))
            ->build();

        $footer = $this->fragment(
            attributes: [$this->attribute('s:slot', 'footer')],
            children: [$this->text('Footer', 1, 1)],
            line: 1,
            column: 1,
        );

        $default = $this->text('Body', 1, 1);

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

        $header = $this->element('div')
            ->attribute('s:slot', 'header')
            ->attributeNode(
                $this->attributeNode(
                    'data-id',
                    $this->outputNode('$id', true, OutputContext::HTML_ATTRIBUTE, 1, 1),
                ),
            )
            ->withChild($this->outputNode('$title', true, OutputContext::HTML, 1, 1))
            ->build();

        $slots = $slotResolver->extract([$header]);

        $expression = $slotResolver->buildSlotsExpression($slots);
        $this->assertStringContainsString("'header' =>", $expression);
        $this->assertStringContainsString('<?= $title ?>', $expression);
        $this->assertStringContainsString('data-id', $expression);
        $this->assertStringContainsString('<?= $id ?>', $expression);
    }
}
