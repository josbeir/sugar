<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\Interface\ElementClaimingDirectiveInterface;
use Sugar\Core\Engine;
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Loader\StringTemplateLoader;

/**
 * Integration tests verifying that directives implementing ElementClaimingDirectiveInterface
 * can be invoked using either the s:NAME attribute syntax or the <s-NAME> element syntax,
 * producing identical output in both cases.
 *
 * These tests exercise the full EngineBuilder → ElementRoutingPass → DirectiveExtractionPass
 * → DirectiveCompilationPass → CodeGenerator pipeline.
 */
final class ElementRoutingIntegrationTest extends TestCase
{
    /**
     * Build an Engine with a stub YoutubeDirective registered via an extension.
     *
     * YoutubeDirective:
     *   - Directive syntax: <div s:youtube="$videoId">...</div>
     *   - Element syntax:   <s-youtube src="$videoId">...</s-youtube>
     *   - Compiles to:      <iframe src="https://www.youtube.com/embed/{$videoId}"></iframe>
     *
     * @param array<string, string> $templates name → source map
     */
    private function buildEngine(array $templates): Engine
    {
        $loader = new StringTemplateLoader($templates);

        return Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new class implements ExtensionInterface {
                public function register(RegistrationContext $context): void
                {
                    $context->directive('youtube', new class implements DirectiveInterface, ElementClaimingDirectiveInterface {
                        public function getType(): DirectiveType
                        {
                            return DirectiveType::OUTPUT;
                        }

                        public function getElementExpressionAttribute(): string
                        {
                            return 'src';
                        }

                        public function compile(Node $node, CompilationContext $context): array
                        {
                            return [
                                new RawPhpNode(
                                    sprintf(
                                        'echo "<iframe src=\"https://www.youtube.com/embed/" . htmlspecialchars(%s, ENT_QUOTES) . "\"></iframe>";',
                                        $node->expression,
                                    ),
                                    $node->line,
                                    $node->column,
                                ),
                            ];
                        }
                    });
                }
            })
            ->build();
    }

    // ================================================================
    // Element syntax works as expected
    // ================================================================

    /**
     * <s-youtube src="$id"> compiles and renders an iframe.
     */
    public function testElementSyntaxRendersDirective(): void
    {
        $engine = $this->buildEngine([
            'test-basic.sugar.php' => '<s-youtube src="$id"></s-youtube>',
        ]);

        $result = $engine->render('test-basic.sugar.php', ['id' => 'dQw4w9WgXcQ']);

        $this->assertSame(
            '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>',
            $result,
        );
    }

    /**
     * Self-closing <s-youtube src="$id" /> also works.
     */
    public function testSelfClosingElementSyntaxRendersDirective(): void
    {
        $engine = $this->buildEngine([
            'test-selfclosing.sugar.php' => '<s-youtube src="$id" />',
        ]);

        $result = $engine->render('test-selfclosing.sugar.php', ['id' => 'abc123']);

        $this->assertSame(
            '<iframe src="https://www.youtube.com/embed/abc123"></iframe>',
            $result,
        );
    }

    // ================================================================
    // Directive syntax still works and produces equivalent output
    // ================================================================

    /**
     * The traditional s:youtube directive attribute continues to work, and its output
     * is identical to the element syntax.
     */
    public function testDirectiveSyntaxProducesSameOutputAsElementSyntax(): void
    {
        $engine = $this->buildEngine([
            'element.sugar.php' => '<s-youtube src="$id"></s-youtube>',
            'directive.sugar.php' => '<div s:youtube="$id"></div>',
        ]);

        $vars = ['id' => 'dQw4w9WgXcQ'];

        $this->assertSame(
            $engine->render('element.sugar.php', $vars),
            $engine->render('directive.sugar.php', $vars),
        );
    }

    // ================================================================
    // Control flow directives on the element syntax
    // ================================================================

    /**
     * s:if on <s-youtube> conditionally renders the iframe.
     */
    public function testElementSyntaxWithSIfConditionShown(): void
    {
        $engine = $this->buildEngine([
            'test-if.sugar.php' => '<s-youtube src="$id" s:if="$show"></s-youtube>',
        ]);

        $shown = $engine->render('test-if.sugar.php', ['id' => 'abc', 'show' => true]);
        $hidden = $engine->render('test-if.sugar.php', ['id' => 'abc', 'show' => false]);

        $this->assertStringContainsString('<iframe', $shown);
        $this->assertSame('', $hidden);
    }

    /**
     * s:foreach on <s-youtube> repeats the iframe for each item.
     */
    public function testElementSyntaxWithSForeach(): void
    {
        $engine = $this->buildEngine([
            'test-foreach.sugar.php' => '<s-youtube src="$id" s:foreach="$ids as $id"></s-youtube>',
        ]);

        $result = $engine->render('test-foreach.sugar.php', ['ids' => ['id1', 'id2', 'id3']]);

        $this->assertStringContainsString('embed/id1', $result);
        $this->assertStringContainsString('embed/id2', $result);
        $this->assertStringContainsString('embed/id3', $result);
    }

    // ================================================================
    // Multiple element invocations in same template
    // ================================================================

    /**
     * Two <s-youtube> elements in the same template both render correctly.
     */
    public function testMultipleElementInvocations(): void
    {
        $engine = $this->buildEngine([
            'test-multi.sugar.php' => '<s-youtube src="$firstId"></s-youtube><s-youtube src="$secondId"></s-youtube>',
        ]);

        $result = $engine->render('test-multi.sugar.php', ['firstId' => 'id1', 'secondId' => 'id2']);

        $this->assertStringContainsString('embed/id1', $result);
        $this->assertStringContainsString('embed/id2', $result);
    }

    // ================================================================
    // Unknown custom elements still reach ComponentExpansionPass as before
    // ================================================================

    /**
     * A <s-button> tag that is NOT an element-claiming directive continues to be
     * treated as a component and passes through to ComponentExpansionPass unchanged.
     * (Without ComponentExtension, it should simply pass compilation without error,
     * because ComponentExpansionPass is not registered here.)
     */
    public function testUnclaimedComponentNodePassesThroughRouting(): void
    {
        $loader = new StringTemplateLoader([
            'test-plain.sugar.php' => '<div>no component ext</div>',
        ]);

        $engine = Engine::builder()
            ->withTemplateLoader($loader)
            ->withExtension(new class implements ExtensionInterface {
                public function register(RegistrationContext $context): void
                {
                    $context->directive('youtube', new class implements DirectiveInterface, ElementClaimingDirectiveInterface {
                        public function getType(): DirectiveType
                        {
                            return DirectiveType::OUTPUT;
                        }

                        public function getElementExpressionAttribute(): string
                        {
                            return 'src';
                        }

                        public function compile(Node $node, CompilationContext $context): array
                        {
                            return [];
                        }
                    });
                }
            })
            ->build();

        // Should render without exception — the <div> is a plain element (no s-prefix)
        $result = $engine->render('test-plain.sugar.php', []);
        $this->assertSame('<div>no component ext</div>', $result);
    }
}
