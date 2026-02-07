<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Compiler\Pipeline;

use LogicException;
use PHPUnit\Framework\TestCase;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\TextNode;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Compiler\Pipeline\AstPipeline;
use Sugar\Compiler\Pipeline\NodeAction;
use Sugar\Compiler\Pipeline\PipelineContext;
use Sugar\Context\CompilationContext;

final class AstPipelineTest extends TestCase
{
    public function testCallsBeforeAndAfterInDepthFirstOrder(): void
    {
        $events = [];
        $pass = new class ($events) implements AstPassInterface {
            /**
             * @var array<int, string>
             */
            public array $events;

            /**
             * @param array<int, string> $events
             */
            public function __construct(array &$events)
            {
                $this->events = &$events;
            }

            public function before(Node $node, PipelineContext $context): NodeAction
            {
                $this->events[] = 'before:' . $node::class;

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                $this->events[] = 'after:' . $node::class;

                return NodeAction::none();
            }
        };

        $ast = new DocumentNode([
            new ElementNode(
                tag: 'div',
                attributes: [],
                children: [new TextNode('Hi', 1, 1)],
                selfClosing: false,
                line: 1,
                column: 1,
            ),
        ]);

        $pipeline = new AstPipeline([$pass]);
        $pipeline->execute($ast, new CompilationContext('test.sugar.php', '', false));

        $this->assertSame([
            'before:' . DocumentNode::class,
            'before:' . ElementNode::class,
            'before:' . TextNode::class,
            'after:' . TextNode::class,
            'after:' . ElementNode::class,
            'after:' . DocumentNode::class,
        ], $pass->events);
    }

    public function testReplacesNodeWithMultipleNodes(): void
    {
        $pass = new class () implements AstPassInterface {
            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof ElementNode) {
                    return NodeAction::replace([
                        new TextNode('A', 1, 1),
                        new TextNode('B', 1, 2),
                    ]);
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $ast = new DocumentNode([
            new ElementNode(
                tag: 'div',
                attributes: [],
                children: [],
                selfClosing: false,
                line: 1,
                column: 1,
            ),
        ]);

        $pipeline = new AstPipeline([$pass]);
        $result = $pipeline->execute($ast, new CompilationContext('test.sugar.php', '', false));

        $this->assertCount(2, $result->children);
        $this->assertInstanceOf(TextNode::class, $result->children[0]);
        $this->assertInstanceOf(TextNode::class, $result->children[1]);
    }

    public function testSkipsChildrenWhenRequested(): void
    {
        $events = [];
        $skipPass = new class () implements AstPassInterface {
            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof ElementNode) {
                    return NodeAction::skipChildren();
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $recordPass = new class ($events) implements AstPassInterface {
            /**
             * @var array<int, string>
             */
            public array $events;

            /**
             * @param array<int, string> $events
             */
            public function __construct(array &$events)
            {
                $this->events = &$events;
            }

            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof TextNode) {
                    $this->events[] = 'text';
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $ast = new DocumentNode([
            new ElementNode(
                tag: 'div',
                attributes: [],
                children: [new TextNode('Skip', 1, 1)],
                selfClosing: false,
                line: 1,
                column: 1,
            ),
        ]);

        $pipeline = new AstPipeline([$skipPass, $recordPass]);
        $pipeline->execute($ast, new CompilationContext('test.sugar.php', '', false));

        $this->assertSame([], $recordPass->events);
    }

    public function testRestartPassReprocessesReplacement(): void
    {
        $pass = new class () implements AstPassInterface {
            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof ElementNode && $node->tag === 'outer') {
                    return NodeAction::replace(
                        new ElementNode('inner', [], [], false, 1, 1),
                        true,
                    );
                }

                if ($node instanceof ElementNode && $node->tag === 'inner') {
                    return NodeAction::replace(new TextNode('done', 1, 1));
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $ast = new DocumentNode([
            new ElementNode(
                tag: 'outer',
                attributes: [],
                children: [],
                selfClosing: false,
                line: 1,
                column: 1,
            ),
        ]);

        $pipeline = new AstPipeline([$pass]);
        $result = $pipeline->execute($ast, new CompilationContext('test.sugar.php', '', false));

        $this->assertCount(1, $result->children);
        $this->assertInstanceOf(TextNode::class, $result->children[0]);
        $this->assertSame('done', $result->children[0]->content);
    }

    public function testTraversesComponentAndDirectiveChildren(): void
    {
        $events = [];
        $pass = new class ($events) implements AstPassInterface {
            /**
             * @var array<int, string>
             */
            public array $events;

            /**
             * @param array<int, string> $events
             */
            public function __construct(array &$events)
            {
                $this->events = &$events;
            }

            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof TextNode) {
                    $this->events[] = $node->content;
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $ast = new DocumentNode([
            new ComponentNode(
                name: 'button',
                attributes: [],
                children: [new TextNode('component', 1, 1)],
                line: 1,
                column: 1,
            ),
            new DirectiveNode(
                name: 'if',
                expression: '$flag',
                children: [new TextNode('directive', 1, 1)],
                line: 1,
                column: 1,
            ),
        ]);

        $pipeline = new AstPipeline([$pass]);
        $pipeline->execute($ast, new CompilationContext('test.sugar.php', '', false));

        $this->assertSame(['component', 'directive'], $pass->events);
    }

    public function testThrowsWhenPipelineReturnsNonDocumentNode(): void
    {
        $pass = new class () implements AstPassInterface {
            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof DocumentNode) {
                    return NodeAction::replace(new TextNode('invalid', 1, 1));
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $ast = new DocumentNode([
            new ElementNode(
                tag: 'div',
                attributes: [],
                children: [],
                selfClosing: false,
                line: 1,
                column: 1,
            ),
        ]);

        $pipeline = new AstPipeline([$pass]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Pipeline must return a single DocumentNode.');

        $pipeline->execute($ast, new CompilationContext('test.sugar.php', '', false));
    }
}
