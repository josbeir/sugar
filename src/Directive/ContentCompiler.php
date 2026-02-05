<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\ElementNode;
use Sugar\Ast\Helper\NodeCloner;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Context\CompilationContext;
use Sugar\Enum\DirectiveType;
use Sugar\Enum\OutputContext;
use Sugar\Extension\DirectiveCompilerInterface;
use Sugar\Parser\PipeParser;

/**
 * Compiler for content directives (text, html)
 *
 * Transforms content directives into output nodes with configurable escaping.
 * The output is injected into the element, preserving the wrapper.
 *
 * Examples:
 * ```
 * // s:text - escaped output
 * <div s:text="$user->name"></div>
 * // Compiles to:
 * <div><?= htmlspecialchars((string)($user->name), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
 *
 * // s:html - unescaped output for trusted content
 * <div s:html="$trustedContent"></div>
 * // Compiles to:
 * <div><?= $trustedContent ?></div>
 * ```
 */
readonly class ContentCompiler implements DirectiveCompilerInterface
{
    /**
     * @param bool $escape Whether to escape output (true for s:text, false for s:html)
     * @param \Sugar\Enum\OutputContext $context Output context for escaping
     */
    public function __construct(
        private bool $escape = true,
        private OutputContext $context = OutputContext::HTML,
    ) {
    }

    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        // Parse pipe syntax from directive expression
        $parsed = PipeParser::parse($node->expression);

        // Create OutputNode with configured escaping
        $outputNode = new OutputNode(
            expression: $parsed['expression'],
            escape: $this->escape,
            context: $this->context,
            line: $node->line,
            column: $node->column,
            pipes: $parsed['pipes'],
        );

        // Content directives are handled differently depending on presence of control flow:
        // 1. With control flow (s:if + s:text): children are element's children, inject output
        // 2. Without control flow (s:text only): children contain the element itself
        //    In this case, we need to inject output into the element and return the element

        // If children is empty or doesn't contain elements, just return the output
        if ($node->children === []) {
            return [$outputNode];
        }

        // Check if first child is an ElementNode (case 2: element is wrapped)
        $firstChild = $node->children[0];
        if ($firstChild instanceof ElementNode) {
            // Element is wrapped - replace element's children with just the output
            // (element's children contain duplicate content DirectiveNode that will be processed separately)
            $modifiedElement = NodeCloner::withChildren(
                $firstChild,
                [$outputNode],
            );

            return [$modifiedElement];
        }

        // Default case: children are element's children (case 1: with control flow)
        // Return output to be injected into element
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
