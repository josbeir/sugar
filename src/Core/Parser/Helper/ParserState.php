<?php
declare(strict_types=1);

namespace Sugar\Core\Parser\Helper;

use Sugar\Core\Ast\Node;
use Sugar\Core\Parser\Token;

/**
 * Parsing state container for the template parser.
 */
final class ParserState
{
    /**
     * @var array<\Sugar\Core\Ast\Node|\Sugar\Core\Parser\Helper\ClosingTagMarker>
     */
    private array $nodes = [];

    /**
     * @var array{element: \Sugar\Core\Ast\ElementNode, attrIndex: int, quote: string|null}|null
     */
    private ?array $pendingAttribute = null;

    /**
     * @param \Sugar\Core\Parser\Helper\TokenStream $stream Token stream
     * @param string $source Template source
     */
    public function __construct(
        public readonly TokenStream $stream,
        public readonly string $source,
    ) {
    }

    /**
     * @return array<\Sugar\Core\Ast\Node|\Sugar\Core\Parser\Helper\ClosingTagMarker>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * Add a node to the state.
     */
    public function addNode(Node|ClosingTagMarker $node): void
    {
        $this->nodes[] = $node;
    }

    /**
     * @param array<\Sugar\Core\Ast\Node|\Sugar\Core\Parser\Helper\ClosingTagMarker> $nodes
     */
    public function addNodes(array $nodes): void
    {
        $this->nodes = array_merge($this->nodes, $nodes);
    }

    /**
     * @return array{element: \Sugar\Core\Ast\ElementNode, attrIndex: int, quote: string|null}|null
     */
    public function pendingAttribute(): ?array
    {
        return $this->pendingAttribute;
    }

    /**
     * @param array{element: \Sugar\Core\Ast\ElementNode, attrIndex: int, quote: string|null}|null $pendingAttribute
     */
    public function setPendingAttribute(?array $pendingAttribute): void
    {
        $this->pendingAttribute = $pendingAttribute;
    }

    /**
     * Check if a pending attribute is tracked.
     */
    public function hasPendingAttribute(): bool
    {
        return $this->pendingAttribute !== null;
    }

    /**
     * Get the stream index.
     */
    public function streamIndex(): int
    {
        return $this->stream->index();
    }

    /**
     * Peek at the current token without advancing.
     */
    public function currentToken(): ?Token
    {
        return $this->stream->peek();
    }

    /**
     * Normalize output expression to support trailing semicolons.
     */
    public function normalizeOutputExpression(string $expression): string
    {
        $expression = trim($expression);
        if ($expression !== '' && str_ends_with($expression, ';')) {
            return rtrim($expression, " \t\n\r\0\x0B;");
        }

        return $expression;
    }

    /**
     * Convert an absolute offset into a 1-based column index.
     */
    public function columnFromOffset(int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        $before = substr($this->source, 0, $offset);
        $lastNewline = strrpos($before, "\n");
        if ($lastNewline === false) {
            return $offset + 1;
        }

        return $offset - $lastNewline;
    }

    /**
     * Consume a PHP expression until the close tag.
     */
    public function consumeExpression(): string
    {
        return trim($this->stream->consumeUntilCloseTag());
    }

    /**
     * Consume a PHP code block until the close tag.
     */
    public function consumePhpBlock(): string
    {
        return trim($this->stream->consumeUntilCloseTag());
    }
}
