<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\Vite\Directive;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Extension\Vite\Directive\ViteDirective;
use Sugar\Extension\Vite\Runtime\ViteAssetResolver;

/**
 * Tests ViteDirective compilation behavior.
 */
final class ViteDirectiveTest extends TestCase
{
    /**
     * Verify empty directive children compile to a single raw OutputNode.
     */
    public function testCompilesToRawOutputNodeWhenNoChildrenExist(): void
    {
        $directive = new ViteDirective();
        $node = new DirectiveNode(
            name: 'vite',
            expression: "'resources/js/app.ts'",
            children: [],
            line: 1,
            column: 1,
        );

        $compiled = $directive->compile($node, $this->createContext());

        $this->assertCount(1, $compiled);
        $this->assertInstanceOf(OutputNode::class, $compiled[0]);

        $outputNode = $compiled[0];
        $this->assertFalse($outputNode->escape);
        $this->assertSame(OutputContext::RAW, $outputNode->context);
        $this->assertStringContainsString(ViteAssetResolver::class, $outputNode->expression);
        $this->assertStringContainsString('resources/js/app.ts', $outputNode->expression);
    }

    /**
     * Verify wrapped element children are replaced with vite output.
     */
    public function testReplacesWrappedElementChildren(): void
    {
        $directive = new ViteDirective();
        $wrapped = new ElementNode(
            tag: 'div',
            attributes: [],
            children: [],
            selfClosing: false,
            line: 2,
            column: 1,
        );
        $node = new DirectiveNode(
            name: 'vite',
            expression: "'resources/js/app.ts'",
            children: [$wrapped],
            line: 2,
            column: 1,
        );

        $compiled = $directive->compile($node, $this->createContext());

        $this->assertCount(1, $compiled);
        $this->assertInstanceOf(ElementNode::class, $compiled[0]);
        $this->assertCount(1, $compiled[0]->children);
        $this->assertInstanceOf(OutputNode::class, $compiled[0]->children[0]);
    }

    /**
     * Verify directive type classification is CONTENT.
     */
    public function testDirectiveTypeIsContent(): void
    {
        $directive = new ViteDirective();

        $this->assertSame(DirectiveType::OUTPUT, $directive->getType());
    }

    /**
     * Verify directive opts out of content element wrapping.
     */
    public function testDoesNotWrapContentElement(): void
    {
        $directive = new ViteDirective();

        $this->assertFalse($directive->shouldWrapContentElement());
    }

    /**
     * Verify bare path attribute values are treated as string literals.
     */
    public function testNormalizesBarePathExpressionToStringLiteral(): void
    {
        $directive = new ViteDirective();
        $node = new DirectiveNode(
            name: 'vite',
            expression: 'resources/scss/site.scss',
            children: [],
            line: 3,
            column: 1,
        );

        $compiled = $directive->compile($node, $this->createContext());

        $this->assertCount(1, $compiled);
        $this->assertInstanceOf(OutputNode::class, $compiled[0]);
        $this->assertStringContainsString("->render('resources/scss/site.scss')", $compiled[0]->expression);
    }

    /**
     * Create a compilation context fixture.
     */
    private function createContext(): CompilationContext
    {
        return new CompilationContext(
            templatePath: 'memory-template',
            source: '<s-template s:vite="\'resources/js/app.ts\'" />',
            debug: true,
        );
    }
}
