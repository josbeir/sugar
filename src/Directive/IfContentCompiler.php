<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Helper\NodeCloner;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Context\CompilationContext;
use Sugar\Directive\Interface\DirectiveCompilerInterface;
use Sugar\Directive\Interface\ElementExtractionInterface;
use Sugar\Enum\DirectiveType;

/**
 * Compiler for s:ifcontent directive (conditional wrapper)
 *
 * Transforms s:ifcontent directives to only render the wrapper element if
 * its content is non-empty (after trimming whitespace).
 *
 * Example:
 * ```
 * <div class="card" s:ifcontent>
 *     <?= $description ?>
 * </div>
 * ```
 *
 * If $description is empty, the entire <div> is not rendered.
 *
 * Compiles to:
 * ```php
 * <?php ob_start(); ?>
 *     <?= $description ?>
 * <?php $__content_HASH = ob_get_clean(); ?>
 * <?php if (trim($__content_HASH) !== ''): ?>
 * <div class="card">
 *     <?= $__content_HASH ?>
 * </div>
 * <?php endif; ?>
 * ```
 *
 * Use cases:
 * - Optional alert/error boxes that should disappear when empty
 * - List wrappers that hide when no items
 * - Card/panel wrappers for optional content
 */
readonly class IfContentCompiler implements DirectiveCompilerInterface, ElementExtractionInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        // Generate unique variable name for this ifcontent instance
        $varName = '$__content_' . substr(md5($node->expression . $node->line . $node->column), 0, 8);

        $parts = [];

        // Start output buffering
        $parts[] = new RawPhpNode('ob_start();', $node->line, $node->column);

        // Add children nodes (the content to be buffered)
        array_push($parts, ...$node->children);

        // Capture buffered content and check if non-empty
        $captureCode = sprintf(
            "%s = ob_get_clean();%sif (trim(%s) !== ''):",
            $varName,
            PHP_EOL,
            $varName,
        );
        $parts[] = new RawPhpNode($captureCode, $node->line, $node->column);

        // Get element metadata to recreate opening and closing tags
        $element = $node->getElementNode();
        if ($element !== null) {
            // Build attributes string
            $attributes = '';
            foreach ($element->attributes as $attr) {
                if ($attr->name !== 's:ifcontent' && $attr->name !== 'ifcontent') {
                    if ($attr->value === null) {
                        $attributes .= ' ' . $attr->name;
                    } elseif (is_string($attr->value)) {
                        // Static string value
                        $attributes .= sprintf(' %s="%s"', $attr->name, $attr->value);
                    }

                    // Note: Dynamic attributes (OutputNode) are not supported in s:ifcontent context
                    // They would have been transformed by previous passes
                }
            }

            // Build opening tag
            if ($element->dynamicTag !== null) {
                // Dynamic tag - output using variable
                $parts[] = new RawPhpNode(
                    sprintf("echo '<' . %s . %s . '>';", $element->dynamicTag, var_export($attributes, true)),
                    $node->line,
                    $node->column,
                );
            } else {
                // Static tag
                $openingTag = '<' . $element->tag . $attributes . '>';
                $parts[] = new RawPhpNode(
                    sprintf('echo %s;', var_export($openingTag, true)),
                    $node->line,
                    $node->column,
                );
            }

            // Output the captured content
            $parts[] = new RawPhpNode('echo ' . $varName . ';', $node->line, $node->column);

            // Output closing tag (if not self-closing)
            if (!$element->selfClosing) {
                if ($element->dynamicTag !== null) {
                    // Dynamic closing tag
                    $parts[] = new RawPhpNode(
                        sprintf("echo '</' . %s . '>';", $element->dynamicTag),
                        $node->line,
                        $node->column,
                    );
                } else {
                    // Static closing tag
                    $parts[] = new RawPhpNode(
                        sprintf('echo %s;', var_export('</' . $element->tag . '>', true)),
                        $node->line,
                        $node->column,
                    );
                }
            }
        } else {
            // Fallback: just output the captured content
            $parts[] = new RawPhpNode('echo ' . $varName . ';', $node->line, $node->column);
        }

        // Close the if statement
        $parts[] = new RawPhpNode('endif;', $node->line, $node->column);

        return $parts;
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }

    /**
     * Custom extraction: store element metadata for wrapping entire element
     *
     * @inheritDoc
     */
    public function extractFromElement(
        ElementNode $element,
        string $expression,
        array $transformedChildren,
        array $remainingAttrs,
    ): ElementNode|DirectiveNode|FragmentNode {
        // Create directive node with just the inner content as children
        $directiveNode = new DirectiveNode(
            name: 'ifcontent',
            expression: $expression,
            children: $transformedChildren,
            line: $element->line,
            column: $element->column,
        );

        // Store element metadata (without s:ifcontent attribute) so compiler can recreate tags
        $elementForIfContent = NodeCloner::withAttributesAndChildren(
            $element,
            $remainingAttrs,
            [], // Empty children - will be filled by compiler
        );
        $directiveNode->setElementNode($elementForIfContent);

        return $directiveNode;
    }
}
