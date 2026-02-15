<?php
declare(strict_types=1);

namespace Sugar\Core\Directive\Interface;

use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;

/**
 * Interface for directive compilers that require custom element extraction
 *
 * Some directives need special handling during the extraction phase before
 * compilation. This interface allows directives to customize how they are
 * extracted from elements.
 *
 * Examples:
 * - s:tag - needs to modify the element's tag name dynamically
 * - s:ifcontent - needs to wrap the entire element conditionally
 *
 * Directives implementing this interface take control of their extraction
 * behavior instead of following the default extraction logic.
 */
interface ElementAwareDirectiveInterface extends DirectiveInterface
{
    /**
     * Perform custom extraction from element
     *
     * This method is called during the extraction pass when the directive
     * is found on an element. It allows the directive to customize how
     * the element and directive node are created.
     *
     * @param \Sugar\Core\Ast\ElementNode $element The element with the directive
     * @param string $expression The directive expression value
     * @param array<\Sugar\Core\Ast\Node> $transformedChildren Already transformed children
     * @param array<\Sugar\Core\Ast\AttributeNode> $remainingAttrs Attributes without this directive
     * @return \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\DirectiveNode|\Sugar\Core\Ast\FragmentNode The transformed node
     */
    public function extractFromElement(
        ElementNode $element,
        string $expression,
        array $transformedChildren,
        array $remainingAttrs,
    ): ElementNode|DirectiveNode|FragmentNode;
}
