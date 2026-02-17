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
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Template\Enum\BlockMergeMode;

/**
 * Extracts block declarations and applies child block overrides to parent templates.
 *
 * The merger understands the inheritance directives `s:block`, `s:append`,
 * `s:prepend`, and `s:parent`, including structural validation rules for
 * parent placeholders.
 */
final class BlockMerger
{
    /**
     * Create a merger for a specific directive prefix configuration.
     *
     * @param \Sugar\Core\Config\Helper\DirectivePrefixHelper $prefixHelper Directive prefix helper
     */
    public function __construct(private readonly DirectivePrefixHelper $prefixHelper)
    {
    }

    /**
     * Extract only the requested block nodes in first-encounter order.
     *
     * Traverses the tree depth-first and appends matching block elements to the
     * output document without modifying node contents.
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
     * Collect block directives from a document tree.
     *
     * Returns a map keyed by block name for `s:block`, `s:append`, and
     * `s:prepend` declarations, and enforces `s:parent` placement rules while
     * walking the tree.
     *
     * @return array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Template\Enum\BlockMergeMode, attributeName: string}>
     */
    public function collectBlocks(DocumentNode $document, CompilationContext $context): array
    {
        $blocks = [];

        foreach ($document->children as $child) {
            $this->collectBlocksFromNode($child, $context, false, $blocks);
        }

        return $blocks;
    }

    /**
     * Recursively collect block directives from a single node subtree.
     *
     * The traversal validates `s:parent` context/shape and records each block
     * definition exactly once per child template.
     *
     * @param array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Template\Enum\BlockMergeMode, attributeName: string}> $blocks
     */
    private function collectBlocksFromNode(
        Node $node,
        CompilationContext $context,
        bool $insideBlock,
        array &$blocks,
    ): void {
        if (!($node instanceof ElementNode) && !($node instanceof FragmentNode)) {
            return;
        }

        $blockDirective = $this->getBlockDirective($node, $context);
        $parentAttr = $this->findParentAttribute($node);
        $insideReplaceBlock = $insideBlock || (
            $blockDirective !== null
            && $blockDirective['mode'] === BlockMergeMode::REPLACE
        );

        if ($parentAttr instanceof AttributeNode && !$insideBlock) {
            throw $context->createSyntaxExceptionForAttribute(
                sprintf(
                    '%s is only allowed inside %s.',
                    $this->prefixHelper->buildName('parent'),
                    $this->prefixHelper->buildName('block'),
                ),
                $parentAttr,
            );
        }

        if ($parentAttr instanceof AttributeNode) {
            $this->validateParentPlaceholderShape($node, $parentAttr, $context);
        }

        if ($blockDirective !== null) {
            $this->assertUniqueBlockDefinition($blockDirective['name'], $node, $context, $blocks);

            $blocks[$blockDirective['name']] = [
                'node' => $node,
                'mode' => $blockDirective['mode'],
                'attributeName' => $blockDirective['attributeName'],
            ];
        }

        foreach ($node->children as $child) {
            $this->collectBlocksFromNode($child, $context, $insideReplaceBlock, $blocks);
        }
    }

    /**
     * Ensure a block name is only defined once in the current child template.
     *
     * Duplicate names across `s:block`, `s:append`, and `s:prepend` are
     * rejected to keep override intent explicit and deterministic.
     *
     * @param array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Template\Enum\BlockMergeMode, attributeName: string}> $blocks
     */
    private function assertUniqueBlockDefinition(
        string $name,
        ElementNode|FragmentNode $node,
        CompilationContext $context,
        array $blocks,
    ): void {
        if (!isset($blocks[$name])) {
            return;
        }

        throw $context->createSyntaxExceptionForNode(
            sprintf(
                'Block "%s" is defined multiple times in the same child template. '
                . 'Define it once and use %s inside %s.',
                $name,
                $this->prefixHelper->buildName('parent'),
                $this->prefixHelper->buildName('block'),
            ),
            $node,
        );
    }

    /**
     * Apply child block definitions to a parent document.
     *
     * Each parent block node is replaced, appended to, or prepended to
     * according to the mode recorded in the child block map.
     *
     * @param array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Template\Enum\BlockMergeMode, attributeName: string}> $childBlocks
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
     * Recursively collect matching block nodes for extraction.
     *
     * Nodes are appended to `$output` when their block name matches `$targets`.
     * Search continues into child nodes for non-matching elements/fragments.
     *
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
     * Recursively replace block nodes in a subtree using collected child blocks.
     *
     * Non-block nodes are cloned with transformed children so the original AST
     * remains unchanged.
     *
     * @param array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Template\Enum\BlockMergeMode, attributeName: string}> $childBlocks
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
     * Resolve the block directive declared on a node.
     *
     * Detects which of `s:block`, `s:append`, or `s:prepend` is present,
     * validates that at most one is used, and converts it to merge metadata.
     *
     * @return array{name: string, mode: \Sugar\Core\Template\Enum\BlockMergeMode, attributeName: string}|null
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
     * Merge a child block node into the matched parent block node.
     *
     * Delegates to replacement behavior for `REPLACE`, otherwise to list merge
     * behavior for `APPEND` and `PREPEND`.
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
     * Replace parent block content using child block structure.
     *
     * Handles element-vs-fragment wrapper rules and resolves `s:parent`
     * placeholders before producing the final replacement node.
     */
    private function replaceBlock(
        ElementNode|FragmentNode $parent,
        Node $child,
        string $attributeName,
    ): Node {
        if ($child instanceof ElementNode) {
            $resolvedChildren = $this->resolveParentPlaceholders(
                $child->children,
                $parent->children,
            );

            if ($parent instanceof ElementNode) {
                return NodeCloner::withChildren($parent, $resolvedChildren);
            }

            return NodeCloner::withChildren($child, $resolvedChildren);
        }

        if ($child instanceof FragmentNode) {
            $resolvedChildren = $this->resolveParentPlaceholders(
                $child->children,
                $parent->children,
            );

            $hasDirectives = $this->fragmentHasDirectives($child);
            $cleanAttrs = $this->removeBlockAttribute($child, $attributeName);
            $childWithResolvedChildren = NodeCloner::fragmentWithChildren($child, $resolvedChildren);

            if ($parent instanceof ElementNode) {
                if ($hasDirectives) {
                    $wrappedFragment = NodeCloner::fragmentWithChildren($childWithResolvedChildren, $resolvedChildren);
                    $wrappedFragment = NodeCloner::fragmentWithAttributes($wrappedFragment, $cleanAttrs);

                    return NodeCloner::withChildren($parent, [$wrappedFragment]);
                }

                return NodeCloner::withChildren($parent, $resolvedChildren);
            }

            if ($hasDirectives) {
                return NodeCloner::fragmentWithAttributes($childWithResolvedChildren, $cleanAttrs);
            }

            return NodeCloner::fragmentWithChildren($parent, $resolvedChildren);
        }

        return $parent;
    }

    /**
     * Append or prepend child content into an existing parent block.
     *
     * For fragment children, wrapper preservation depends on whether the
     * fragment carries non-inheritance directives.
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
     * Merge two child node lists using the selected merge mode.
     *
     * `APPEND` adds after base children, `PREPEND` adds before base children,
     * and `REPLACE` returns only the additional children.
     *
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
     * Determine whether a fragment contains non-inheritance directives.
     *
     * This is used to decide whether the fragment wrapper must be preserved
     * during block merge operations.
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
     * Return fragment attributes without the active block merge attribute.
     *
     * Removes one of `s:block`, `s:append`, or `s:prepend` from the fragment
     * before re-emitting it in merged output.
     *
     * @return array<\Sugar\Core\Ast\AttributeNode>
     */
    private function removeBlockAttribute(FragmentNode $node, string $attributeName): array
    {
        return AttributeHelper::removeAttribute($node->attributes, $attributeName);
    }

    /**
     * Replace `s:parent` placeholders with clones of parent block children.
     *
     * Recurses into nested elements/fragments so placeholder expansion works at
     * any depth inside a replacement block.
     *
     * @param array<\Sugar\Core\Ast\Node> $children
     * @param array<\Sugar\Core\Ast\Node> $parentChildren
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function resolveParentPlaceholders(array $children, array $parentChildren): array
    {
        $parentName = $this->prefixHelper->buildName('parent');
        $resolved = [];

        foreach ($children as $child) {
            if ($child instanceof ElementNode || $child instanceof FragmentNode) {
                if (AttributeHelper::hasAttribute($child, $parentName)) {
                    array_push($resolved, ...NodeCloner::cloneNodes($parentChildren));

                    continue;
                }

                $nested = $this->resolveParentPlaceholders($child->children, $parentChildren);
                $resolved[] = $child instanceof ElementNode
                    ? NodeCloner::withChildren($child, $nested)
                    : NodeCloner::fragmentWithChildren($child, $nested);

                continue;
            }

            $resolved[] = $child;
        }

        return $resolved;
    }

    /**
     * Find the `s:parent` attribute declared on a node, if present.
     */
    private function findParentAttribute(ElementNode|FragmentNode $node): ?AttributeNode
    {
        return AttributeHelper::findAttribute(
            $node->attributes,
            $this->prefixHelper->buildName('parent'),
        );
    }

    /**
     * Validate structural constraints for an `s:parent` placeholder node.
     *
     * Placeholders must be declared on `<s-template>`, cannot include other
     * attributes, and cannot contain non-whitespace child content.
     */
    private function validateParentPlaceholderShape(
        ElementNode|FragmentNode $node,
        AttributeNode $parentAttr,
        CompilationContext $context,
    ): void {
        if (!$node instanceof FragmentNode) {
            throw $context->createSyntaxExceptionForAttribute(
                sprintf('%s must be used on <s-template>.', $this->prefixHelper->buildName('parent')),
                $parentAttr,
            );
        }

        if (count($node->attributes) !== 1) {
            throw $context->createSyntaxExceptionForAttribute(
                sprintf('%s cannot be combined with other attributes.', $this->prefixHelper->buildName('parent')),
                $parentAttr,
            );
        }

        foreach ($node->children as $child) {
            if ($child instanceof TextNode && trim($child->content) === '') {
                continue;
            }

            throw $context->createSyntaxExceptionForAttribute(
                sprintf('%s cannot have child content.', $this->prefixHelper->buildName('parent')),
                $parentAttr,
            );
        }
    }
}
