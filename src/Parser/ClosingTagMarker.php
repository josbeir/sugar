<?php
declare(strict_types=1);

namespace Sugar\Parser;

/**
 * Temporary marker for closing HTML tags during parsing
 *
 * Used internally by Parser to track element nesting. Not part of final AST.
 */
final readonly class ClosingTagMarker
{
    /**
     * @param string $tagName The name of the closing tag (e.g., 'div' from </div>)
     */
    public function __construct(
        public string $tagName,
    ) {
    }
}
