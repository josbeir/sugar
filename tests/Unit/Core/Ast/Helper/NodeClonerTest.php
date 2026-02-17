<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Ast\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Helper\NodeCloner;
use Sugar\Core\Ast\OutputNode;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;

final class NodeClonerTest extends TestCase
{
    use NodeBuildersTrait;

    public function testWithChildren(): void
    {
        $node = $this->element('div')
            ->attribute('id', 'main')
            ->withChild($this->text('old', 1, 1))
            ->at(1, 1)
            ->build();

        $newChildren = [$this->text('new', 1, 1)];
        $result = NodeCloner::withChildren($node, $newChildren);

        $this->assertNotSame($node, $result);
        $this->assertSame('div', $result->tag);
        $this->assertSame($node->attributes, $result->attributes);
        $this->assertSame($newChildren, $result->children);
    }

    public function testWithAttributesAndChildren(): void
    {
        $node = $this->element('div')
            ->attribute('id', 'old')
            ->withChild($this->text('old', 1, 1))
            ->at(1, 1)
            ->build();

        $newAttributes = [$this->attribute('id', 'new', 1, 1)];
        $newChildren = [$this->text('new', 1, 1)];
        $result = NodeCloner::withAttributesAndChildren($node, $newAttributes, $newChildren);

        $this->assertNotSame($node, $result);
        $this->assertSame('div', $result->tag);
        $this->assertSame($newAttributes, $result->attributes);
        $this->assertSame($newChildren, $result->children);
        $this->assertSame($node->selfClosing, $result->selfClosing);
        $this->assertSame($node->line, $result->line);
        $this->assertSame($node->column, $result->column);
    }

    public function testFragmentWithChildren(): void
    {
        $node = $this->fragment(
            attributes: [$this->attribute('s:if', '$show', 1, 1)],
            children: [$this->text('old', 1, 1)],
            line: 1,
            column: 1,
        );

        $newChildren = [$this->text('new', 1, 1)];
        $result = NodeCloner::fragmentWithChildren($node, $newChildren);

        $this->assertNotSame($node, $result);
        $this->assertSame($node->attributes, $result->attributes);
        $this->assertSame($newChildren, $result->children);
        $this->assertSame($node->line, $result->line);
        $this->assertSame($node->column, $result->column);
    }

    public function testFragmentWithAttributes(): void
    {
        $node = $this->fragment(
            attributes: [$this->attribute('s:if', 'old', 1, 1)],
            children: [$this->text('content', 1, 1)],
            line: 1,
            column: 1,
        );

        $newAttributes = [$this->attribute('s:if', 'new', 1, 1)];
        $result = NodeCloner::fragmentWithAttributes($node, $newAttributes);

        $this->assertNotSame($node, $result);
        $this->assertSame($newAttributes, $result->attributes);
        $this->assertSame($node->children, $result->children);
        $this->assertSame($node->line, $result->line);
        $this->assertSame($node->column, $result->column);
    }

    public function testImmutabilityWithChildren(): void
    {
        $original = $this->element('span')
            ->attribute('class', 'original')
            ->withChild($this->text('text', 1, 1))
            ->at(5, 10)
            ->build();

        $modified = NodeCloner::withChildren($original, []);

        // Original unchanged
        $this->assertCount(1, $original->children);

        // Modified is different
        $this->assertCount(0, $modified->children);
        $this->assertSame($original->tag, $modified->tag);
        $this->assertSame($original->attributes, $modified->attributes);
    }

    public function testCloneDocumentDeepClonesTreeAndPreservesTemplatePath(): void
    {
        $output = $this->outputNode('$title', line: 2, column: 5);
        $output->setTemplatePath('templates/page.sugar.php');

        $attribute = $this->attributeNode('title', AttributeValue::output($output), 2, 1);
        $attribute->setTemplatePath('templates/page.sugar.php');

        $element = $this->element('h1')
            ->attributeNode($attribute)
            ->withChild($this->text('Header', 2, 10))
            ->at(2, 1)
            ->build();
        $element->setTemplatePath('templates/page.sugar.php');

        $document = $this->document()->withChild($element)->build();
        $document->setTemplatePath('templates/page.sugar.php');

        $cloned = NodeCloner::cloneDocument($document);

        $this->assertNotSame($document, $cloned);
        $this->assertSame('templates/page.sugar.php', $cloned->getTemplatePath());
        $this->assertCount(1, $cloned->children);
        $this->assertNotSame($document->children[0], $cloned->children[0]);

        $clonedElement = $cloned->children[0];
        $this->assertInstanceOf(ElementNode::class, $clonedElement);
        $this->assertSame('templates/page.sugar.php', $clonedElement->getTemplatePath());
        $this->assertNotSame($element->attributes[0], $clonedElement->attributes[0]);
        $this->assertNotSame($output, $clonedElement->attributes[0]->value->output);
    }

    public function testCloneNodesCreatesDistinctNodeInstances(): void
    {
        $first = $this->text('one', 1, 1);
        $second = $this->component('x-card', children: [$this->text('two', 2, 1)], line: 2, column: 1);

        $cloned = NodeCloner::cloneNodes([$first, $second]);

        $this->assertCount(2, $cloned);
        $this->assertNotSame($first, $cloned[0]);
        $this->assertNotSame($second, $cloned[1]);
        $this->assertInstanceOf(ComponentNode::class, $cloned[1]);
        $this->assertNotSame($second->children[0], $cloned[1]->children[0]);
    }

    public function testCloneAttributeValueOutputCreatesDistinctOutputNode(): void
    {
        $output = $this->outputNode('$value', line: 3, column: 7);
        $output->setTemplatePath('templates/value.sugar.php');

        $value = AttributeValue::output($output);

        $cloned = NodeCloner::cloneAttributeValue($value);

        $this->assertTrue($cloned->isOutput());
        $this->assertInstanceOf(OutputNode::class, $cloned->output);
        $this->assertNotSame($output, $cloned->output);
        $this->assertSame('$value', $cloned->output->expression);
        $this->assertSame('templates/value.sugar.php', $cloned->output->getTemplatePath());
    }

    public function testCloneAttributeValuePartsClonesOutputPartsOnly(): void
    {
        $output = $this->outputNode('$name', line: 4, column: 12);
        $output->setTemplatePath('templates/parts.sugar.php');

        $value = AttributeValue::parts(['prefix-', $output, '-suffix']);

        $cloned = NodeCloner::cloneAttributeValue($value);
        $parts = $cloned->toParts();

        $this->assertIsArray($parts);
        $this->assertCount(3, $parts);
        $this->assertSame('prefix-', $parts[0]);
        $this->assertInstanceOf(OutputNode::class, $parts[1]);
        $this->assertSame('-suffix', $parts[2]);
        $this->assertNotSame($output, $parts[1]);
        $this->assertSame('templates/parts.sugar.php', $parts[1]->getTemplatePath());
    }
}
