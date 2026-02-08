<?php
declare(strict_types=1);

namespace Sugar\Pass\Directive\Helper;

use Sugar\Ast\Helper\DirectivePrefixHelper;
use Sugar\Directive\Interface\ContentWrappingDirectiveInterface;
use Sugar\Enum\DirectiveType;
use Sugar\Extension\DirectiveRegistryInterface;

/**
 * Classifies directive attribute names by registry type.
 */
final readonly class DirectiveClassifier
{
    /**
     * @param \Sugar\Extension\DirectiveRegistryInterface $registry Directive registry
     * @param \Sugar\Ast\Helper\DirectivePrefixHelper $prefixHelper Directive prefix helper
     */
    public function __construct(
        private DirectiveRegistryInterface $registry,
        private DirectivePrefixHelper $prefixHelper,
    ) {
    }

    /**
     * Check whether an attribute name is a directive, optionally excluding inheritance directives.
     */
    public function isDirectiveAttribute(string $attributeName, bool $allowInheritanceAttributes = true): bool
    {
        return $this->prefixHelper->isDirective($attributeName)
            && ($allowInheritanceAttributes || !$this->prefixHelper->isInheritanceAttribute($attributeName));
    }

    /**
     * Check if a directive attribute should be treated as a non-pass-through directive.
     */
    public function isNonPassThroughDirectiveAttribute(
        string $attributeName,
        bool $allowInheritanceAttributes = true,
    ): bool {
        if (!$this->isDirectiveAttribute($attributeName, $allowInheritanceAttributes)) {
            return false;
        }

        $name = $this->prefixHelper->stripPrefix($attributeName);
        if (!$this->registry->has($name)) {
            return true;
        }

        $compiler = $this->registry->get($name);

        if ($compiler instanceof ContentWrappingDirectiveInterface) {
            return true;
        }

        return $compiler->getType() !== DirectiveType::PASS_THROUGH;
    }

    /**
     * Check if a directive attribute maps to a control flow compiler.
     */
    public function isControlFlowDirectiveAttribute(string $attributeName): bool
    {
        if (!$this->prefixHelper->isDirective($attributeName)) {
            return false;
        }

        $name = $this->prefixHelper->stripPrefix($attributeName);
        if (!$this->registry->has($name)) {
            return false;
        }

        $compiler = $this->registry->get($name);

        return $compiler->getType() === DirectiveType::CONTROL_FLOW;
    }
}
