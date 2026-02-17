<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Compiler\Pipeline;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\CompilerPipelineFactory;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Enum\PassPriority;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Parser\Parser;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

final class CompilerPipelineFactoryTest extends TestCase
{
    use NodeBuildersTrait;
    use TemplateTestHelperTrait;

    public function testCompilerPipelineAppliesCustomPasses(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader();
        $parser = new Parser($config);
        $registry = new DirectiveRegistry();

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

        $factory = new CompilerPipelineFactory(
            $loader,
            $parser,
            $registry,
            $config,
            [
                ['pass' => $pass, 'priority' => PassPriority::POST_DIRECTIVE_COMPILATION],
            ],
        );

        $pipeline = $factory->buildCompilerPipeline(enableInheritance: false);
        $ast = $this->document()->withChild($this->text('hello', 1, 1))->build();
        $result = $pipeline->execute($ast, $this->createContext());

        $this->assertInstanceOf(TextNode::class, $result->children[0]);
        $this->assertSame('HELLO', $result->children[0]->content);
    }

    public function testCompilerPipelineAppliesAllCustomPassesByPriority(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader();
        $parser = new Parser($config);
        $registry = new DirectiveRegistry();

        $inRange = new class implements AstPassInterface {
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

        $afterPass = new class implements AstPassInterface {
            public function before(Node $node, PipelineContext $context): NodeAction
            {
                if ($node instanceof TextNode) {
                    $node->content .= 'X';
                }

                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };

        $factory = new CompilerPipelineFactory(
            $loader,
            $parser,
            $registry,
            $config,
            [
                ['pass' => $inRange, 'priority' => PassPriority::POST_DIRECTIVE_COMPILATION],
                ['pass' => $afterPass, 'priority' => PassPriority::CONTEXT_ANALYSIS],
            ],
        );

        $pipeline = $factory->buildCompilerPipeline(enableInheritance: false);
        $ast = $this->document()->withChild($this->text('hello', 1, 1))->build();
        $result = $pipeline->execute($ast, $this->createContext());

        $this->assertInstanceOf(TextNode::class, $result->children[0]);
        $this->assertSame('HELLOX', $result->children[0]->content);
    }
}
