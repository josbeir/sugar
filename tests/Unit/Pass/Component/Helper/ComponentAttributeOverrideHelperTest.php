<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Component\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\OutputNode;
use Sugar\Enum\OutputContext;
use Sugar\Pass\Component\Helper\ComponentAttributeOverrideHelper;
use Sugar\Runtime\HtmlAttributeHelper;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

final class ComponentAttributeOverrideHelperTest extends TestCase
{
    use NodeBuildersTrait;

    public function testAppliesRuntimeAttributeOverrides(): void
    {
        $outputNode = $this->outputNode('$id', true, OutputContext::HTML_ATTRIBUTE, 1, 1);
        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('class', 'btn', 1, 1),
                new AttributeNode('data-id', $outputNode, 1, 1),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $document = $this->document()->withChild($element)->build();

        ComponentAttributeOverrideHelper::apply($document, '$__sugar_attrs');

        $this->assertCount(3, $element->attributes);

        $classAttr = $element->attributes[0];
        $this->assertSame('class', $classAttr->name);
        $this->assertInstanceOf(OutputNode::class, $classAttr->value);
        $this->assertStringContainsString(HtmlAttributeHelper::class . '::classNames', $classAttr->value->expression);
        $this->assertStringContainsString('$__sugar_attrs[\'class\']', $classAttr->value->expression);

        $dataAttr = $element->attributes[1];
        $this->assertSame('data-id', $dataAttr->name);
        $this->assertInstanceOf(OutputNode::class, $dataAttr->value);
        $this->assertStringContainsString('$__sugar_attrs[\'data-id\']', $dataAttr->value->expression);

        $spreadAttr = $element->attributes[2];
        $this->assertSame('', $spreadAttr->name);
        $this->assertInstanceOf(OutputNode::class, $spreadAttr->value);
        $this->assertStringContainsString(HtmlAttributeHelper::class . '::spreadAttrs', $spreadAttr->value->expression);
    }

    public function testNoRootElementLeavesDocumentUnchanged(): void
    {
        $text = $this->text('Hello', 1, 1);
        $document = $this->document()->withChild($text)->build();

        ComponentAttributeOverrideHelper::apply($document, '$__sugar_attrs');

        $this->assertSame($text, $document->children[0]);
    }
}
