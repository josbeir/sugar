<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\Interface\ElementAwareDirectiveInterface;
use Sugar\Core\Runtime\HtmlTagHelper;
use Sugar\Core\Util\Hash;

/**
 * Compiler for s:tag directive (dynamic tag names)
 *
 * Transforms s:tag directives to change HTML element tag names dynamically at runtime.
 * Validates tag names for security and correctness.
 *
 * Example:
 * ```
 * <div s:tag="$headingLevel">Title</div>
 * ```
 *
 * With $headingLevel = 'h1', compiles to:
 * ```php
 * <?php $__tag_HASH = \Sugar\Core\Runtime\HtmlTagHelper::validateTagName($headingLevel); ?>
 * <<?= $__tag_HASH ?>>Title</<?= $__tag_HASH ?>>
 * ```
 *
 * Security features:
 * - Validates tag names (alphanumeric only, must start with letter)
 * - Blocks dangerous tags (script, iframe, object, etc.)
 * - Prevents XSS through tag name injection
 */
readonly class TagDirective implements DirectiveInterface, ElementAwareDirectiveInterface
{
    /**
     * Extract s:tag directive from element
     *
     * Returns a FragmentNode containing:
     * 1. Validation RawPhpNode that stores validated tag name
     * 2. ElementNode with dynamicTag property set
     *
     * This allows other directives (like s:ifcontent) to process the modified element.
     */
    public function extractFromElement(
        ElementNode $element,
        string $expression,
        array $transformedChildren,
        array $remainingAttrs,
    ): FragmentNode {
        // Generate unique variable name for this tag instance
        $varName = '$__tag_' . Hash::short($expression . $element->line . $element->column);

        // Create validation node
        $validation = new RawPhpNode(
            sprintf(
                '%s = %s::validateTagName(%s);',
                $varName,
                HtmlTagHelper::class,
                $expression,
            ),
            $element->line,
            $element->column,
        );

        // Create element with dynamic tag set
        $modifiedElement = new ElementNode(
            tag: $element->tag,
            attributes: $remainingAttrs,
            children: $transformedChildren,
            selfClosing: $element->selfClosing,
            line: $element->line,
            column: $element->column,
            dynamicTag: $varName,
        );

        // Return fragment with validation + modified element
        return new FragmentNode(
            attributes: [],
            children: [$validation, $modifiedElement],
            line: $element->line,
            column: $element->column,
        );
    }

    /**
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        // This method should never be called because s:tag uses extractFromElement
        // But we implement it for completeness
        // Generate unique variable name for this tag instance
        $varName = '$__tag_' . Hash::short($node->expression . $node->line . $node->column);

        // Generate PHP code to validate and store tag name
        $code = sprintf(
            '%s = %s::validateTagName(%s);',
            $varName,
            HtmlTagHelper::class,
            $node->expression,
        );

        return [
            new RawPhpNode(
                $code,
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
