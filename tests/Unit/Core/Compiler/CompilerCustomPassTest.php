<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;

/**
 * Tests for custom compiler pass injection into the pipeline
 */
final class CompilerCustomPassTest extends TestCase
{
    use CompilerTestTrait;

    public function testCustomPassAtBeforeCodeGen(): void
    {
        // A pass that uppercases text nodes
        $pass = new class implements AstPassInterface {
            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof TextNode) {
                    $node->content = strtoupper($node->content);
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $this->setUpCompilerWithStringLoader(
            customPasses: [['pass' => $pass, 'priority' => 45]],
        );

        $result = $this->compiler->compile('<p>hello world</p>');

        $this->assertStringContainsString('HELLO WORLD', $result);
    }

    public function testCustomPassAtAfterCompilation(): void
    {
        // A pass that adds a data attribute to elements
        $pass = new class implements AstPassInterface {
            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof ElementNode) {
                    $node->attributes['data-processed'] = new AttributeNode(
                        'data-processed',
                        AttributeValue::static('true'),
                        0,
                        0,
                    );
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $this->setUpCompilerWithStringLoader(
            customPasses: [['pass' => $pass, 'priority' => 35]],
        );

        $result = $this->compiler->compile('<div>content</div>');

        $this->assertStringContainsString('data-processed="true"', $result);
    }

    public function testMultipleCustomPassesAtDifferentPriorities(): void
    {
        $executionOrder = [];

        $pass1 = new OrderTrackingPass($executionOrder, 'before_extraction');
        $pass2 = new OrderTrackingPass($executionOrder, 'before_code_gen');

        $this->setUpCompilerWithStringLoader(
            customPasses: [
                ['pass' => $pass1, 'priority' => 5],
                ['pass' => $pass2, 'priority' => 45],
            ],
        );

        $this->compiler->compile('hello');

        $this->assertSame(['before_extraction', 'before_code_gen'], $executionOrder);
    }

    public function testNoCustomPassesPreservesDefaultBehavior(): void
    {
        $this->setUpCompilerWithStringLoader();

        $result = $this->compiler->compile('<p s:if="$show">visible</p>');

        $this->assertStringContainsString('if ($show)', $result);
    }
}
