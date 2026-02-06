<?php
declare(strict_types=1);

namespace Sugar\Pass\Directive;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Interface\SiblingNavigationInterface;
use Sugar\Ast\Node;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\PairedDirectiveCompilerInterface;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Pass\PassInterface;

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
final class DirectivePairingPass implements PassInterface
{
    /**
     * Constructor
     *
     * @param \Sugar\Extension\DirectiveRegistry $registry Extension registry with directive compilers
     */
    public function __construct(
        private readonly DirectiveRegistry $registry,
    ) {
    }

    /**
     * Execute the pass: wire parent references and pair sibling directives
     *
     * @param \Sugar\Ast\DocumentNode $ast Document to transform
     * @return \Sugar\Ast\DocumentNode Same document with paired directives linked
     */
    public function execute(DocumentNode $ast, CompilationContext $context): DocumentNode
    {
        // First pass: wire up all parent references
        $this->wireParents($ast, null);

        // Second pass: pair sibling directives
        $this->pairSiblingDirectives($ast);

        return $ast;
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
        if (!$this->registry->has($node->name)) {
            return;
        }

        $compiler = $this->registry->get($node->name);

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
