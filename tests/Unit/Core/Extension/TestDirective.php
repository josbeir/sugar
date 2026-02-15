<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Extension;

use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Enum\DirectiveType;

/**
 * Test compiler for testing class-name registration
 */
final class TestDirective implements DirectiveInterface
{
    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
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
