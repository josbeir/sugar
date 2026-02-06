<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\DirectiveCompilerInterface;
use Sugar\Enum\DirectiveType;
use Sugar\Exception\SyntaxException;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\Directive\DirectiveCompilationPass;
use Sugar\Pass\PassInterface;

final class DirectiveCompilationPassTest extends PassTestCase
{
    protected function getPass(): PassInterface
    {
        $this->registry = new DirectiveRegistry();

        return new DirectiveCompilationPass($this->registry);
    }

    public function testCompilesSimpleDirective(): void
    {
        $compiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node, CompilationContext $context): array
            {
                return [
                    new RawPhpNode('<?php if ($condition): ?>', 1, 0),
                    ...$node->children,
                    new RawPhpNode('<?php endif; ?>', 1, 0),
                ];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::CONTROL_FLOW;
            }
        };

        $this->registry->register('if', $compiler);

        $directive = $this->directive('if')
            ->expression('$condition')
            ->withChild($this->text('Content', 1, 10))
            ->build();

        $ast = $this->document()->withChild($directive)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(3)
            ->containsNodeType(RawPhpNode::class)
            ->containsText('Content');
    }

    public function testCompilesNestedDirectives(): void
    {
        $ifCompiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node, CompilationContext $context): array
            {
                return [
                    new RawPhpNode('<?php if ($x): ?>', 1, 0),
                    ...$node->children,
                    new RawPhpNode('<?php endif; ?>', 1, 0),
                ];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::CONTROL_FLOW;
            }
        };

        $foreachCompiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node, CompilationContext $context): array
            {
                return [
                    new RawPhpNode('<?php foreach ($items as $item): ?>', 1, 0),
                    ...$node->children,
                    new RawPhpNode('<?php endforeach; ?>', 1, 0),
                ];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::CONTROL_FLOW;
            }
        };

        $this->registry->register('if', $ifCompiler);
        $this->registry->register('foreach', $foreachCompiler);

        $innerDirective = $this->directive('if')
            ->expression('$x')
            ->withChild($this->text('Inner', 2, 5))
            ->at(2, 4)
            ->build();

        $outerDirective = $this->directive('foreach')
            ->expression('$items')
            ->withChild(
                $this->element('div')
                    ->withChild($innerDirective)
                    ->at(1, 10)
                    ->build(),
            )
            ->at(1, 0)
            ->build();

        $ast = $this->document()->withChild($outerDirective)->build();
        $result = $this->execute($ast, $this->createTestContext());

        // Should compile both directives
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]); // foreach open
        $this->assertInstanceOf(ElementNode::class, $result->children[1]); // div with compiled if inside
        $this->assertInstanceOf(RawPhpNode::class, $result->children[2]); // foreach close

        // Check the inner directive was compiled
        $divElement = $result->children[1];
        $this->assertInstanceOf(ElementNode::class, $divElement);
        $this->assertCount(3, $divElement->children);
        $this->assertInstanceOf(RawPhpNode::class, $divElement->children[0]); // if open
        $this->assertInstanceOf(TextNode::class, $divElement->children[1]); // content
        $this->assertInstanceOf(RawPhpNode::class, $divElement->children[2]); // if close
    }

    public function testLeavesNonDirectiveNodesUnchanged(): void
    {
        $element = $this->element('div')
            ->withChild($this->text('Content', 1, 10))
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsElement('div')
            ->containsText('Content');
    }

    public function testThrowsOnUnknownDirective(): void
    {
        $directive = $this->directive('unknown')
            ->expression('$value')
            ->build();

        $ast = $this->document()->withChild($directive)->build();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Unknown directive "unknown"');

        $this->execute($ast, $this->createTestContext());
    }

    public function testHandlesCompilerReturningMultipleNodes(): void
    {
        $compiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node, CompilationContext $context): array
            {
                return [
                    new RawPhpNode('<?php // Start ?>', 1, 0),
                    new RawPhpNode('<?php if (true): ?>', 1, 0),
                    new TextNode('Content', 1, 0),
                    new RawPhpNode('<?php endif; ?>', 1, 0),
                    new RawPhpNode('<?php // End ?>', 1, 0),
                ];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::CONTROL_FLOW;
            }
        };

        $this->registry->register('custom', $compiler);

        $directive = $this->directive('custom')
            ->expression('$value')
            ->build();

        $ast = $this->document()->withChild($directive)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(5)
            ->containsNodeType(RawPhpNode::class)
            ->containsNodeType(TextNode::class);
    }

    public function testProcessesMultipleTopLevelDirectives(): void
    {
        $compiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node, CompilationContext $context): array
            {
                return [
                    new RawPhpNode(sprintf('<?php // %s ?>', $node->expression), 1, 0),
                    ...$node->children,
                ];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::CONTROL_FLOW;
            }
        };

        $this->registry->register('test', $compiler);

        $directive1 = $this->directive('test')
            ->expression('first')
            ->withChild($this->text('One', 1, 0))
            ->at(1, 0)
            ->build();

        $directive2 = $this->directive('test')
            ->expression('second')
            ->withChild($this->text('Two', 2, 0))
            ->at(2, 0)
            ->build();

        $ast = $this->document()->withChildren([$directive1, $directive2])->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(4)
            ->containsNodeType(RawPhpNode::class)
            ->containsNodeType(TextNode::class);
    }

    public function testHandlesDirectiveWithinElementChildren(): void
    {
        $compiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node, CompilationContext $context): array
            {
                return [
                    new RawPhpNode('<?php if (true): ?>', 1, 0),
                    ...$node->children,
                    new RawPhpNode('<?php endif; ?>', 1, 0),
                ];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::CONTROL_FLOW;
            }
        };

        $this->registry->register('if', $compiler);

        $directive = $this->directive('if')
            ->expression('$show')
            ->withChild($this->text('Visible', 2, 5))
            ->at(2, 4)
            ->build();

        $element = $this->element('div')
            ->withChild($directive)
            ->at(1, 0)
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $divElement = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $divElement);
        $this->assertCount(3, $divElement->children);
        $this->assertInstanceOf(RawPhpNode::class, $divElement->children[0]);
        $this->assertInstanceOf(TextNode::class, $divElement->children[1]);
        $this->assertInstanceOf(RawPhpNode::class, $divElement->children[2]);
    }

    public function testHandlesMixedContentWithDirectives(): void
    {
        $compiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node, CompilationContext $context): array
            {
                return [
                    new RawPhpNode('<?php /* compiled */ ?>', 1, 0),
                    ...$node->children,
                ];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::CONTROL_FLOW;
            }
        };

        $this->registry->register('test', $compiler);

        $directive = $this->directive('test')
            ->expression('$x')
            ->withChild($this->text('Dir', 2, 5))
            ->at(2, 0)
            ->build();

        $element = $this->element('section')
            ->withChildren([
                $this->text('Before', 1, 10),
                $directive,
                $this->text('After', 3, 10),
            ])
            ->at(1, 0)
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $sectionElement = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $sectionElement);
        $this->assertCount(4, $sectionElement->children);
        $this->assertInstanceOf(TextNode::class, $sectionElement->children[0]); // Before
        $this->assertInstanceOf(RawPhpNode::class, $sectionElement->children[1]); // Compiled directive
        $this->assertInstanceOf(TextNode::class, $sectionElement->children[2]); // Dir (from directive)
        $this->assertInstanceOf(TextNode::class, $sectionElement->children[3]); // After
    }

    public function testPreservesSelfClosingElements(): void
    {
        $element = $this->element('input')
            ->selfClosing()
            ->at(1, 0)
            ->build();

        $ast = $this->document()->withChild($element)->build();
        $result = $this->execute($ast, $this->createTestContext());

        $this->assertAst($result)
            ->hasCount(1)
            ->containsElement('input');

        $inputElement = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $inputElement);
        $this->assertTrue($inputElement->selfClosing);
    }

    public function testRecursivelyProcessesCompiledNodes(): void
    {
        // Compiler that returns a node containing another directive
        $outerCompiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node, CompilationContext $context): array
            {
                return [
                    new RawPhpNode('<?php // Outer start ?>', 1, 0),
                    new DirectiveNode(
                        name: 'inner',
                        expression: '$nested',
                        children: [new TextNode('Nested', 1, 0)],
                        line: 1,
                        column: 0,
                    ),
                    new RawPhpNode('<?php // Outer end ?>', 1, 0),
                ];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::CONTROL_FLOW;
            }
        };

        $innerCompiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node, CompilationContext $context): array
            {
                return [
                    new RawPhpNode('<?php // Inner ?>', 1, 0),
                    ...$node->children,
                ];
            }

            public function getType(): DirectiveType
            {
                return DirectiveType::CONTROL_FLOW;
            }
        };

        $this->registry->register('outer', $outerCompiler);
        $this->registry->register('inner', $innerCompiler);

        $directive = $this->directive('outer')
            ->expression('$x')
            ->at(1, 0)
            ->build();

        $ast = $this->document()->withChild($directive)->build();
        $result = $this->execute($ast, $this->createTestContext());

        // Should compile both outer and inner directives
        $this->assertAst($result)
            ->hasCount(4)
            ->containsNodeType(RawPhpNode::class)
            ->containsNodeType(TextNode::class);
    }

    protected function createContext(
        string $source = '',
        string $templatePath = 'test.sugar.php',
        bool $debug = false,
    ): CompilationContext {
        return new CompilationContext($templatePath, $source, $debug);
    }
}
