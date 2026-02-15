<?php
declare(strict_types=1);

namespace Sugar\Core\Parser\Helper;

use Sugar\Core\Parser\Token;

/**
 * Token stream wrapper for sequential parsing.
 *
 * Provides safe, index-based access to a list of tokens with lookahead support.
 */
final class TokenStream
{
    /**
     * @param array<\Sugar\Core\Parser\Token> $tokens
     */
    public function __construct(
        private array $tokens,
        private int $index = 0,
    ) {
    }

    /**
     * Get the current token index.
     */
    public function index(): int
    {
        return $this->index;
    }

    /**
     * Check if the stream is at the end.
     */
    public function isEnd(): bool
    {
        return $this->index >= count($this->tokens);
    }

    /**
     * Peek at a token without advancing the stream.
     */
    public function peek(int $offset = 0): ?Token
    {
        $index = $this->index + $offset;

        return $this->tokens[$index] ?? null;
    }

    /**
     * Read the next token and advance the stream.
     */
    public function next(): ?Token
    {
        if ($this->index >= count($this->tokens)) {
            return null;
        }

        return $this->tokens[$this->index++];
    }

    /**
     * Consume tokens until a condition is met.
     *
     * @param callable(\Sugar\Core\Parser\Token): bool $isTerminator Terminator predicate.
     * @param bool $consumeTerminator Whether to consume the matching token.
     */
    public function consumeUntil(callable $isTerminator, bool $consumeTerminator = true): string
    {
        $content = '';
        while (($token = $this->peek()) instanceof Token) {
            if ($isTerminator($token)) {
                if ($consumeTerminator) {
                    $this->next();
                }

                break;
            }

            $content .= $this->next()?->content();
        }

        return $content;
    }

    /**
     * Consume tokens until a PHP close tag is reached.
     */
    public function consumeUntilCloseTag(): string
    {
        return $this->consumeUntil(static fn(Token $token): bool => $token->isCloseTag());
    }
}
