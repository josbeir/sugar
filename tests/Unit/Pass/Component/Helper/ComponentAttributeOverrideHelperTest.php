<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass\Component\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeValue;
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
        $element = $this->element('div')
            ->attribute('class', 'btn')
            ->attributeNode($this->attributeNode('data-id', $outputNode))
            ->build();

        $document = $this->document()->withChild($element)->build();

        ComponentAttributeOverrideHelper::apply($document, '$__sugar_attrs');

        $this->assertCount(3, $element->attributes);

        $classAttr = $element->attributes[0];
        $this->assertSame('class', $classAttr->name);
        $this->assertTrue($classAttr->value->isOutput());
        $this->assertInstanceOf(OutputNode::class, $classAttr->value->output);
        $this->assertStringContainsString(HtmlAttributeHelper::class . '::classNames', $classAttr->value->output->expression);
        $this->assertStringContainsString('$__sugar_attrs[\'class\']', $classAttr->value->output->expression);

        $dataAttr = $element->attributes[1];
        $this->assertSame('data-id', $dataAttr->name);
        $this->assertTrue($dataAttr->value->isOutput());
        $this->assertInstanceOf(OutputNode::class, $dataAttr->value->output);
        $this->assertStringContainsString('$__sugar_attrs[\'data-id\']', $dataAttr->value->output->expression);

        $spreadAttr = $element->attributes[2];
        $this->assertSame('', $spreadAttr->name);
        $this->assertTrue($spreadAttr->value->isOutput());
        $this->assertInstanceOf(OutputNode::class, $spreadAttr->value->output);
        $this->assertStringContainsString(HtmlAttributeHelper::class . '::spreadAttrs', $spreadAttr->value->output->expression);
    }

    public function testNoRootElementLeavesDocumentUnchanged(): void
    {
        $text = $this->text('Hello', 1, 1);
        $document = $this->document()->withChild($text)->build();

        ComponentAttributeOverrideHelper::apply($document, '$__sugar_attrs');

        $this->assertSame($text, $document->children[0]);
    }

    public function testAppliesOverridesToFirstRootElementOnly(): void
    {
        $first = $this->element('div')
            ->attribute('id', 'first')
            ->build();
        $second = $this->element('div')
            ->attributeNode($this->attributeNode('id', 'second', 2, 1))
            ->build();

        $document = $this->document()->withChildren([$first, $second])->build();

        ComponentAttributeOverrideHelper::apply($document, '$__sugar_attrs');

        $this->assertCount(2, $first->attributes);
        $this->assertSame('', $first->attributes[1]->name);
        $this->assertTrue($second->attributes[0]->value->isStatic());
        $this->assertSame('second', $second->attributes[0]->value->static);
        $this->assertCount(1, $second->attributes);
    }

    public function testAttributeValueExpressionHandlesBooleanAndParts(): void
    {
        $titleParts = AttributeValue::parts([
            'Hello ',
            $this->outputNode('$name', true, OutputContext::HTML_ATTRIBUTE, 1, 10),
        ]);

        $element = $this->element('div')
            ->attributeNode($this->attributeNode('disabled', null, 1, 1))
            ->attributeNode($this->attributeNode('title', $titleParts, 1, 5))
            ->build();

        $document = $this->document()->withChild($element)->build();

        ComponentAttributeOverrideHelper::apply($document, '$__sugar_attrs');

        $disabledAttr = $element->attributes[0];
        $this->assertSame('disabled', $disabledAttr->name);
        $this->assertTrue($disabledAttr->value->isOutput());
        $this->assertInstanceOf(OutputNode::class, $disabledAttr->value->output);
        $this->assertStringContainsString('$__sugar_attrs[\'disabled\'] ?? null', $disabledAttr->value->output->expression);

        $titleAttr = $element->attributes[1];
        $this->assertSame('title', $titleAttr->name);
        $this->assertTrue($titleAttr->value->isOutput());
        $this->assertInstanceOf(OutputNode::class, $titleAttr->value->output);
        $this->assertStringContainsString('$__sugar_attrs[\'title\'] ??', $titleAttr->value->output->expression);
        $this->assertStringContainsString("'Hello '", $titleAttr->value->output->expression);
        $this->assertStringContainsString('$name', $titleAttr->value->output->expression);
    }
}
