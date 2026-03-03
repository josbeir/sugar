<?php
declare(strict_types=1);

namespace Sugar\Core\Util;

/**
 * Stateless utility for whitespace normalization used by both the compile-time
 * AST pass and the runtime-generated wrappers emitted by InheritanceCompilationPass.
 *
 * Having one shared implementation ensures that `s:trim` behaves identically
 * whether whitespace is stripped from static text nodes in the same template
 * (compile time) or from the rendered output of a cross-template boundary such
 * as `s:include` or `s:block` (runtime).
 *
 * Example:
 * ```php
 * WhitespaceNormalizer::collapseSequences("  hello\n  world  "); // " hello world "
 * WhitespaceNormalizer::normalize("  hello\n  world  ");         // "hello world"
 * ```
 */
final class WhitespaceNormalizer
{
    /**
     * Collapse any run of whitespace characters (space, tab, CR, LF) into
     * a single space. Leading/trailing whitespace is collapsed to a single space
     * but not removed; call normalize() if trimming is also needed.
     *
     * Used on individual text-node content during AST normalization so that
     * indentation, line-breaks, and runs of spaces inside a trimmed element
     * become single spaces.
     *
     * @param string $text Raw text content
     * @return string Text with collapsed whitespace sequences
     */
    public static function collapseSequences(string $text): string
    {
        return preg_replace('/[ \t\r\n]+/u', ' ', $text) ?? $text;
    }

    /**
     * Collapse whitespace sequences in a fully rendered HTML string and
     * then strip any remaining leading or trailing whitespace.
     *
     * Used at runtime to normalize the output of `s:include` and `s:block`
     * calls that appear inside an element marked with `s:trim`.
     *
     * @param string $html Rendered HTML string
     * @return string Whitespace-normalised and trimmed HTML string
     */
    public static function normalize(string $html): string
    {
        return trim(self::collapseSequences($html));
    }
}
