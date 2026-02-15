<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Pass;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Enum\OutputContext;
use Sugar\Core\Runtime\HtmlAttributeHelper;
use Sugar\Extension\Component\Pass\ComponentVariantAdjustmentPass;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

final class ComponentVariantAdjustmentPassTest extends TestCase
{
    use NodeBuildersTrait;

    public function testBeforeDisablesEscapingForSlotVariables(): void
    {
        $slotOutput = $this->outputNode('$slot', true, OutputContext::HTML, 1, 1);
        $otherOutput = $this->outputNode('$title', true, OutputContext::HTML, 1, 10);
        $document = $this->document()->withChildren([$slotOutput, $otherOutput])->build();

        $pass = new ComponentVariantAdjustmentPass(['slot']);
        $pass->before($document, $this->createPipelineContext());

        $this->assertFalse($slotOutput->escape);
        $this->assertTrue($otherOutput->escape);
    }

    public function testAfterAppliesRuntimeAttributeOverrides(): void
    {
        $outputNode = $this->outputNode('$id', true, OutputContext::HTML_ATTRIBUTE, 1, 1);
        $element = $this->element('div')
            ->attribute('class', 'btn')
            ->attributeNode($this->attributeNode('data-id', $outputNode))
            ->build();

        $document = $this->document()->withChild($element)->build();

        $pass = new ComponentVariantAdjustmentPass([]);
        $pass->after($document, $this->createPipelineContext());

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

    public function testAfterNoRootElementLeavesDocumentUnchanged(): void
    {
        $text = $this->text('Hello', 1, 1);
        $document = $this->document()->withChild($text)->build();

        $pass = new ComponentVariantAdjustmentPass([]);
        $pass->after($document, $this->createPipelineContext());

        $this->assertSame($text, $document->children[0]);
    }

    public function testAfterAppliesOverridesToFirstRootElementOnly(): void
    {
        $first = $this->element('div')
            ->attribute('id', 'first')
            ->build();
        $second = $this->element('div')
            ->attributeNode($this->attributeNode('id', 'second', 2, 1))
            ->build();

        $document = $this->document()->withChildren([$first, $second])->build();

        $pass = new ComponentVariantAdjustmentPass([]);
        $pass->after($document, $this->createPipelineContext());

        $this->assertCount(2, $first->attributes);
        $this->assertSame('', $first->attributes[1]->name);
        $this->assertTrue($second->attributes[0]->value->isStatic());
        $this->assertSame('second', $second->attributes[0]->value->static);
        $this->assertCount(1, $second->attributes);
    }

    public function testAfterAttributeValueExpressionHandlesBooleanAndParts(): void
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

        $pass = new ComponentVariantAdjustmentPass([]);
        $pass->after($document, $this->createPipelineContext());

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

    private function createPipelineContext(): PipelineContext
    {
        $compilationContext = new CompilationContext(
            templatePath: 'test.sugar.php',
            source: '',
            debug: false,
        );

        return new PipelineContext($compilationContext, null, 0);
    }
}
