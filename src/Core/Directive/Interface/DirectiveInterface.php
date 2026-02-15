<?php
declare(strict_types=1);

namespace Sugar\Core\Directive\Interface;

use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Enum\DirectiveType;

/**
 * Interface for directive compilers
 *
 * Directive compilers transform DirectiveNodes (s:if, s:foreach, etc.)
 * into arrays of AST nodes (typically RawPhpNodes).
 */
interface DirectiveInterface
{
    /**
     * Compile a directive node into an array of AST nodes
     *
     * Overrides parent to specify DirectiveNode in docblock for clarity,
     * but accepts Node to maintain contravariance.
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node The directive node to compile
     * @param \Sugar\Core\Compiler\CompilationContext $context Compilation context for error reporting
     * @return array<\Sugar\Core\Ast\Node> The compiled AST nodes
     */
    public function compile(Node $node, CompilationContext $context): array;

    /**
     * Get the directive type classification
     *
     * Determines how this directive interacts with elements:
     * - CONTROL_FLOW: Wraps element (if, foreach, while, etc.)
     * - ATTRIBUTE: Modifies attributes (class, spread)
     * - CONTENT: Injects content (text, html)
     *
     * @return \Sugar\Core\Enum\DirectiveType The directive type
     */
    public function getType(): DirectiveType;
}
