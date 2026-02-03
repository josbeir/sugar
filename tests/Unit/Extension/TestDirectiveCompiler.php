<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension;

use Sugar\Ast\Node;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\DirectiveCompilerInterface;

/**
 * Test compiler for testing class-name registration
 */
final class TestDirectiveCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node): array
    {
        return [];
    }

    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }
}
