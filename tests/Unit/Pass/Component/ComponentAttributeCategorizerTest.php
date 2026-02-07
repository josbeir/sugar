<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Component;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\Helper\DirectivePrefixHelper;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\Component\Helper\ComponentAttributeCategorizer;

final class ComponentAttributeCategorizerTest extends TestCase
{
    public function testCategorizesComponentAttributes(): void
    {
        $registry = new DirectiveRegistry();
        $prefixHelper = new DirectivePrefixHelper('s');
        $categorizer = new ComponentAttributeCategorizer($registry, $prefixHelper);

        $attributes = [
            new AttributeNode('s:if', '$user', 1, 1),
            new AttributeNode('s:class', 'btn', 1, 1),
            new AttributeNode('s:bind', "['type' => 'warning']", 1, 1),
            new AttributeNode('id', 'main', 1, 1),
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
            new AttributeNode('s:unknown', 'value', 1, 1),
        ];

        $categorized = $categorizer->categorize($attributes);

        $this->assertCount(0, $categorized->controlFlow);
        $this->assertCount(1, $categorized->attributeDirectives);
        $this->assertSame('s:unknown', $categorized->attributeDirectives[0]->name);
        $this->assertNotInstanceOf(AttributeNode::class, $categorized->componentBindings);
        $this->assertCount(0, $categorized->merge);
    }
}
