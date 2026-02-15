<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler\Pipeline;

use LogicException;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\CompilationContext;

/**
 * Executes compiler passes in a single AST traversal.
 */
final class AstPipeline
{
    /**
     * @var array<int, array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: int, sequence: int}>
     */
    private array $passEntries = [];

    /**
     * @var array<\Sugar\Core\Compiler\Pipeline\AstPassInterface>
     */
    private array $passes = [];

    private bool $passesReady = false;

    private int $sequence = 0;

    /**
     * @param array<\Sugar\Core\Compiler\Pipeline\AstPassInterface> $passes
     */
    public function __construct(
        array $passes = [],
    ) {
        foreach ($passes as $pass) {
            $this->addPass($pass);
        }
    }

    /**
     * Append a single compiler pass to the pipeline.
     *
     * Lower priorities run first; equal priorities preserve insertion order.
     *
     * @param \Sugar\Core\Compiler\Pipeline\AstPassInterface $pass Pass to add
     * @param int $priority Ordering priority (negative before, positive after)
     * @return $this
     */
    public function addPass(AstPassInterface $pass, int $priority = 0)
    {
        $this->passEntries[] = [
            'pass' => $pass,
            'priority' => $priority,
            'sequence' => $this->sequence,
        ];
        $this->sequence++;
        $this->passesReady = false;

        return $this;
    }

    /**
     * @return array<\Sugar\Core\Compiler\Pipeline\AstPassInterface>
     */
    private function getPasses(): array
    {
        if ($this->passesReady) {
            return $this->passes;
        }

        $entries = $this->passEntries;
        usort($entries, static function (array $left, array $right): int {
            $priority = $left['priority'] <=> $right['priority'];
            if ($priority !== 0) {
                return $priority;
            }

            return $left['sequence'] <=> $right['sequence'];
        });

        $this->passes = array_map(static fn(array $entry): AstPassInterface => $entry['pass'], $entries);
        $this->passesReady = true;

        return $this->passes;
    }

    /**
     * Execute the pipeline on a document.
     */
    public function execute(DocumentNode $ast, CompilationContext $context): DocumentNode
    {
        $this->passes = $this->getPasses();
        $processed = $this->walkNode($ast, $context, null, 0, 0);

        if (count($processed) !== 1 || !($processed[0] instanceof DocumentNode)) {
            throw new LogicException('Pipeline must return a single DocumentNode.');
        }

        return $processed[0];
    }

    /**
     * @return array<\Sugar\Core\Ast\Node>
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
     * @param array<\Sugar\Core\Ast\Node> $nodes
     * @return array<\Sugar\Core\Ast\Node>
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
     * @param array<\Sugar\Core\Ast\Node> $replacement
     * @return array<\Sugar\Core\Ast\Node>
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
     * @return array<\Sugar\Core\Ast\Node>|null
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
     * @param array<\Sugar\Core\Ast\Node> $children
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
