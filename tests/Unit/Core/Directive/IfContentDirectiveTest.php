<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Directive;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\IfContentDirective;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Escape\Enum\OutputContext;

final class IfContentDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new IfContentDirective();
    }

    protected function getDirectiveName(): string
    {
        return 'ifcontent';
    }

    public function testCompilesIfContentDirective(): void
    {
        $node = new DirectiveNode(
            name: 'ifcontent',
            expression: '',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCountGreaterThan(3)
            ->containsNodeType(RawPhpNode::class);
    }

    public function testStartsOutputBuffering(): void
    {
        $node = new DirectiveNode(
            name: 'ifcontent',
            expression: '',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCountGreaterThan(3)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('ob_start()');
    }

    public function testStoresContentInVariable(): void
    {
        $node = new DirectiveNode(
            name: 'ifcontent',
            expression: '',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCountGreaterThan(3)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('$__content_');
    }

    public function testChecksForEmptyContent(): void
    {
        $node = new DirectiveNode(
            name: 'ifcontent',
            expression: '',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCountGreaterThan(3)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('trim')
            ->hasPhpCode("!== ''");
    }

    public function testUsesObGetClean(): void
    {
        $node = new DirectiveNode(
            name: 'ifcontent',
            expression: '',
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertAst($result)
            ->hasCountGreaterThan(3)
            ->containsNodeType(RawPhpNode::class)
            ->hasPhpCode('ob_get_clean()');
    }

    public function testCompilesElementWrapperWithMixedAttributes(): void
    {
        $node = new DirectiveNode(
            name: 'ifcontent',
            expression: '',
            children: [$this->text('Body')],
            line: 1,
            column: 0,
        );

        $node->setElementNode(new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('s:ifcontent', AttributeValue::boolean(), 1, 0),
                new AttributeNode('hidden', AttributeValue::boolean(), 1, 0),
                new AttributeNode('class', AttributeValue::static('card'), 1, 0),
                new AttributeNode('title', AttributeValue::output(new OutputNode('$title', true, OutputContext::HTML, 1, 0)), 1, 0),
                new AttributeNode('raw', AttributeValue::output(new OutputNode('$raw', false, OutputContext::HTML, 1, 0)), 1, 0),
                new AttributeNode('', AttributeValue::output(new OutputNode('$spread', false, OutputContext::HTML, 1, 0)), 1, 0),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 0,
        ));

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $compiledPhp = $this->extractPhpCode($result);

        $this->assertStringContainsString("echo '<div';", $compiledPhp);
        $this->assertStringContainsString("echo ' hidden';", $compiledPhp);
        $this->assertStringContainsString('class="card"', $compiledPhp);
        $this->assertStringContainsString('htmlspecialchars((string) ($title), ENT_QUOTES, \\"UTF-8\\");', $compiledPhp);
        $this->assertStringContainsString('echo $raw;', $compiledPhp);
        $this->assertStringContainsString('$__ifcontent_attr = $spread;', $compiledPhp);
        $this->assertStringContainsString("echo '>';", $compiledPhp);
        $this->assertStringContainsString("echo '</div>';", $compiledPhp);
    }

    public function testCompilesDynamicTagAndSelfClosingWithoutClosingTag(): void
    {
        $node = $this->directive('ifcontent')
            ->expression('')
            ->withChildren([$this->text('Body')])
            ->at(1, 0)
            ->build();

        $node->setElementNode(new ElementNode(
            tag: 'div',
            attributes: [],
            children: [],
            selfClosing: true,
            line: 1,
            column: 0,
            dynamicTag: '$tagName',
        ));

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $compiledPhp = $this->extractPhpCode($result);

        $this->assertStringContainsString('echo \'<\' . $tagName;', $compiledPhp);
        $this->assertStringContainsString("echo '>';", $compiledPhp);
        $this->assertStringNotContainsString('echo \'</\' . $tagName . \'>\';', $compiledPhp);
    }

    public function testExtractFromElementStoresElementMetadata(): void
    {
        $directive = new IfContentDirective();

        $element = $this->element('section')
            ->attributeNode($this->attributeNode('s:ifcontent', AttributeValue::boolean(), 1, 0))
            ->withChildren([$this->text('Inner')])
            ->at(1, 0)
            ->build();

        $remainingAttrs = [
            new AttributeNode('class', AttributeValue::static('panel'), 1, 0),
        ];

        $result = $directive->extractFromElement(
            $element,
            '',
            [$this->text('Inner')],
            $remainingAttrs,
        );

        $this->assertInstanceOf(DirectiveNode::class, $result);
        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->getElementNode());
        $this->assertCount(1, $result->getElementNode()->attributes);
        $this->assertSame('class', $result->getElementNode()->attributes[0]->name);
        $this->assertCount(0, $result->getElementNode()->children);
    }

    public function testGetTypeReturnsControlFlow(): void
    {
        $this->assertSame(DirectiveType::CONTROL_FLOW, $this->directiveCompiler->getType());
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $nodes
     */
    private function extractPhpCode(array $nodes): string
    {
        $parts = [];
        foreach ($nodes as $node) {
            if ($node instanceof RawPhpNode) {
                $parts[] = $node->code;
            }
        }

        return implode("\n", $parts);
    }
}
