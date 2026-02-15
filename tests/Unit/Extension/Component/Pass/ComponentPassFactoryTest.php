<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Component\Pass;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\AstPipeline;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Parser\Parser;
use Sugar\Extension\Component\Loader\StringComponentTemplateLoader;
use Sugar\Extension\Component\Pass\ComponentPassFactory;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Tests for ComponentPassFactory.
 */
final class ComponentPassFactoryTest extends TestCase
{
    use TemplateTestHelperTrait;

    public function testCreateExpansionPassReturnsCachedInstance(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(
            config: $config,
        );
        $componentLoader = new StringComponentTemplateLoader(
            config: $config,
            components: ['plain' => '<div>hello</div>'],
        );
        $parser = new Parser($config);
        $registry = new DirectiveRegistry();

        $factory = new ComponentPassFactory(
            templateLoader: $loader,
            componentLoader: $componentLoader,
            parser: $parser,
            registry: $registry,
            config: $config,
        );

        $first = $factory->createExpansionPass();
        $second = $factory->createExpansionPass();

        $this->assertSame($first, $second);
    }

    public function testIncludesInRangeCustomPassesInComponentTemplatePipeline(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(
            config: $config,
        );
        $componentLoader = new StringComponentTemplateLoader(
            config: $config,
            components: ['plain' => '<div>hello</div>'],
        );
        $parser = new Parser($config);
        $registry = new DirectiveRegistry();

        $upperCasePass = new class implements AstPassInterface {
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

        $factory = new ComponentPassFactory(
            templateLoader: $loader,
            componentLoader: $componentLoader,
            parser: $parser,
            registry: $registry,
            config: $config,
            customPasses: [
                ['pass' => $upperCasePass, 'priority' => 35],
            ],
        );

        $pass = $factory->createExpansionPass();
        $pipeline = new AstPipeline([$pass]);

        $ast = $parser->parse('<s-plain></s-plain>');
        $result = $pipeline->execute($ast, new CompilationContext('test', '<s-plain></s-plain>'));

        $this->assertStringContainsString('HELLO', $this->astToString($result));
    }

    public function testExcludesOutOfRangeCustomPassesFromComponentTemplatePipeline(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(
            config: $config,
        );
        $componentLoader = new StringComponentTemplateLoader(
            config: $config,
            components: ['plain' => '<div>hello</div>'],
        );
        $parser = new Parser($config);
        $registry = new DirectiveRegistry();

        $appendPass = new class implements AstPassInterface {
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

        $factory = new ComponentPassFactory(
            templateLoader: $loader,
            componentLoader: $componentLoader,
            parser: $parser,
            registry: $registry,
            config: $config,
            customPasses: [
                ['pass' => $appendPass, 'priority' => 20],
                ['pass' => $appendPass, 'priority' => 40],
            ],
        );

        $pass = $factory->createExpansionPass();
        $pipeline = new AstPipeline([$pass]);

        $ast = $parser->parse('<s-plain></s-plain>');
        $result = $pipeline->execute($ast, new CompilationContext('test', '<s-plain></s-plain>'));

        $this->assertStringContainsString('hello', $this->astToString($result));
        $this->assertStringNotContainsString('helloX', $this->astToString($result));
    }

    private function astToString(DocumentNode $ast): string
    {
        $output = '';
        foreach ($ast->children as $child) {
            if ($child instanceof TextNode) {
                $output .= $child->content;
                continue;
            }

            if ($child instanceof ElementNode) {
                foreach ($child->children as $nested) {
                    if ($nested instanceof TextNode) {
                        $output .= $nested->content;
                    }
                }
            }
        }

        return $output;
    }
}
