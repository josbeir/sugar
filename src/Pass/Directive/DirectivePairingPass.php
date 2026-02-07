<?php
declare(strict_types=1);

namespace Sugar\Pass\Directive;

use Sugar\Ast\ComponentNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Interface\SiblingNavigationInterface;
use Sugar\Ast\Node;
use Sugar\Directive\Interface\PairedDirectiveCompilerInterface;
use Sugar\Extension\DirectiveRegistryInterface;
use Sugar\Pass\Middleware\AstMiddlewarePassInterface;
use Sugar\Pass\Middleware\NodeAction;
use Sugar\Pass\Middleware\WalkContext;

/**
 * Directive Pairing Pass
 *
 * Pairs sibling directives (e.g., s:forelse with s:empty, s:switch with s:case).
 *
 * This pass runs after DirectiveExtractionPass so it can pair DirectiveNodes
 * regardless of intervening text nodes, comments, or whitespace.
 */
final class DirectivePairingPass implements AstMiddlewarePassInterface
{
    /**
     * Constructor
     *
     * @param \Sugar\Extension\DirectiveRegistryInterface $registry Extension registry with directive compilers
     */
    public function __construct(
        private readonly DirectiveRegistryInterface $registry,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function before(Node $node, WalkContext $context): NodeAction
    {
        $children = $this->getChildren($node);
        if ($children === null) {
            return NodeAction::none();
        }

        if (!($node instanceof SiblingNavigationInterface)) {
            return NodeAction::none();
        }

        foreach ($children as $child) {
            if ($child instanceof DirectiveNode) {
                $this->pairDirective($node, $child);
            }
        }

        return NodeAction::none();
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, WalkContext $context): NodeAction
    {
        return NodeAction::none();
    }

    /**
     * Pair a directive with its sibling if it's a paired directive type
     */
    private function pairDirective(SiblingNavigationInterface $parent, DirectiveNode $node): void
    {
        if (!$this->registry->has($node->name)) {
            return;
        }

        $compiler = $this->registry->get($node->name);

        if (!($compiler instanceof PairedDirectiveCompilerInterface)) {
            return;
        }

        $pairName = $compiler->getPairingDirective();
        // Find next sibling with matching directive name
        $paired = $parent->findNextSibling(
            $node,
            fn($n): bool => $n instanceof DirectiveNode && $n->name === $pairName,
        );

        if ($paired instanceof DirectiveNode) {
            $node->setPairedSibling($paired);
            $paired->markConsumedByPairing();
        }
    }

    /**
     * @return array<\Sugar\Ast\Node>|null
     */
    private function getChildren(Node $node): ?array
    {
        if ($node instanceof DocumentNode || $node instanceof ElementNode) {
            return $node->children;
        }

        if ($node instanceof DirectiveNode || $node instanceof ComponentNode) {
            return $node->children;
        }

        return null;
    }
}
