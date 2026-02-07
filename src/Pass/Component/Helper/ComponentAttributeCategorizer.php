<?php
declare(strict_types=1);

namespace Sugar\Pass\Component\Helper;

use Sugar\Ast\Helper\DirectivePrefixHelper;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\DirectiveRegistryInterface;

/**
 * Groups component attributes into control flow, directives, bindings, and merges.
 */
final class ComponentAttributeCategorizer
{
    /**
     * @param \Sugar\Extension\DirectiveRegistryInterface $registry Directive registry
     * @param \Sugar\Ast\Helper\DirectivePrefixHelper $prefixHelper Directive prefix helper
     */
    public function __construct(
        private readonly DirectiveRegistryInterface $registry,
        private readonly DirectivePrefixHelper $prefixHelper,
    ) {
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
                } elseif ($this->isControlFlowDirective($name)) {
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

    /**
     * Determine whether a directive name maps to a control flow compiler.
     */
    private function isControlFlowDirective(string $directiveName): bool
    {
        $name = $this->prefixHelper->stripPrefix($directiveName);

        if (!$this->registry->has($name)) {
            return false;
        }

        $compiler = $this->registry->get($name);

        return $compiler->getType() === DirectiveType::CONTROL_FLOW;
    }
}
