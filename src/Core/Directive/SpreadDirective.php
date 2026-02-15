<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Interface\AttributeMergePolicyDirectiveInterface;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Enum\AttributeMergeMode;
use Sugar\Core\Enum\DirectiveType;
use Sugar\Core\Runtime\HtmlAttributeHelper;

/**
 * Compiler for s:spread directive (attribute spreading)
 *
 * Transforms s:spread directives into calls to the spreadAttrs() helper.
 * Spreads an array of attributes onto an element (like JSX spread).
 *
 * Example:
 * ```
 * <div s:spread="$attrs">Content</div>
 * ```
 *
 * Compiles to:
 * ```php
 * <div <?= \Sugar\Core\Runtime\spreadAttrs($attrs) ?>>Content</div>
 * ```
 *
 * With:
 * ```php
 * $attrs = [
 *     'id' => 'user-123',
 *     'class' => 'card',
 *     'disabled' => true,
 *     'hidden' => false, // omitted
 * ];
 * ```
 *
 * Outputs:
 * ```html
 * <div id="user-123" class="card" disabled>Content</div>
 * ```
 */
readonly class SpreadDirective implements DirectiveInterface, AttributeMergePolicyDirectiveInterface
{
    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        // s:spread is handled as attribute output, not control structure
        return [
            new RawPhpNode(
                sprintf(
                    '<?= ' . HtmlAttributeHelper::class . '::spreadAttrs(%s) ?>',
                    $node->expression,
                ),
                $node->line,
                $node->column,
            ),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::ATTRIBUTE;
    }

    /**
     * @inheritDoc
     */
    public function getAttributeMergeMode(): AttributeMergeMode
    {
        return AttributeMergeMode::EXCLUDE_NAMED;
    }

    /**
     * @inheritDoc
     */
    public function getMergeTargetAttributeName(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mergeNamedAttributeExpression(string $existingExpression, string $incomingExpression): string
    {
        return $incomingExpression;
    }

    /**
     * @inheritDoc
     */
    public function buildExcludedAttributesExpression(string $sourceExpression, array $excludedAttributeNames): string
    {
        if ($excludedAttributeNames === []) {
            return sprintf('%s::spreadAttrs(%s)', HtmlAttributeHelper::class, $sourceExpression);
        }

        $keys = implode(
            ', ',
            array_map(
                static fn(string $name): string => sprintf('%s => true', var_export($name, true)),
                array_values(array_unique($excludedAttributeNames)),
            ),
        );

        return sprintf(
            '%s::spreadAttrs(array_diff_key((array) (%s), [%s]))',
            HtmlAttributeHelper::class,
            $sourceExpression,
            $keys,
        );
    }
}
