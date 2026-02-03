<?php
declare(strict_types=1);

namespace Sugar\Pass\Directive;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Interface\SiblingNavigationInterface;
use Sugar\Ast\Node;
use Sugar\Extension\ExtensionRegistry;
use Sugar\Extension\PairedDirectiveCompilerInterface;

/**
 * Directive Pairing Pass
 *
 * Wires up parent references in the AST and pairs sibling directives
 * (e.g., s:forelse with s:empty, s:switch with s:case).
 *
 * This pass must run before DirectiveExtractionPass to enable robust
 * sibling-based directive pairing that works regardless of intervening
 * text nodes, comments, or whitespace.
 */
final class DirectivePairingPass
{
    /**
     * Constructor
     *
     * @param \Sugar\Extension\ExtensionRegistry $registry Extension registry with directive compilers
     */
    public function __construct(
        private readonly ExtensionRegistry $registry,
    ) {
    }

    /**
     * Transform AST: wire parents and pair sibling directives
     */
    public function transform(DocumentNode $node): DocumentNode
    {
        // First pass: wire up all parent references
        $this->wireParents($node, null);

        // Second pass: pair sibling directives
        $this->pairSiblingDirectives($node);

        return $node;
    }

    /**
     * Recursively wire parent references throughout the tree
     */
    private function wireParents(Node $node, ?Node $parent): void
    {
        $node->setParent($parent);

        // Process children array
        if ($node instanceof DocumentNode || $node instanceof ElementNode) {
            foreach ($node->children as $child) {
                $this->wireParents($child, $node);
            }
        }

        // Process DirectiveNode children
        if ($node instanceof DirectiveNode) {
            foreach ($node->children as $child) {
                $this->wireParents($child, $node);
            }
        }
    }

    /**
     * Find and pair sibling directives
     */
    private function pairSiblingDirectives(Node $node): void
    {
        if ($node instanceof DirectiveNode) {
            $this->pairDirective($node);
        }

        // Recurse to children
        if ($node instanceof DocumentNode || $node instanceof ElementNode) {
            foreach ($node->children as $child) {
                $this->pairSiblingDirectives($child);
            }
        }

        if ($node instanceof DirectiveNode) {
            foreach ($node->children as $child) {
                $this->pairSiblingDirectives($child);
            }
        }
    }

    /**
     * Pair a directive with its sibling if it's a paired directive type
     */
    private function pairDirective(DirectiveNode $node): void
    {
        if (!$this->registry->hasDirective($node->name)) {
            return;
        }

        $compiler = $this->registry->getDirective($node->name);

        if (!($compiler instanceof PairedDirectiveCompilerInterface)) {
            return;
        }

        $pairName = $compiler->getPairingDirective();
        $parent = $node->getParent();

        // Parent must support sibling navigation to enable pairing
        if (!($parent instanceof SiblingNavigationInterface)) {
            return;
        }

        // Find next sibling with matching directive name
        $paired = $parent->findNextSibling(
            $node,
            fn($n): bool => $n instanceof DirectiveNode && $n->name === $pairName,
        );

        if ($paired instanceof Node) {
            assert($paired instanceof DirectiveNode);
            $node->setPairedSibling($paired);
            $paired->markConsumedByPairing();
        }
    }
}
