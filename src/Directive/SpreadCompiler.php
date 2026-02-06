<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\DirectiveCompilerInterface;
use Sugar\Enum\DirectiveType;
use Sugar\Runtime\HtmlAttributeHelper;

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
 * <div <?= \Sugar\Runtime\spreadAttrs($attrs) ?>>Content</div>
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
readonly class SpreadCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
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
}
