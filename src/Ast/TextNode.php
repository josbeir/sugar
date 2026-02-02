<?php
declare(strict_types=1);

namespace Sugar\Ast;

/**
 * Static text content (no interpolation needed)
 */
final class TextNode extends Node
{
    /**
     * Constructor
     *
     * @param string $content Text content
     * @param int $line Line number
     * @param int $column Column number
     */
    public function __construct(
        public string $content,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }
}
