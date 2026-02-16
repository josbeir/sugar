<?php
declare(strict_types=1);

namespace Sugar\Core\Parser;

/**
 * Represents a single token emitted by the template lexer.
 *
 * Each token carries its type, textual value, and source location (line + column)
 * so that downstream consumers (parser, error reporting) can map back to source.
 *
 * Example:
 *   new Token(TokenType::Text, '<div class="box">', 1, 1)
 *   new Token(TokenType::PhpOutputOpen, '<?=', 1, 18)
 */
final readonly class Token
{
    /**
     * @param \Sugar\Core\Parser\TokenType $type  The kind of token
     * @param string    $value The raw textual content of the token
     * @param int       $line  1-based source line number
     * @param int       $column 1-based source column number
     */
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $line,
        public int $column,
    ) {
    }
}
