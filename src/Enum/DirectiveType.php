<?php
declare(strict_types=1);

namespace Sugar\Enum;

/**
 * Directive type classification
 *
 * Directives are categorized by how they interact with elements:
 *
 * - CONTROL_FLOW: Wraps element in control structures (if, foreach, while, etc.)
 *   Only ONE control flow directive per element.
 *
 * - ATTRIBUTE: Modifies element attributes (class, spread)
 *   Can combine with control flow directives.
 *
 * - CONTENT: Injects content into element children (text, html)
 *   Can combine with control flow directives.
 */
enum DirectiveType
{
    /**
     * Control flow directive - wraps element (if, foreach, while, switch, unless, isset, empty)
     */
    case CONTROL_FLOW;

    /**
     * Attribute directive - modifies element attributes (class, spread)
     */
    case ATTRIBUTE;

    /**
     * Content directive - injects content into children (text, html)
     */
    case CONTENT;
}
