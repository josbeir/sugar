<?php
declare(strict_types=1);

namespace Sugar\Ast;

/**
 * Verbatim raw body content preserved from s:raw regions.
 */
final class RawBodyNode extends Node
{
    /**
     * @param string $content Raw, unparsed body content
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
