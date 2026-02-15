<?php
declare(strict_types=1);

namespace Sugar\Core\Enum;

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
 *
 * - PASS_THROUGH: Special attributes that use directive syntax but are handled by other passes.
 *   Not compiled as directives, passed through to downstream passes (e.g., slot for components).
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

    /**
     * Pass-through attribute - uses directive syntax but handled by other passes (slot)
     * These are registered in the directive system but not compiled by DirectiveCompilationPass
     */
    case PASS_THROUGH;
}
