<?php
declare(strict_types=1);

namespace Sugar\Core\Pass\Directive;

use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Interface\SiblingNavigationInterface;
use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Directive\Interface\PairedDirectiveInterface;
use Sugar\Core\Extension\DirectiveRegistryInterface;

/**
 * Directive Pairing Pass
 *
 * Pairs sibling directives (e.g., s:forelse with s:empty, s:switch with s:case).
 *
 * This pass runs after DirectiveExtractionPass so it can pair DirectiveNodes
 * regardless of intervening text nodes, comments, or whitespace.
 */
final class DirectivePairingPass implements AstPassInterface
{
    /**
     * Constructor
     *
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface $registry Extension registry with directive compilers
     */
    public function __construct(
        private readonly DirectiveRegistryInterface $registry,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function before(Node $node, PipelineContext $context): NodeAction
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
    public function after(Node $node, PipelineContext $context): NodeAction
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

        if (!($compiler instanceof PairedDirectiveInterface)) {
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
     * @return array<\Sugar\Core\Ast\Node>|null
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
