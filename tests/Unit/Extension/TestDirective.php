<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension;

use Sugar\Ast\Node;
use Sugar\Compiler\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Enum\DirectiveType;

/**
 * Test compiler for testing class-name registration
 */
final class TestDirective implements DirectiveInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        return [];
    }

    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }
}
