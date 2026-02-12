<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Ast\DirectiveNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Helper\NodeCloner;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Compiler\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Directive\Interface\ElementAwareDirectiveInterface;
use Sugar\Enum\DirectiveType;
use Sugar\Util\Hash;

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
readonly class IfContentDirective implements DirectiveInterface, ElementAwareDirectiveInterface
{
    /**
     * @param \Sugar\Ast\DirectiveNode $node
     * @return array<\Sugar\Ast\Node>
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        // Generate unique variable name for this ifcontent instance
        $varName = '$__content_' . Hash::short($node->expression . $node->line . $node->column);

        $parts = [];

        // Start output buffering
        $parts[] = $this->rawNode('ob_start();', $node);

        // Add children nodes (the content to be buffered)
        array_push($parts, ...$node->children);

        // Capture buffered content and check if non-empty
        $captureCode = sprintf(
            "%s = ob_get_clean();%sif (trim(%s) !== ''):",
            $varName,
            PHP_EOL,
            $varName,
        );
        $parts[] = $this->rawNode($captureCode, $node);

        // Get element metadata to recreate opening and closing tags
        $element = $node->getElementNode();
        if ($element !== null) {
            // Build attributes string
            $attributes = '';
            foreach ($element->attributes as $attr) {
                if (!$this->isIfContentAttribute($attr->name)) {
                    if ($attr->value->isBoolean()) {
                        $attributes .= ' ' . $attr->name;
                    } elseif ($attr->value->isStatic()) {
                        // Static string value
                        $attributes .= sprintf(' %s="%s"', $attr->name, $attr->value->static ?? '');
                    }

                    // Note: Dynamic attributes (OutputNode) are not supported in s:ifcontent context
                    // They would have been transformed by previous passes
                }
            }

            // Build opening tag
            if ($element->dynamicTag !== null) {
                // Dynamic tag - output using variable
                $parts[] = $this->rawNode(
                    sprintf("echo '<' . %s . %s . '>';", $element->dynamicTag, var_export($attributes, true)),
                    $node,
                );
            } else {
                // Static tag
                $openingTag = '<' . $element->tag . $attributes . '>';
                $parts[] = $this->rawNode(
                    sprintf('echo %s;', var_export($openingTag, true)),
                    $node,
                );
            }

            // Output the captured content
            $parts[] = $this->rawNode('echo ' . $varName . ';', $node);

            // Output closing tag (if not self-closing)
            if (!$element->selfClosing) {
                if ($element->dynamicTag !== null) {
                    // Dynamic closing tag
                    $parts[] = $this->rawNode(
                        sprintf("echo '</' . %s . '>';", $element->dynamicTag),
                        $node,
                    );
                } else {
                    // Static closing tag
                    $parts[] = $this->rawNode(
                        sprintf('echo %s;', var_export('</' . $element->tag . '>', true)),
                        $node,
                    );
                }
            }
        } else {
            // Fallback: just output the captured content
            $parts[] = $this->rawNode('echo ' . $varName . ';', $node);
        }

        // Close the if statement
        $parts[] = $this->rawNode('endif;', $node);

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
        $directiveNode->inheritTemplatePathFrom($element);

        // Store element metadata (without s:ifcontent attribute) so compiler can recreate tags
        $elementForIfContent = NodeCloner::withAttributesAndChildren(
            $element,
            $remainingAttrs,
            [], // Empty children - will be filled by compiler
        );
        $directiveNode->setElementNode($elementForIfContent);

        return $directiveNode;
    }

    /**
     * Build a RawPhpNode that inherits the directive's template path.
     */
    private function rawNode(string $code, Node $origin): RawPhpNode
    {
        $rawNode = new RawPhpNode($code, $origin->line, $origin->column);
        $rawNode->inheritTemplatePathFrom($origin);

        return $rawNode;
    }

    /**
     * Check whether an attribute name targets the ifcontent directive.
     */
    private function isIfContentAttribute(string $name): bool
    {
        return $name === 'ifcontent' || str_ends_with($name, ':ifcontent');
    }
}
