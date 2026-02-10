<?php
declare(strict_types=1);

namespace Sugar\Pass\Component\Helper;

use Sugar\Config\Helper\DirectivePrefixHelper;
use Sugar\Extension\DirectiveRegistryInterface;
use Sugar\Pass\Directive\Helper\DirectiveClassifier;

/**
 * Classifies component attributes for expansion.
 *
 * Splits attributes into:
 * - Control flow directives (wrap or conditionally render the component)
 * - Attribute directives (applied to the component root element)
 * - Component bindings (`s:bind` props)
 * - Plain HTML attributes to merge into the root element
 */
final class ComponentAttributeCategorizer
{
    private DirectiveClassifier $directiveClassifier;

    /**
     * @param \Sugar\Extension\DirectiveRegistryInterface $registry Directive registry
     * @param \Sugar\Config\Helper\DirectivePrefixHelper $prefixHelper Directive prefix helper
     */
    public function __construct(
        DirectiveRegistryInterface $registry,
        private readonly DirectivePrefixHelper $prefixHelper,
    ) {
        $this->directiveClassifier = new DirectiveClassifier($registry, $prefixHelper);
    }

    /**
     * @param array<\Sugar\Ast\AttributeNode> $attributes
     */
    public function categorize(array $attributes): ComponentAttributeCategories
    {
        $controlFlow = [];
        $attributeDirectives = [];
        $componentBindings = null;
        $mergeAttrs = [];

        foreach ($attributes as $attr) {
            $name = $attr->name;

            if ($this->prefixHelper->isDirective($name)) {
                $directiveName = $this->prefixHelper->stripPrefix($name);

                if ($directiveName === 'bind') {
                    $componentBindings = $attr;
                } elseif ($this->directiveClassifier->isControlFlowDirectiveAttribute($name)) {
                    $controlFlow[] = $attr;
                } else {
                    $attributeDirectives[] = $attr;
                }
            } else {
                $mergeAttrs[] = $attr;
            }
        }

        return new ComponentAttributeCategories(
            controlFlow: $controlFlow,
            attributeDirectives: $attributeDirectives,
            componentBindings: $componentBindings,
            merge: $mergeAttrs,
        );
    }
}
