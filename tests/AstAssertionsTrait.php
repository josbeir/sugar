<?php
declare(strict_types=1);

namespace Sugar\Tests;

use Sugar\Ast\DocumentNode;
use Sugar\Tests\Assertion\AstAssertionBuilder;

/**
 * Trait providing fluent AST assertions
 */
trait AstAssertionsTrait
{
    /**
     * Create a fluent AST assertion builder
     *
     * @param array<\Sugar\Ast\Node>|DocumentNode $ast
     */
    protected function assertAst(array|DocumentNode $ast): AstAssertionBuilder
    {
        return new AstAssertionBuilder($ast);
    }
}
