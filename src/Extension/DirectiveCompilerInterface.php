<?php
declare(strict_types=1);

namespace Sugar\Extension;

use Sugar\Ast\Node;
use Sugar\Context\CompilationContext;
use Sugar\Enum\DirectiveType;

/**
 * Interface for directive compilers
 *
 * Directive compilers transform DirectiveNodes (s:if, s:foreach, etc.)
 * into arrays of AST nodes (typically RawPhpNodes).
 */
interface DirectiveCompilerInterface
{
    /**
     * Compile a directive node into an array of AST nodes
     *
     * Overrides parent to specify DirectiveNode in docblock for clarity,
     * but accepts Node to maintain contravariance.
     *
     * @param \Sugar\Ast\DirectiveNode $node The directive node to compile
     * @param \Sugar\Context\CompilationContext $context Compilation context for error reporting
     * @return array<\Sugar\Ast\Node> The compiled AST nodes
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
     * @return \Sugar\Enum\DirectiveType The directive type
     */
    public function getType(): DirectiveType;
}
