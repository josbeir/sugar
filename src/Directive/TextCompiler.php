<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Enum\DirectiveType;
use Sugar\Enum\OutputContext;
use Sugar\Extension\DirectiveCompilerInterface;

/**
 * Compiler for text directive
 *
 * Transforms s:text directives into escaped output nodes.
 * Content is automatically escaped based on context (HTML/JS/CSS/URL).
 * The output is injected into the element, preserving the wrapper.
 *
 * Example:
 * ```
 * <div s:text="$user->name"></div>
 * ```
 *
 * Compiles to:
 * ```php
 * <div><?= htmlspecialchars((string)($user->name), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
 * ```
 */
final readonly class TextCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node): array
    {
        // Create OutputNode with escaping enabled and inject into element's children
        $outputNode = new OutputNode(
            expression: $node->expression,
            escape: true,
            context: OutputContext::HTML,
            line: $node->line,
            column: $node->column,
        );

        // Return element's children with the OutputNode injected
        return [$outputNode, ...$node->children];
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTENT;
    }
}
