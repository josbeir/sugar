<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use LogicException;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Directive\NoWrapDirective;
use Sugar\Core\Enum\DirectiveType;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

final class NoWrapDirectiveTest extends TestCase
{
    use TemplateTestHelperTrait;

    public function testDoesNotWrapContent(): void
    {
        $directive = new NoWrapDirective();

        $this->assertFalse($directive->shouldWrapContentElement());
    }

    public function testReturnsPassThroughType(): void
    {
        $directive = new NoWrapDirective();

        $this->assertSame(DirectiveType::PASS_THROUGH, $directive->getType());
    }

    public function testCompileThrowsLogicException(): void
    {
        $directive = new NoWrapDirective();
        $node = new DirectiveNode('nowrap', '', [], 1, 1);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('s:nowrap should be handled during directive extraction.');

        $directive->compile($node, $this->createContext());
    }
}
