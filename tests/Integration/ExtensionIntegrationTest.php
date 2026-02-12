<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Compiler\CompilationContext;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Compiler\Pipeline\NodeAction;
use Sugar\Compiler\Pipeline\PipelineContext;
use Sugar\Config\SugarConfig;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Engine;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\ExtensionInterface;
use Sugar\Extension\RegistrationContext;
use Sugar\Loader\StringTemplateLoader;

/**
 * Integration tests for the extension system
 *
 * Verifies end-to-end registration and execution of extensions
 * via EngineBuilder, including custom directives and compiler passes.
 */
final class ExtensionIntegrationTest extends TestCase
{
    public function testExtensionWithCustomDirective(): void
    {
        $extension = new class implements ExtensionInterface {
            public function register(RegistrationContext $context): void
            {
                $context->directive('repeat', new class implements DirectiveInterface {
                    /**
                     * @param \Sugar\Ast\DirectiveNode $node
                     * @return array<\Sugar\Ast\Node>
                     */
                    public function compile(Node $node, CompilationContext $context): array
                    {
                        $count = $node->expression;
                        $children = $node->children;

                        return [
                            new RawPhpNode(sprintf('for ($__i = 0; $__i < %s; $__i++):', $count), $node->line, $node->column),
                            ...$children,
                            new RawPhpNode('endfor;', $node->line, $node->column),
                        ];
                    }

                    public function getType(): DirectiveType
                    {
                        return DirectiveType::CONTROL_FLOW;
                    }
                });
            }
        };

        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'repeat' => '<span s:repeat="3">Hi </span>',
            ],
        );

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension($extension)
            ->build();

        $result = $engine->render('repeat');

        // 'Hi ' should appear 3 times
        $this->assertSame(3, substr_count($result, '<span>Hi </span>'));
    }

    public function testExtensionWithCustomCompilerPass(): void
    {
        $extension = new class implements ExtensionInterface {
            public function register(RegistrationContext $context): void
            {
                // A pass that uppercases all text content
                $context->compilerPass(new class implements AstPassInterface {
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
                }, 45);
            }
        };

        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'upper' => '<p>hello world</p>',
            ],
        );

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension($extension)
            ->build();

        $result = $engine->render('upper');

        $this->assertStringContainsString('HELLO WORLD', $result);
    }

    public function testExtensionWithDirectiveAndPass(): void
    {
        $extension = new class implements ExtensionInterface {
            public function register(RegistrationContext $context): void
            {
                // Register a simple control-flow directive
                $context->directive('repeat', new class implements DirectiveInterface {
                    /**
                     * @param \Sugar\Ast\DirectiveNode $node
                     * @return array<\Sugar\Ast\Node>
                     */
                    public function compile(Node $node, CompilationContext $context): array
                    {
                        $count = $node->expression;
                        $children = $node->children;

                        return [
                            new RawPhpNode(sprintf('for ($__i = 0; $__i < %s; $__i++):', $count), $node->line, $node->column),
                            ...$children,
                            new RawPhpNode('endfor;', $node->line, $node->column),
                        ];
                    }

                    public function getType(): DirectiveType
                    {
                        return DirectiveType::CONTROL_FLOW;
                    }
                });

                // Register a pass that uppercases text nodes
                $context->compilerPass(new class implements AstPassInterface {
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
                }, 45);
            }
        };

        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'combined' => '<div s:repeat="2">item</div>',
            ],
        );

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension($extension)
            ->build();

        $result = $engine->render('combined');

        // Should include the uppercased text from the pass
        $this->assertStringContainsString('ITEM', $result);
        // Should repeat 2 times
        $this->assertSame(2, substr_count($result, '<div>ITEM</div>'));
    }

    public function testMultipleExtensions(): void
    {
        $ext1 = new class implements ExtensionInterface {
            public function register(RegistrationContext $context): void
            {
                $context->compilerPass(new class implements AstPassInterface {
                    public function before(Node $node, PipelineContext $context): NodeAction
                    {
                        if ($node instanceof TextNode && str_contains($node->content, '[UPPER]')) {
                            $node->content = str_replace('[UPPER]', '', $node->content);
                            $node->content = strtoupper($node->content);
                        }

                        return NodeAction::none();
                    }

                    public function after(Node $node, PipelineContext $context): NodeAction
                    {
                        return NodeAction::none();
                    }
                }, 45);
            }
        };

        $ext2 = new class implements ExtensionInterface {
            public function register(RegistrationContext $context): void
            {
                $context->compilerPass(new class implements AstPassInterface {
                    public function before(Node $node, PipelineContext $context): NodeAction
                    {
                        if ($node instanceof TextNode && str_contains($node->content, '[TRIM]')) {
                            $node->content = str_replace('[TRIM]', '', $node->content);
                            $node->content = trim($node->content);
                        }

                        return NodeAction::none();
                    }

                    public function after(Node $node, PipelineContext $context): NodeAction
                    {
                        return NodeAction::none();
                    }
                }, 45);
            }
        };

        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'multi' => '<p>[UPPER]hello</p><span>[TRIM]  spaced  </span>',
            ],
        );

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension($ext1)
            ->withExtension($ext2)
            ->build();

        $result = $engine->render('multi');

        $this->assertStringContainsString('HELLO', $result);
        $this->assertStringContainsString('<span>spaced</span>', $result);
    }

    public function testEngineWithoutExtensionsStillWorks(): void
    {
        $loader = new StringTemplateLoader(
            config: new SugarConfig(),
            templates: [
                'no-ext-baseline' => '<div s:if="$show">Visible</div>',
            ],
        );

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->build();

        $result = $engine->render('no-ext-baseline', ['show' => true]);
        $this->assertStringContainsString('Visible', $result);
    }
}
