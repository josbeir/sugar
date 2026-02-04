<?php
declare(strict_types=1);

namespace Sugar\Pass;

use Sugar\Ast\DocumentNode;
use Sugar\Context\CompilationContext;

/**
 * Interface for AST transformation passes
 *
 * Passes transform the Abstract Syntax Tree during compilation.
 * Each pass performs a specific transformation or analysis step.
 *
 * Standard passes implement this interface with a simple execute() method.
 * Some specialized passes (like TemplateInheritancePass) may require
 * additional context and use custom method signatures.
 *
 * Examples:
 * - DirectiveExtractionPass: Extracts s:* attributes into DirectiveNodes
 * - DirectiveCompilationPass: Compiles DirectiveNodes into PHP control structures
 * - ContextAnalysisPass: Assigns OutputContext to nodes for auto-escaping
 * - DirectivePairingPass: Links paired directives (if/elseif/else, forelse/empty)
 * - ComponentExpansionPass: Expands component invocations into their template content
 */
interface PassInterface
{
    /**
     * Execute the pass on an AST document
     *
     * Transforms the document and returns a new DocumentNode.
     * Implementations should maintain immutability where possible.
     *
     * @param \Sugar\Ast\DocumentNode $ast The document to process
     * @param \Sugar\Context\CompilationContext $context Compilation context with template metadata
     * @return \Sugar\Ast\DocumentNode The transformed document
     */
    public function execute(DocumentNode $ast, CompilationContext $context): DocumentNode;
}
