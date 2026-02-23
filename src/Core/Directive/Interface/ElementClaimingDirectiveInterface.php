<?php
declare(strict_types=1);

namespace Sugar\Core\Directive\Interface;

/**
 * Marks a directive as also claimable as a custom element tag.
 *
 * When a directive implements this interface it can be invoked via two equivalent syntaxes:
 *
 * **Directive syntax** (attribute on any element or fragment):
 * ```html
 * <div s:youtube="$videoId">...</div>
 * ```
 *
 * **Element syntax** (dedicated custom tag):
 * ```html
 * <s-youtube src="$videoId">...</s-youtube>
 * ```
 *
 * Both syntaxes produce an identical DirectiveNode that is passed to `compile()`, so
 * the directive implementation needs no special-casing.
 *
 * The `ElementRoutingPass` (priority 15, before DirectiveExtractionPass at priority 20)
 * intercepts any `ComponentNode` whose name matches a registered directive that implements
 * this interface, converts it into a `DirectiveNode`, and forwards the remaining attributes
 * (including any `s:if`, `s:foreach`, `s:class`, etc.) to a `FragmentNode` so that
 * `DirectiveExtractionPass` can process them transparently.
 *
 * ### Example
 *
 * ```php
 * final class YoutubeDirective implements DirectiveInterface, ElementClaimingDirectiveInterface
 * {
 *     public function getElementExpressionAttribute(): ?string
 *     {
 *         return 'src';   // <s-youtube src="$videoId">
 *     }
 *
 *     public function getType(): DirectiveType
 *     {
 *         return DirectiveType::OUTPUT;
 *     }
 *
 *     public function compile(Node $node, CompilationContext $context): array { ... }
 * }
 * ```
 *
 * ### Directive-less elements
 *
 * Directives that take no expression (e.g. a marker/wrapper directive) should return `null`.
 * The resulting `DirectiveNode` will have an empty string expression.
 *
 * ```php
 * public function getElementExpressionAttribute(): ?string
 * {
 *     return null;  // <s-nobr> — no expression needed
 * }
 * ```
 */
interface ElementClaimingDirectiveInterface extends DirectiveInterface
{
    /**
     * Return the attribute name on the custom element that carries the directive expression.
     *
     * When a template uses the element syntax `<s-NAME attr="expression">`, this tells
     * `ElementRoutingPass` which attribute value to use as the `DirectiveNode` expression.
     *
     * Return `null` when the directive takes no expression (the `DirectiveNode` expression
     * will be an empty string, matching what a boolean/valueless directive attribute produces).
     *
     * @return string|null Attribute name (e.g. `'src'`, `'key'`) or null for expression-less directives
     */
    public function getElementExpressionAttribute(): ?string;
}
