<?php
declare(strict_types=1);

namespace Sugar\Core\Directive;

use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\NodeCloner;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\Interface\ElementAwareDirectiveInterface;
use Sugar\Core\Util\Hash;

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
     * @param \Sugar\Core\Ast\DirectiveNode $node
     * @return array<\Sugar\Core\Ast\Node>
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
            // Build opening tag
            if ($element->dynamicTag !== null) {
                $parts[] = $this->rawNode(
                    sprintf("echo '<' . %s;", $element->dynamicTag),
                    $node,
                );
            } else {
                $parts[] = $this->rawNode(
                    sprintf('echo %s;', var_export('<' . $element->tag, true)),
                    $node,
                );
            }

            foreach ($element->attributes as $attr) {
                if ($this->isIfContentAttribute($attr->name)) {
                    continue;
                }

                if ($attr->name === '' && $attr->value->isOutput()) {
                    $spreadOutput = $attr->value->output;
                    if ($spreadOutput instanceof OutputNode) {
                        $spreadExpression = sprintf(
                            '$__ifcontent_attr = %s;',
                            $spreadOutput->expression,
                        ) . " if (\$__ifcontent_attr !== '') { echo ' ' . \$__ifcontent_attr; }";

                        $parts[] = $this->rawNode(
                            $spreadExpression,
                            $node,
                        );
                    }

                    continue;
                }

                if ($attr->value->isBoolean()) {
                    $parts[] = $this->rawNode(
                        sprintf('echo %s;', var_export(' ' . $attr->name, true)),
                        $node,
                    );

                    continue;
                }

                if ($attr->value->isStatic()) {
                    $staticAttribute = sprintf(
                        ' %s="%s"',
                        $attr->name,
                        $attr->value->static ?? '',
                    );

                    $parts[] = $this->rawNode(
                        sprintf('echo %s;', var_export($staticAttribute, true)),
                        $node,
                    );

                    continue;
                }

                if ($attr->value->isOutput()) {
                    $output = $attr->value->output;
                    if (!$output instanceof OutputNode) {
                        continue;
                    }

                    $parts[] = $this->rawNode(
                        sprintf('echo %s;', var_export(' ' . $attr->name . '="', true)),
                        $node,
                    );

                    if ($output->escape) {
                        $parts[] = $this->rawNode(
                            sprintf(
                                'echo htmlspecialchars((string) (%s), ENT_QUOTES, "UTF-8");',
                                $output->expression,
                            ),
                            $node,
                        );
                    } else {
                        $parts[] = $this->rawNode(
                            sprintf('echo %s;', $output->expression),
                            $node,
                        );
                    }

                    $parts[] = $this->rawNode(
                        sprintf('echo %s;', var_export('"', true)),
                        $node,
                    );
                }
            }

            $parts[] = $this->rawNode("echo '>';", $node);

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
