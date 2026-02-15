<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Compiler\Pipeline;

use LogicException;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\AstPipeline;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

final class AstPipelineTest extends TestCase
{
    use NodeBuildersTrait;
    use TemplateTestHelperTrait;

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

        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->withChild($this->text('Hi', 1, 1))
                    ->at(1, 1)
                    ->build(),
            )
            ->build();

        $pipeline = new AstPipeline([$pass]);
        $pipeline->execute($ast, $this->createContext());

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

        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->at(1, 1)
                    ->build(),
            )
            ->build();

        $pipeline = new AstPipeline([$pass]);
        $result = $pipeline->execute($ast, $this->createContext());

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

        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->withChild($this->text('Skip', 1, 1))
                    ->at(1, 1)
                    ->build(),
            )
            ->build();

        $pipeline = new AstPipeline([$skipPass, $recordPass]);
        $pipeline->execute($ast, $this->createContext());

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

        $ast = $this->document()
            ->withChild(
                $this->element('outer')
                    ->at(1, 1)
                    ->build(),
            )
            ->build();

        $pipeline = new AstPipeline([$pass]);
        $result = $pipeline->execute($ast, $this->createContext());

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

        $ast = $this->document()
            ->withChildren([
                $this->component(
                    name: 'button',
                    attributes: [],
                    children: [$this->text('component', 1, 1)],
                    line: 1,
                    column: 1,
                ),
                $this->directive('if')
                    ->expression('$flag')
                    ->withChild($this->text('directive', 1, 1))
                    ->at(1, 1)
                    ->build(),
            ])
            ->build();

        $pipeline = new AstPipeline([$pass]);
        $pipeline->execute($ast, $this->createContext());

        $this->assertSame(['component', 'directive'], $pass->events);
    }

    public function testAddPassRespectsPriorityAndInsertionOrder(): void
    {
        $events = [];
        $first = new class ($events, 'first') implements AstPassInterface {
            /**
             * @var array<int, string>
             */
            public array $events;

            /**
             * @param array<int, string> $events
             */
            public function __construct(array &$events, private string $label)
            {
                $this->events = &$events;
            }

            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof DocumentNode) {
                    $this->events[] = $this->label;
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $second = new class ($events, 'second') implements AstPassInterface {
            /**
             * @var array<int, string>
             */
            public array $events;

            /**
             * @param array<int, string> $events
             */
            public function __construct(array &$events, private string $label)
            {
                $this->events = &$events;
            }

            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof DocumentNode) {
                    $this->events[] = $this->label;
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $third = new class ($events, 'third') implements AstPassInterface {
            /**
             * @var array<int, string>
             */
            public array $events;

            /**
             * @param array<int, string> $events
             */
            public function __construct(array &$events, private string $label)
            {
                $this->events = &$events;
            }

            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof DocumentNode) {
                    $this->events[] = $this->label;
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $pipeline = new AstPipeline();
        $pipeline->addPass($first, 0);
        $pipeline->addPass($second, -10);
        $pipeline->addPass($third, 0);

        $pipeline->execute($this->document()->build(), $this->createContext());

        $this->assertSame(['second', 'first', 'third'], $events);
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

        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->at(1, 1)
                    ->build(),
            )
            ->build();

        $pipeline = new AstPipeline([$pass]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Pipeline must return a single DocumentNode.');

        $pipeline->execute($ast, $this->createContext());
    }
}
