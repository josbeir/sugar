<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Enum\DirectiveType;
use Sugar\Runtime\HtmlAttributeHelper;

/**
 * Compiler for boolean HTML attributes (s:checked, s:selected, s:disabled)
 *
 * Conditionally adds boolean attributes to elements based on a condition.
 * The attribute is added when the condition is truthy, omitted otherwise.
 *
 * Example:
 * ```
 * <input type="checkbox" s:checked="$isSubscribed">
 * ```
 *
 * Compiles to:
 * ```php
 * <input type="checkbox" <?= \Sugar\Runtime\HtmlAttributeHelper::booleanAttribute('checked', $isSubscribed) ?>>
 * ```
 *
 * Supported attributes:
 * - `s:checked` - For checkboxes and radio inputs
 * - `s:selected` - For option elements
 * - `s:disabled` - For any form element
 */
readonly class BooleanAttributeDirective implements DirectiveInterface
{
    /**
     * Map directive names to their HTML attribute names
     */
    private const ATTRIBUTE_MAP = [
        'checked' => 'checked',
        'selected' => 'selected',
        'disabled' => 'disabled',
    ];

    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        $attributeName = self::ATTRIBUTE_MAP[$node->name] ?? $node->name;

        // Use spread format (empty attribute name) to output raw PHP
        // This will call a helper that conditionally outputs the attribute
        return [
            new RawPhpNode(
                sprintf(
                    "<?= %s::booleanAttribute('%s', %s) ?>",
                    HtmlAttributeHelper::class,
                    $attributeName,
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
}
