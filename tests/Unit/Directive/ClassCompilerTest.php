<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Directive\ClassCompiler;
use Sugar\Runtime\HtmlAttributeHelper;

final class ClassCompilerTest extends TestCase
{
    private ClassCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ClassCompiler();
    }

    public function testCompilesClassDirective(): void
    {
        $node = new DirectiveNode(
            name: 'class',
            expression: "['btn', 'active' => \$isActive]",
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString(HtmlAttributeHelper::class . '::classNames', $result[0]->code);
        $this->assertStringContainsString("['btn', 'active' => \$isActive]", $result[0]->code);
    }

    public function testGeneratesClassAttribute(): void
    {
        $node = new DirectiveNode(
            name: 'class',
            expression: "['card', 'shadow']",
            children: [],
            line: 1,
            column: 0,
        );

        $result = $this->compiler->compile($node);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('class="', $result[0]->code);
        $this->assertStringContainsString('<?=', $result[0]->code);
        $this->assertStringContainsString('?>"', $result[0]->code);
    }
}
