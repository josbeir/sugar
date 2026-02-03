<?php
declare(strict_types=1);

namespace Sugar\Pass\Directive;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Helper\NodeCloner;
use Sugar\Ast\Node;
use Sugar\Extension\ExtensionRegistry;

/**
 * Compiles DirectiveNodes into PHP control structures using registered compilers
 *
 * This pass walks the AST looking for DirectiveNode instances and uses
 * the ExtensionRegistry to find the appropriate compiler for each directive.
 * The compiler transforms the DirectiveNode into an array of nodes
 * (typically RawPhpNodes).
 *
 * This pass does NOT extract directives - that's handled by DirectiveExtractionPass.
 */
final readonly class DirectiveCompilationPass
{
    /**
     * Constructor
     *
     * @param \Sugar\Extension\ExtensionRegistry $registry Extension registry with directive compilers
     */
    public function __construct(
        private ExtensionRegistry $registry,
    ) {
    }

    /**
     * Transform AST by compiling DirectiveNodes
     */
    public function transform(DocumentNode $ast): DocumentNode
    {
        $newChildren = [];

        foreach ($ast->children as $node) {
            $compiled = $this->compileNode($node);
            array_push($newChildren, ...is_array($compiled) ? $compiled : [$compiled]);
        }

        return new DocumentNode($newChildren);
    }

    /**
     * Compile a single node (recursively)
     *
     * @return \Sugar\Ast\Node|array<\Sugar\Ast\Node>
     */
    private function compileNode(Node $node): Node|array
    {
        if ($node instanceof DirectiveNode) {
            // Skip directives that have been consumed by pairing
            if ($node->isConsumedByPairing()) {
                return [];
            }

            // Get compiler for this directive
            $compiler = $this->registry->getDirective($node->name);

            // Compile the directive - this returns an array of nodes
            $compiledNodes = $compiler->compile($node);

            // Recursively process the compiled nodes to handle nested directives
            $processedNodes = [];
            foreach ($compiledNodes as $compiledNode) {
                $processed = $this->compileNode($compiledNode);
                if (is_array($processed)) {
                    array_push($processedNodes, ...$processed);
                } else {
                    $processedNodes[] = $processed;
                }
            }

            return $processedNodes;
        }

        // Transform children recursively
        return $this->transformNode($node);
    }

    /**
     * Transform a node and its children recursively
     */
    private function transformNode(Node $node): Node
    {
        // Handle elements with children
        if ($node instanceof ElementNode) {
            $newChildren = [];
            foreach ($node->children as $child) {
                $compiled = $this->compileNode($child);
                array_push($newChildren, ...is_array($compiled) ? $compiled : [$compiled]);
            }

            return NodeCloner::withChildren($node, $newChildren);
        }

        // All other nodes pass through unchanged
        return $node;
    }
}
