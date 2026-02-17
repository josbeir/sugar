<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Enum\AttributeMergeMode;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\AttributeMergePolicyDirectiveInterface;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Runtime\HtmlAttributeHelper;

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
 * <div class="<?= \Sugar\Core\Runtime\classNames(['btn', 'active' => $isActive, 'disabled' => !$canClick]) ?>">
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
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
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
