<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Compiler\CompilationContext;
use Sugar\Directive\Interface\AttributeMergePolicyDirectiveInterface;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Enum\AttributeMergeMode;
use Sugar\Enum\DirectiveType;
use Sugar\Runtime\HtmlAttributeHelper;

/**
 * Compiler for s:class directive (dynamic CSS classes)
 *
 * Transforms s:class directives into calls to the classNames() helper.
 * Handles conditional class arrays for clean dynamic class generation.
 *
 * Example:
 * ```
 * <div s:class="['btn', 'active' => $isActive, 'disabled' => !$canClick]">
 *     Button
 * </div>
 * ```
 *
 * Compiles to:
 * ```php
 * <div class="<?= \Sugar\Runtime\classNames(['btn', 'active' => $isActive, 'disabled' => !$canClick]) ?>">
 *     Button
 * </div>
 * ```
 *
 * Note: s:class is compiled into a class="..." attribute, not a directive node.
 * This compilation happens in DirectiveExtractionPass.
 */
readonly class ClassDirective implements DirectiveInterface, AttributeMergePolicyDirectiveInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        // s:class is handled as attribute output, not control structure
        // This compiler shouldn't be called directly - it's for registry completeness
        return [
            new RawPhpNode(
                sprintf(
                    'class="<?= ' . HtmlAttributeHelper::class . '::classNames(%s) ?>"',
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
        return AttributeMergeMode::MERGE_NAMED;
    }

    /**
     * @inheritDoc
     */
    public function getMergeTargetAttributeName(): ?string
    {
        return 'class';
    }

    /**
     * @inheritDoc
     */
    public function mergeNamedAttributeExpression(string $existingExpression, string $incomingExpression): string
    {
        return sprintf(
            '%s::classNames([%s, %s])',
            HtmlAttributeHelper::class,
            $existingExpression,
            $incomingExpression,
        );
    }

    /**
     * @inheritDoc
     */
    public function buildExcludedAttributesExpression(string $sourceExpression, array $excludedAttributeNames): string
    {
        return sprintf('%s::spreadAttrs(%s)', HtmlAttributeHelper::class, $sourceExpression);
    }
}
