<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Component;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Config\Helper\DirectivePrefixHelper;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\Component\Helper\ComponentAttributeCategorizer;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

final class ComponentAttributeCategorizerTest extends TestCase
{
    use NodeBuildersTrait;

    public function testCategorizesComponentAttributes(): void
    {
        $registry = new DirectiveRegistry();
        $prefixHelper = new DirectivePrefixHelper('s');
        $categorizer = new ComponentAttributeCategorizer($registry, $prefixHelper);

        $attributes = [
            $this->attribute('s:if', '$user'),
            $this->attribute('s:class', 'btn'),
            $this->attribute('s:bind', "['type' => 'warning']"),
            $this->attribute('id', 'main'),
        ];

        $categorized = $categorizer->categorize($attributes);

        $this->assertCount(1, $categorized->controlFlow);
        $this->assertSame('s:if', $categorized->controlFlow[0]->name);

        $this->assertCount(1, $categorized->attributeDirectives);
        $this->assertSame('s:class', $categorized->attributeDirectives[0]->name);

        $this->assertInstanceOf(AttributeNode::class, $categorized->componentBindings);
        $this->assertSame('s:bind', $categorized->componentBindings->name);

        $this->assertCount(1, $categorized->merge);
        $this->assertSame('id', $categorized->merge[0]->name);
    }

    public function testTreatsUnknownDirectiveAsAttributeDirective(): void
    {
        $registry = new DirectiveRegistry();
        $prefixHelper = new DirectivePrefixHelper('s');
        $categorizer = new ComponentAttributeCategorizer($registry, $prefixHelper);

        $attributes = [
            $this->attribute('s:unknown', 'value'),
        ];

        $categorized = $categorizer->categorize($attributes);

        $this->assertCount(0, $categorized->controlFlow);
        $this->assertCount(1, $categorized->attributeDirectives);
        $this->assertSame('s:unknown', $categorized->attributeDirectives[0]->name);
        $this->assertNotInstanceOf(AttributeNode::class, $categorized->componentBindings);
        $this->assertCount(0, $categorized->merge);
    }
}
