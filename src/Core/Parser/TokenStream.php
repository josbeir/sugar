<?php
declare(strict_types=1);

namespace Sugar\Core\Parser;

/**
 * Sequential read-only stream over a flat token array.
 *
 * Provides peek/consume semantics for the recursive-descent parser.
 * Once constructed, the stream is immutable—only the cursor advances.
 *
 * Example:
 *   $stream = new TokenStream($tokens);
 *   while (!$stream->isEof()) {
 *       $token = $stream->consume();
 *       // ...
 *   }
 */
final class TokenStream
{
    private int $cursor = 0;

    private int $count;

    /**
     * @param array<\Sugar\Core\Parser\Token> $tokens Flat token array (must end with Eof)
     */
    public function __construct(
        private readonly array $tokens,
    ) {
        $this->count = count($tokens);
    }

    /**
     * Return the current token without advancing.
     */
    public function current(): Token
    {
        return $this->tokens[$this->cursor];
    }

    /**
     * Return the current token and advance the cursor.
     */
    public function consume(): Token
    {
        $token = $this->tokens[$this->cursor];
        if ($this->cursor < $this->count - 1) {
            $this->cursor++;
        }

        return $token;
    }

    /**
     * If the current token matches `$type`, consume and return it; otherwise return null.
     */
    public function consumeIf(TokenType $type): ?Token
    {
        if ($this->current()->type === $type) {
            return $this->consume();
        }

        return null;
    }

    /**
     * Peek at the next token without consuming the current one.
     */
    public function peek(): Token
    {
        $next = $this->cursor + 1;

        return $next < $this->count ? $this->tokens[$next] : $this->tokens[$this->count - 1];
    }

    /**
     * Check if the stream is at the end-of-file token.
     */
    public function isEof(): bool
    {
        return $this->current()->type === TokenType::Eof;
    }
}
