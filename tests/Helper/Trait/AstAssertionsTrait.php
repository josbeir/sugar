<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Trait;

use Sugar\Core\Ast\DocumentNode;
use Sugar\Tests\Helper\Assertion\AstAssertionBuilder;

/**
 * Trait providing fluent AST assertions
 */
trait AstAssertionsTrait
{
    /**
     * Create a fluent AST assertion builder
     *
     * @param array<\Sugar\Core\Ast\Node>|DocumentNode $ast
     */
    protected function assertAst(array|DocumentNode $ast): AstAssertionBuilder
    {
        return new AstAssertionBuilder($ast);
    }
}
