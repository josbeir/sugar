<?php
declare(strict_types=1);

namespace Sugar\Pass\Directive;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\Node;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Compiler\Pipeline\NodeAction;
use Sugar\Compiler\Pipeline\PipelineContext;
use Sugar\Exception\SyntaxException;
use Sugar\Exception\UnknownDirectiveException;
use Sugar\Extension\DirectiveRegistryInterface;

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
     * @param \Sugar\Extension\DirectiveRegistryInterface $registry Extension registry with directive compilers
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
            throw $context->compilation->createException(
                SyntaxException::class,
                $unknownDirectiveException->getMessage(),
                $node->line,
                $node->column,
            );
        }

        $compiledNodes = $compiler->compile($node, $context->compilation);

        return NodeAction::replace($compiledNodes, true);
    }
}
