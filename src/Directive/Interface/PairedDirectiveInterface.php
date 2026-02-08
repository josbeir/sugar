<?php
declare(strict_types=1);

namespace Sugar\Directive\Interface;

/**
 * Interface for directive compilers that require sibling pairing
 *
 * Some directives need to pair with a following sibling element to provide
 * alternative content or fallback behavior. This interface extends the base
 * DirectiveInterface to add pairing metadata.
 *
 * Examples:
 * - forelse pairs with 'empty' - loop with empty fallback
 * - if pairs with 'elseif' and 'else' - conditional with alternatives
 * - switch could pair with 'case' - switch with case branches
 *
 * During pairing, the DirectivePairingPass will detect paired directives
 * and set the pairedSibling property linking them together.
 */
interface PairedDirectiveInterface extends DirectiveInterface
{
    /**
     * Get the name of the sibling directive that should be paired with this one
     *
     * @return string The name of the pairing directive (without prefix)
     */
    public function getPairingDirective(): string;
}
