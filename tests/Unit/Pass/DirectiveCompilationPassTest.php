<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Context\CompilationContext;
use Sugar\Enum\DirectiveType;
use Sugar\Exception\UnknownDirectiveException;
use Sugar\Extension\DirectiveCompilerInterface;
use Sugar\Extension\ExtensionRegistry;
use Sugar\Pass\Directive\DirectiveCompilationPass;

final class DirectiveCompilationPassTest extends TestCase
{
    private ExtensionRegistry $registry;

    private DirectiveCompilationPass $pass;

    protected function setUp(): void
    {
        $this->registry = new ExtensionRegistry();
        $this->pass = new DirectiveCompilationPass($this->registry);
    }

    public function testCompilesSimpleDirective(): void
    {
        $compiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node): array
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

        $this->registry->registerDirective('if', $compiler);

        $directive = new DirectiveNode(
            name: 'if',
            expression: '$condition',
            children: [new TextNode('Content', 1, 10)],
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$directive]);
        $result = $this->pass->execute($ast, $this->createContext());

        $this->assertCount(3, $result->children);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
        $this->assertInstanceOf(TextNode::class, $result->children[1]);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[2]);
    }

    public function testCompilesNestedDirectives(): void
    {
        $ifCompiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node): array
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
            public function compile(Node $node): array
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

        $this->registry->registerDirective('if', $ifCompiler);
        $this->registry->registerDirective('foreach', $foreachCompiler);

        $innerDirective = new DirectiveNode(
            name: 'if',
            expression: '$x',
            children: [new TextNode('Inner', 2, 5)],
            line: 2,
            column: 4,
        );

        $outerDirective = new DirectiveNode(
            name: 'foreach',
            expression: '$items',
            children: [
                new ElementNode(
                    tag: 'div',
                    attributes: [],
                    children: [$innerDirective],
                    selfClosing: false,
                    line: 1,
                    column: 10,
                ),
            ],
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$outerDirective]);
        $result = $this->pass->execute($ast, $this->createContext());

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
        $element = new ElementNode(
            tag: 'div',
            attributes: [],
            children: [new TextNode('Content', 1, 10)],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->execute($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(ElementNode::class, $result->children[0]);
        $this->assertSame('div', $result->children[0]->tag);
    }

    public function testThrowsOnUnknownDirective(): void
    {
        $directive = new DirectiveNode(
            name: 'unknown',
            expression: '$value',
            children: [],
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$directive]);

        $this->expectException(UnknownDirectiveException::class);
        $this->expectExceptionMessage('Unknown directive "unknown"');

        $this->pass->execute($ast, $this->createContext());
    }

    public function testHandlesCompilerReturningMultipleNodes(): void
    {
        $compiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node): array
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

        $this->registry->registerDirective('custom', $compiler);

        $directive = new DirectiveNode(
            name: 'custom',
            expression: '$value',
            children: [],
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$directive]);
        $result = $this->pass->execute($ast, $this->createContext());

        $this->assertCount(5, $result->children);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[1]);
        $this->assertInstanceOf(TextNode::class, $result->children[2]);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[3]);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[4]);
    }

    public function testProcessesMultipleTopLevelDirectives(): void
    {
        $compiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node): array
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

        $this->registry->registerDirective('test', $compiler);

        $directive1 = new DirectiveNode(
            name: 'test',
            expression: 'first',
            children: [new TextNode('One', 1, 0)],
            line: 1,
            column: 0,
        );

        $directive2 = new DirectiveNode(
            name: 'test',
            expression: 'second',
            children: [new TextNode('Two', 2, 0)],
            line: 2,
            column: 0,
        );

        $ast = new DocumentNode([$directive1, $directive2]);
        $result = $this->pass->execute($ast, $this->createContext());

        $this->assertCount(4, $result->children);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]);
        $this->assertInstanceOf(TextNode::class, $result->children[1]);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[2]);
        $this->assertInstanceOf(TextNode::class, $result->children[3]);
    }

    public function testHandlesDirectiveWithinElementChildren(): void
    {
        $compiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node): array
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

        $this->registry->registerDirective('if', $compiler);

        $directive = new DirectiveNode(
            name: 'if',
            expression: '$show',
            children: [new TextNode('Visible', 2, 5)],
            line: 2,
            column: 4,
        );

        $element = new ElementNode(
            tag: 'div',
            attributes: [],
            children: [$directive],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->execute($ast, $this->createContext());

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
            public function compile(Node $node): array
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

        $this->registry->registerDirective('test', $compiler);

        $directive = new DirectiveNode(
            name: 'test',
            expression: '$x',
            children: [new TextNode('Dir', 2, 5)],
            line: 2,
            column: 0,
        );

        $element = new ElementNode(
            tag: 'section',
            attributes: [],
            children: [
                new TextNode('Before', 1, 10),
                $directive,
                new TextNode('After', 3, 10),
            ],
            selfClosing: false,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->execute($ast, $this->createContext());

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
        $element = new ElementNode(
            tag: 'input',
            attributes: [],
            children: [],
            selfClosing: true,
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$element]);
        $result = $this->pass->execute($ast, $this->createContext());

        $this->assertCount(1, $result->children);
        $inputElement = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $inputElement);
        $this->assertTrue($inputElement->selfClosing);
    }

    public function testRecursivelyProcessesCompiledNodes(): void
    {
        // Compiler that returns a node containing another directive
        $outerCompiler = new class implements DirectiveCompilerInterface {
            public function compile(Node $node): array
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
            public function compile(Node $node): array
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

        $this->registry->registerDirective('outer', $outerCompiler);
        $this->registry->registerDirective('inner', $innerCompiler);

        $directive = new DirectiveNode(
            name: 'outer',
            expression: '$x',
            children: [],
            line: 1,
            column: 0,
        );

        $ast = new DocumentNode([$directive]);
        $result = $this->pass->execute($ast, $this->createContext());

        // Should compile both outer and inner directives
        $this->assertCount(4, $result->children);
        $this->assertInstanceOf(RawPhpNode::class, $result->children[0]); // Outer start
        $this->assertInstanceOf(RawPhpNode::class, $result->children[1]); // Inner
        $this->assertInstanceOf(TextNode::class, $result->children[2]); // Nested content
        $this->assertInstanceOf(RawPhpNode::class, $result->children[3]); // Outer end
    }

    protected function createContext(
        string $source = '',
        string $templatePath = 'test.sugar.php',
        bool $debug = false,
    ): CompilationContext {
        return new CompilationContext($templatePath, $source, $debug);
    }
}
