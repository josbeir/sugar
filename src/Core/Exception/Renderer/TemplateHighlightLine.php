<?php
declare(strict_types=1);

namespace Sugar\Core\Exception\Renderer;

/**
 * Represents a formatted template line for error rendering.
 */
final readonly class TemplateHighlightLine
{
    /**
     * @param string $text Formatted line text
     * @param bool $isErrorLine True when this line matches the error line
     * @param bool $isCaretLine True when this line is the caret indicator
     */
    public function __construct(
        public string $text,
        public bool $isErrorLine = false,
        public bool $isCaretLine = false,
    ) {
    }
}
