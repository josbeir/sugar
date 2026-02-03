<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Enum\DirectiveType;
use Sugar\Enum\OutputContext;
use Sugar\Extension\DirectiveCompilerInterface;

/**
 * Compiler for html directive
 *
 * Transforms s:html directives into unescaped output nodes.
 * Content is rendered without escaping - USE WITH CAUTION!
 * Only use with trusted content to prevent XSS vulnerabilities.
 * The output is injected into the element, preserving the wrapper.
 *
 * Example:
 * ```
 * <div s:html="$trustedContent"></div>
 * ```
 *
 * Compiles to:
 * ```php
 * <div><?= $trustedContent ?></div>
 * ```
 */
final readonly class HtmlCompiler implements DirectiveCompilerInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node): array
    {
        // Create OutputNode with escaping disabled and RAW context, inject into element's children
        $outputNode = new OutputNode(
            expression: $node->expression,
            escape: false,
            context: OutputContext::RAW,
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
