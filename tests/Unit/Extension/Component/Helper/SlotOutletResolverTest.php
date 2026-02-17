<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Extension\Component\Helper\ComponentSlots;
use Sugar\Extension\Component\Helper\SlotOutletResolver;
use Sugar\Tests\Helper\Trait\AstStringifyTrait;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

final class SlotOutletResolverTest extends TestCase
{
    use AstStringifyTrait;
    use NodeBuildersTrait;

    public function testReplacesNamedElementOutletAndRemovesSlotAttribute(): void
    {
        $document = $this->document()
            ->withChild(
                $this->element('header')
                    ->attribute('s:slot', 'header')
                    ->withChild($this->text('Fallback Header', 1, 1))
                    ->build(),
            )
            ->build();

        $slots = new ComponentSlots(
            namedSlots: ['header' => [$this->element('h1')->withChild($this->text('Custom Header', 1, 1))->build()]],
            defaultSlot: [],
        );

        $resolver = new SlotOutletResolver('s:slot');
        $result = $resolver->apply($document, $slots);
        $output = $this->astToString($result);

        $this->assertStringContainsString('Custom Header', $output);
        $this->assertStringNotContainsString('Fallback Header', $output);
        $this->assertStringNotContainsString('s:slot', $output);
    }

    public function testKeepsFallbackWhenNamedSlotIsMissing(): void
    {
        $document = $this->document()
            ->withChild(
                $this->element('header')
                    ->attribute('s:slot', 'header')
                    ->withChild($this->text('Fallback Header', 1, 1))
                    ->build(),
            )
            ->build();

        $resolver = new SlotOutletResolver('s:slot');
        $result = $resolver->apply($document, new ComponentSlots(namedSlots: [], defaultSlot: []));
        $output = $this->astToString($result);

        $this->assertStringContainsString('Fallback Header', $output);
        $this->assertStringNotContainsString('s:slot', $output);
    }

    public function testThrowsOnNonStaticOutletValue(): void
    {
        $document = $this->document()
            ->withChild(
                $this->element('header')
                    ->attributeNode($this->attributeNode('s:slot', $this->outputNode('$name', true), 1, 1))
                    ->withChild($this->text('Fallback Header', 1, 1))
                    ->build(),
            )
            ->build();

        $resolver = new SlotOutletResolver('s:slot');

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:slot outlet value must be a static slot name.');

        $resolver->apply($document, new ComponentSlots(namedSlots: [], defaultSlot: []));
    }
}
