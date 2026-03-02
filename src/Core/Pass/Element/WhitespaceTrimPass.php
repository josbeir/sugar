<?php
declare(strict_types=1);

namespace Sugar\Core\Pass\Element;

use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;

/**
 * Trims whitespace-only child text nodes on elements marked with s:trim.
 *
 * This pass runs before directive extraction so that `s:trim` is consumed as
 * a compile-time element behavior toggle instead of a regular directive.
 */
final class WhitespaceTrimPass implements AstPassInterface
{
    private readonly DirectivePrefixHelper $prefixHelper;

    /**
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration used to derive directive prefix
     */
    public function __construct(SugarConfig $config)
    {
        $this->prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
    }

    /**
     * @inheritDoc
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof ElementNode) {
            $this->applyTrimToElement($node);
        }

        $children = $this->getChildren($node);
        if ($children !== null) {
            foreach ($children as $index => $child) {
                if (!$child instanceof ElementNode) {
                    continue;
                }

                $children[$index] = $this->applyTrimToElement($child);
            }

            $this->setChildren($node, $children);
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
     * Remove whitespace-only text nodes from a children list.
     *
     * @param array<\Sugar\Core\Ast\Node> $children
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function withoutWhitespaceOnlyTextChildren(array $children): array
    {
        return array_values(array_filter(
            $children,
            static function (Node $child): bool {
                if (!$child instanceof TextNode) {
                    return true;
                }

                return preg_match('/^\s*$/u', $child->content) !== 1;
            },
        ));
    }

    /**
     * Apply s:trim behavior to a single element.
     */
    private function applyTrimToElement(ElementNode $element): ElementNode
    {
        $trimAttributeName = $this->prefixHelper->buildName('trim');
        if (!AttributeHelper::hasAttribute($element, $trimAttributeName)) {
            return $element;
        }

        $element->attributes = AttributeHelper::removeAttribute($element->attributes, $trimAttributeName);
        $element->children = $this->withoutWhitespaceOnlyTextChildren($element->children);

        return $element;
    }

    /**
     * @return array<\Sugar\Core\Ast\Node>|null
     */
    private function getChildren(Node $node): ?array
    {
        if (
            $node instanceof DocumentNode
            || $node instanceof ElementNode
            || $node instanceof FragmentNode
            || $node instanceof ComponentNode
            || $node instanceof DirectiveNode
        ) {
            return $node->children;
        }

        return null;
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $children
     */
    private function setChildren(Node $node, array $children): void
    {
        if (
            $node instanceof DocumentNode
            || $node instanceof ElementNode
            || $node instanceof FragmentNode
            || $node instanceof ComponentNode
            || $node instanceof DirectiveNode
        ) {
            $node->children = $children;
        }
    }
}
