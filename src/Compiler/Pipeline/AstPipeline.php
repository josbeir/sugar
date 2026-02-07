<?php
declare(strict_types=1);

namespace Sugar\Compiler\Pipeline;

use LogicException;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Node;
use Sugar\Context\CompilationContext;

/**
 * Executes compiler passes in a single AST traversal.
 */
final class AstPipeline
{
    /**
     * @param array<\Sugar\Compiler\Pipeline\AstPassInterface> $passes
     */
    public function __construct(
        private readonly array $passes,
    ) {
    }

    /**
     * Execute the pipeline on a document.
     */
    public function execute(DocumentNode $ast, CompilationContext $context): DocumentNode
    {
        $processed = $this->walkNode($ast, $context, null, 0, 0);

        if (count($processed) !== 1 || !($processed[0] instanceof DocumentNode)) {
            throw new LogicException('Pipeline must return a single DocumentNode.');
        }

        return $processed[0];
    }

    /**
     * @return array<\Sugar\Ast\Node>
     */
    private function walkNode(
        Node $node,
        CompilationContext $context,
        ?Node $parent,
        int $index,
        int $startPassIndex,
    ): array {
        $node->setParent($parent);
        $walkContext = new PipelineContext($context, $parent, $index);
        $passCount = count($this->passes);
        $skipChildren = false;

        for ($i = $startPassIndex; $i < $passCount; $i++) {
            $action = $this->passes[$i]->before($node, $walkContext);
            if ($action->replaceWith !== null) {
                $startIndex = $action->restartPass ? $i : $i + 1;

                return $this->walkReplacement($action->replaceWith, $context, $parent, $index, $startIndex);
            }

            if ($action->skipChildren) {
                $skipChildren = true;
            }
        }

        if (!$skipChildren) {
            $children = $this->getChildren($node);
            if ($children !== null) {
                $nodeChildren = $this->walkChildren($children, $context, $node);
                $this->setChildren($node, $nodeChildren);
            }
        }

        for ($i = $startPassIndex; $i < $passCount; $i++) {
            $action = $this->passes[$i]->after($node, $walkContext);
            if ($action->replaceWith !== null) {
                $startIndex = $action->restartPass ? $i : $i + 1;

                return $this->walkReplacement($action->replaceWith, $context, $parent, $index, $startIndex);
            }
        }

        return [$node];
    }

    /**
     * @param array<\Sugar\Ast\Node> $nodes
     * @return array<\Sugar\Ast\Node>
     */
    private function walkChildren(array $nodes, CompilationContext $context, ?Node $parent): array
    {
        $result = [];

        foreach ($nodes as $index => $child) {
            $processed = $this->walkNode($child, $context, $parent, $index, 0);
            array_push($result, ...$processed);
        }

        return $result;
    }

    /**
     * @param array<\Sugar\Ast\Node> $replacement
     * @return array<\Sugar\Ast\Node>
     */
    private function walkReplacement(
        array $replacement,
        CompilationContext $context,
        ?Node $parent,
        int $index,
        int $startPassIndex,
    ): array {
        $result = [];

        foreach ($replacement as $offset => $node) {
            $processed = $this->walkNode($node, $context, $parent, $index + $offset, $startPassIndex);
            array_push($result, ...$processed);
        }

        return $result;
    }

    /**
     * @return array<\Sugar\Ast\Node>|null
     */
    private function getChildren(Node $node): ?array
    {
        if ($node instanceof DocumentNode) {
            return $node->children;
        }

        if ($node instanceof ElementNode || $node instanceof FragmentNode) {
            return $node->children;
        }

        if ($node instanceof ComponentNode || $node instanceof DirectiveNode) {
            return $node->children;
        }

        return null;
    }

    /**
     * @param array<\Sugar\Ast\Node> $children
     */
    private function setChildren(Node $node, array $children): void
    {
        if ($node instanceof DocumentNode) {
            $node->children = $children;

            return;
        }

        if ($node instanceof ElementNode || $node instanceof FragmentNode) {
            $node->children = $children;

            return;
        }

        if ($node instanceof ComponentNode || $node instanceof DirectiveNode) {
            $node->children = $children;
        }
    }
}
