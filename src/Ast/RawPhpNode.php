<?php
declare(strict_types=1);

namespace Sugar\Ast;

/**
 * Raw PHP code block (<?php ... ?>)
 * Passed through as-is to generated output
 */
final class RawPhpNode extends Node
{
    /**
     * Constructor
     *
     * @param string $code PHP code (without <?php and ?> tags)
     * @param int $line Line number
     * @param int $column Column number
     */
    public function __construct(
        public string $code,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }
}
