<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use LogicException;
use PHPUnit\Framework\TestCase;
use Sugar\Ast\DirectiveNode;
use Sugar\Directive\PassThroughDirective;
use Sugar\Enum\DirectiveType;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

final class PassThroughDirectiveTest extends TestCase
{
    use TemplateTestHelperTrait;

    public function testGetTypeReturnsPassThrough(): void
    {
        $compiler = new PassThroughDirective();

        $this->assertSame(DirectiveType::PASS_THROUGH, $compiler->getType());
    }

    public function testCompileThrowsLogicException(): void
    {
        $compiler = new PassThroughDirective();
        $context = $this->createContext('<div></div>');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Pass-through directives should not be compiled');

        $compiler->compile(new DirectiveNode('slot', 'true', [], 1, 0), $context);
    }
}
