<?php
declare(strict_types=1);

namespace Sugar\Core\Pass\Directive;

use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Exception\UnknownDirectiveException;
use Sugar\Core\Extension\DirectiveRegistryInterface;

/**
 * Compiles DirectiveNodes into PHP control structures using registered compilers
 *
 * This pass participates in the shared traversal and compiles DirectiveNode instances using
 * the ExtensionRegistry to find the appropriate compiler for each directive.
 * The compiler transforms the DirectiveNode into an array of nodes
 * (typically RawPhpNodes).
 *
 * This pass does NOT extract directives - that's handled by DirectiveExtractionPass.
 */
final class DirectiveCompilationPass implements AstPassInterface
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
        return NodeAction::none();
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, PipelineContext $context): NodeAction
    {
        if (!($node instanceof DirectiveNode)) {
            return NodeAction::none();
        }

        if ($node->isConsumedByPairing()) {
            return NodeAction::replace([]);
        }

        try {
            $compiler = $this->registry->get($node->name);
        } catch (UnknownDirectiveException $unknownDirectiveException) {
            throw $context->compilation->createSyntaxExceptionForNode(
                $unknownDirectiveException->getMessage(),
                $node,
            );
        }

        $compiledNodes = $compiler->compile($node, $context->compilation);

        return NodeAction::replace($compiledNodes, true);
    }
}
