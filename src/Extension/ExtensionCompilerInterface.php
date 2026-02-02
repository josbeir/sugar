<?php
declare(strict_types=1);

namespace Sugar\Extension;

use Sugar\Ast\Node;

/**
 * Base interface for all extension compilers
 *
 * Extension compilers transform AST nodes into other AST nodes.
 * This includes directives, components, filters, functions, etc.
 */
interface ExtensionCompilerInterface
{
    /**
     * Compile an AST node into an array of AST nodes
     *
     * @param \Sugar\Ast\Node $node The node to compile
     * @return array<\Sugar\Ast\Node> The compiled AST nodes
     */
    public function compile(Node $node): array;
}
