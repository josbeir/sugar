<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Directive\SpreadCompiler;

final class SpreadCompilerTest extends TestCase
{
    private SpreadCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new SpreadCompiler();
    }

    public function testCompilesSpreadDirective(): void
    {
        $node = new DirectiveNode(
            name: 'spread',
            expression: '$attrs',
            children: [],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('\\Sugar\\Runtime\\AttributeHelper::spreadAttrs', $result[0]->code);
        $this->assertStringContainsString('$attrs', $result[0]->code);
    }

    public function testGeneratesPhpOutput(): void
    {
        $node = new DirectiveNode(
            name: 'spread',
            expression: '$attributes',
            children: [],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertStringContainsString('<?=', $result[0]->code);
        $this->assertStringContainsString('?>', $result[0]->code);
    }

    public function testHandlesComplexExpressions(): void
    {
        $node = new DirectiveNode(
            name: 'spread',
            expression: 'array_merge($baseAttrs, $customAttrs)',
            children: [],
            elseChildren: null,
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertStringContainsString('array_merge($baseAttrs, $customAttrs)', $result[0]->code);
    }
}
