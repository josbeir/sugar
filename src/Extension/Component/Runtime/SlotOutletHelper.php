<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Runtime;

use Sugar\Core\Escape\Escaper;
use Sugar\Core\Runtime\HtmlAttributeHelper;

/**
 * Runtime helper for rendering slot outlet elements with tag swapping and attribute merging.
 *
 * When a component template declares an element with `s:slot`, the compiled output
 * delegates to this helper at runtime. It replaces the outlet tag with the caller's tag,
 * merges attributes (class values are concatenated, others are overridden), and wraps
 * the slot content in the resulting element.
 *
 * Example:
 * ```php
 * // Caller: <h3 s:slot="header" class="extra">Title</h3>
 * // Outlet: <h2 s:slot="header" class="card-title">Default</h2>
 * SlotOutletHelper::render('Title', ['tag' => 'h3', 'attrs' => ['class' => 'extra']], 'h2', ['class' => 'card-title'])
 * // Result: '<h3 class="card-title extra">Title</h3>'
 * ```
 */
final class SlotOutletHelper
{
    /**
     * Render a slot outlet element with tag swapping and attribute merging.
     *
     * When caller metadata is present, the caller's tag replaces the outlet tag
     * and attributes are merged. When metadata is null (fragment-based slots),
     * the outlet tag and attributes are preserved as-is.
     *
     * @param string $content Slot inner content (pre-rendered HTML string)
     * @param array{tag: string, attrs: array<string, string|null>}|null $meta Caller element metadata or null for fragment slots
     * @param string $outletTag The outlet element's tag name
     * @param array<string, string|null> $outletAttrs The outlet element's attributes
     * @return string Rendered HTML element
     */
    public static function render(
        string $content,
        ?array $meta,
        string $outletTag,
        array $outletAttrs,
    ): string {
        $tag = $meta['tag'] ?? $outletTag;
        $callerAttrs = $meta['attrs'] ?? [];

        $mergedAttrs = self::mergeAttributes($outletAttrs, $callerAttrs);
        $attrString = self::formatAttributes($mergedAttrs);

        return '<' . $tag . $attrString . '>' . $content . '</' . $tag . '>';
    }

    /**
     * Merge outlet and caller attributes for slot rendering.
     *
     * Class attributes are concatenated (outlet first, then caller). Other same-name
     * attributes are overridden by the caller. Different-name attributes from both
     * sources are included.
     *
     * @param array<string, string|null> $outletAttrs Outlet element attributes
     * @param array<string, string|null> $callerAttrs Caller element attributes
     * @return array<string, string|null> Merged attributes
     */
    protected static function mergeAttributes(array $outletAttrs, array $callerAttrs): array
    {
        $merged = $outletAttrs;

        foreach ($callerAttrs as $name => $value) {
            if ($name === 'class' && isset($merged['class'])) {
                $merged['class'] = HtmlAttributeHelper::mergeClassValues($merged['class'], $value);
                continue;
            }

            $merged[$name] = $value;
        }

        return $merged;
    }

    /**
     * Build an HTML attribute string from an associative array.
     *
     * Boolean attributes (null value) render as name-only. String values
     * are properly escaped. Returns a space-prefixed string or empty string.
     *
     * @param array<string, string|null> $attributes Attributes to render
     * @return string Space-prefixed attribute string or empty string
     */
    protected static function formatAttributes(array $attributes): string
    {
        $parts = [];

        foreach ($attributes as $name => $value) {
            if ($value === null) {
                $parts[] = Escaper::attr($name);
                continue;
            }

            $parts[] = sprintf('%s="%s"', Escaper::attr($name), Escaper::attr($value));
        }

        if ($parts === []) {
            return '';
        }

        return ' ' . implode(' ', $parts);
    }
}
