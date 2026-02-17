<?php
declare(strict_types=1);

namespace Sugar\Core\Template;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Helper\NodeCloner;
use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Enum\BlockMergeMode;

/**
 * Handles extraction and merge semantics for template blocks.
 */
final class BlockMerger
{
    /**
     * @param \Sugar\Core\Config\Helper\DirectivePrefixHelper $prefixHelper Directive prefix helper
     */
    public function __construct(private readonly DirectivePrefixHelper $prefixHelper)
    {
    }

    /**
     * Extract only the requested blocks in template order.
     *
     * @param array<string> $blockNames
     */
    public function extractBlocks(
        DocumentNode $document,
        array $blockNames,
        CompilationContext $context,
    ): DocumentNode {
        $targets = array_fill_keys($blockNames, true);
        $children = [];

        $this->collectBlockChildren($document->children, $targets, $children, $context);

        return new DocumentNode($children);
    }

    /**
     * Collect s:block definitions from a document tree.
     *
     * @return array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Enum\BlockMergeMode, attributeName: string}>
     */
    public function collectBlocks(DocumentNode $document, CompilationContext $context): array
    {
        $blocks = [];

        foreach ($document->children as $child) {
            if ($child instanceof ElementNode || $child instanceof FragmentNode) {
                $blockDirective = $this->getBlockDirective($child, $context);

                if ($blockDirective !== null) {
                    $blocks[$blockDirective['name']] = [
                        'node' => $child,
                        'mode' => $blockDirective['mode'],
                        'attributeName' => $blockDirective['attributeName'],
                    ];
                }

                $blocks = [...$blocks, ...$this->collectBlocksFromChildren($child->children, $context)];
            }
        }

        return $blocks;
    }

    /**
     * Replace parent blocks using child block map.
     *
     * @param array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Enum\BlockMergeMode, attributeName: string}> $childBlocks
     */
    public function replaceBlocks(DocumentNode $document, array $childBlocks): DocumentNode
    {
        $newChildren = [];

        foreach ($document->children as $child) {
            $newChildren[] = $this->replaceBlocksInNode($child, $childBlocks);
        }

        return new DocumentNode($newChildren);
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $nodes
     * @param array<string, bool> $targets
     * @param array<\Sugar\Core\Ast\Node> $output
     */
    private function collectBlockChildren(
        array $nodes,
        array $targets,
        array &$output,
        CompilationContext $context,
    ): void {
        foreach ($nodes as $node) {
            if (!($node instanceof ElementNode) && !($node instanceof FragmentNode)) {
                continue;
            }

            $blockDirective = $this->getBlockDirective($node, $context);
            $blockName = $blockDirective['name'] ?? '';

            if ($blockName !== '' && isset($targets[$blockName])) {
                $output[] = $node;

                continue;
            }

            $this->collectBlockChildren($node->children, $targets, $output, $context);
        }
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $children
     * @return array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Enum\BlockMergeMode, attributeName: string}>
     */
    private function collectBlocksFromChildren(array $children, CompilationContext $context): array
    {
        $doc = new DocumentNode($children);

        return $this->collectBlocks($doc, $context);
    }

    /**
     * @param array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Enum\BlockMergeMode, attributeName: string}> $childBlocks
     */
    private function replaceBlocksInNode(Node $node, array $childBlocks): Node
    {
        if (!($node instanceof ElementNode) && !($node instanceof FragmentNode)) {
            return $node;
        }

        $blockName = AttributeHelper::getStringAttributeValue(
            $node,
            $this->prefixHelper->buildName('block'),
        );

        if ($blockName === '') {
            $blockName = null;
        }

        if ($blockName !== null && isset($childBlocks[$blockName])) {
            $childBlock = $childBlocks[$blockName]['node'];
            $mode = $childBlocks[$blockName]['mode'];
            $attributeName = $childBlocks[$blockName]['attributeName'];

            return $this->mergeBlock($node, $childBlock, $mode, $attributeName);
        }

        $newChildren = [];
        foreach ($node->children as $child) {
            $newChildren[] = $this->replaceBlocksInNode($child, $childBlocks);
        }

        if ($node instanceof ElementNode) {
            return NodeCloner::withChildren($node, $newChildren);
        }

        return NodeCloner::fragmentWithChildren($node, $newChildren);
    }

    /**
     * @return array{name: string, mode: \Sugar\Core\Enum\BlockMergeMode, attributeName: string}|null
     */
    private function getBlockDirective(ElementNode|FragmentNode $node, CompilationContext $context): ?array
    {
        $blockAttr = $this->prefixHelper->buildName('block');
        $appendAttr = $this->prefixHelper->buildName('append');
        $prependAttr = $this->prefixHelper->buildName('prepend');

        $found = [];
        if (AttributeHelper::hasAttribute($node, $blockAttr)) {
            $found[] = $blockAttr;
        }

        if (AttributeHelper::hasAttribute($node, $appendAttr)) {
            $found[] = $appendAttr;
        }

        if (AttributeHelper::hasAttribute($node, $prependAttr)) {
            $found[] = $prependAttr;
        }

        if (count($found) > 1) {
            $message = sprintf(
                'Only one of %s, %s, or %s is allowed on a single element.',
                $blockAttr,
                $appendAttr,
                $prependAttr,
            );
            $attr = AttributeHelper::findAttribute($node->attributes, $found[1])
                ?? AttributeHelper::findAttribute($node->attributes, $found[0]);
            $line = $attr instanceof AttributeNode ? $attr->line : $node->line;
            $column = $attr instanceof AttributeNode ? $attr->column : $node->column;

            if ($attr instanceof AttributeNode) {
                throw $context->createSyntaxExceptionForAttribute(
                    $message,
                    $attr,
                    $line,
                    $column,
                );
            }

            throw $context->createSyntaxExceptionForNode(
                $message,
                $node,
                $line,
                $column,
            );
        }

        $attributeName = $found[0] ?? null;
        if ($attributeName === null) {
            return null;
        }

        $name = AttributeHelper::getStringAttributeValue($node, $attributeName);
        if ($name === '') {
            return null;
        }

        $mode = match ($attributeName) {
            $appendAttr => BlockMergeMode::APPEND,
            $prependAttr => BlockMergeMode::PREPEND,
            default => BlockMergeMode::REPLACE,
        };

        return [
            'name' => $name,
            'mode' => $mode,
            'attributeName' => $attributeName,
        ];
    }

    /**
     * Merge child block into parent according to block merge mode.
     */
    private function mergeBlock(
        ElementNode|FragmentNode $parent,
        Node $child,
        BlockMergeMode $mode,
        string $attributeName,
    ): Node {
        if ($mode === BlockMergeMode::REPLACE) {
            return $this->replaceBlock($parent, $child, $attributeName);
        }

        return $this->mergeBlockChildren($parent, $child, $mode, $attributeName);
    }

    /**
     * Replace parent block contents using child block content.
     */
    private function replaceBlock(
        ElementNode|FragmentNode $parent,
        Node $child,
        string $attributeName,
    ): Node {
        if ($child instanceof ElementNode) {
            if ($parent instanceof ElementNode) {
                return NodeCloner::withChildren($parent, $child->children);
            }

            return $child;
        }

        if ($child instanceof FragmentNode) {
            $hasDirectives = $this->fragmentHasDirectives($child);
            $cleanAttrs = $this->removeBlockAttribute($child, $attributeName);

            if ($parent instanceof ElementNode) {
                if ($hasDirectives) {
                    $wrappedFragment = NodeCloner::fragmentWithChildren($child, $child->children);
                    $wrappedFragment = NodeCloner::fragmentWithAttributes($wrappedFragment, $cleanAttrs);

                    return NodeCloner::withChildren($parent, [$wrappedFragment]);
                }

                return NodeCloner::withChildren($parent, $child->children);
            }

            if ($hasDirectives) {
                return NodeCloner::fragmentWithAttributes($child, $cleanAttrs);
            }

            return NodeCloner::fragmentWithChildren($parent, $child->children);
        }

        return $parent;
    }

    /**
     * Append or prepend child block contents into the parent block.
     */
    private function mergeBlockChildren(
        ElementNode|FragmentNode $parent,
        Node $child,
        BlockMergeMode $mode,
        string $attributeName,
    ): Node {
        if ($child instanceof ElementNode) {
            if ($parent instanceof ElementNode) {
                $merged = $this->mergeChildren($parent->children, $child->children, $mode);

                return NodeCloner::withChildren($parent, $merged);
            }

            $merged = $this->mergeChildren($parent->children, [$child], $mode);

            return NodeCloner::fragmentWithChildren($parent, $merged);
        }

        if ($child instanceof FragmentNode) {
            $hasDirectives = $this->fragmentHasDirectives($child);
            $cleanAttrs = $this->removeBlockAttribute($child, $attributeName);

            if ($parent instanceof ElementNode) {
                if ($hasDirectives) {
                    $wrappedFragment = NodeCloner::fragmentWithChildren($child, $child->children);
                    $wrappedFragment = NodeCloner::fragmentWithAttributes($wrappedFragment, $cleanAttrs);
                    $merged = $this->mergeChildren($parent->children, [$wrappedFragment], $mode);

                    return NodeCloner::withChildren($parent, $merged);
                }

                $merged = $this->mergeChildren($parent->children, $child->children, $mode);

                return NodeCloner::withChildren($parent, $merged);
            }

            if ($hasDirectives) {
                $wrappedFragment = NodeCloner::fragmentWithChildren($child, $child->children);
                $wrappedFragment = NodeCloner::fragmentWithAttributes($wrappedFragment, $cleanAttrs);
                $merged = $this->mergeChildren($parent->children, [$wrappedFragment], $mode);

                return NodeCloner::fragmentWithChildren($parent, $merged);
            }

            $merged = $this->mergeChildren($parent->children, $child->children, $mode);

            return NodeCloner::fragmentWithChildren($parent, $merged);
        }

        return $parent;
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $base
     * @param array<\Sugar\Core\Ast\Node> $additional
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function mergeChildren(array $base, array $additional, BlockMergeMode $mode): array
    {
        return match ($mode) {
            BlockMergeMode::APPEND => [...$base, ...$additional],
            BlockMergeMode::PREPEND => [...$additional, ...$base],
            BlockMergeMode::REPLACE => $additional,
        };
    }

    /**
     * Check whether fragment carries non-inheritance directives.
     */
    private function fragmentHasDirectives(FragmentNode $node): bool
    {
        foreach ($node->attributes as $attr) {
            if (
                $this->prefixHelper->isDirective($attr->name) &&
                !$this->prefixHelper->isInheritanceAttribute($attr->name)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<\Sugar\Core\Ast\AttributeNode>
     */
    private function removeBlockAttribute(FragmentNode $node, string $attributeName): array
    {
        return AttributeHelper::removeAttribute($node->attributes, $attributeName);
    }
}
