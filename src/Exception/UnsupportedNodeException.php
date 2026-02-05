<?php
declare(strict_types=1);

namespace Sugar\Exception;

/**
 * Exception thrown when an unsupported AST node type is encountered during code generation
 *
 * This typically indicates an internal error where a new node type was added to the AST
 * but the code generator wasn't updated to handle it.
 */
final class UnsupportedNodeException extends CompilationException
{
    protected string $defaultMessage = 'Unsupported AST node type encountered during code generation';

    /**
     * Create exception for an unsupported node type
     *
     * @param string $nodeClass The fully qualified class name of the unsupported node
     * @param string|null $templatePath Path to template file
     * @param int|null $templateLine Line number in template
     * @param int|null $templateColumn Column number in template
     * @param string|null $snippet Code snippet showing error context
     */
    public static function forNodeType(
        string $nodeClass,
        ?string $templatePath = null,
        ?int $templateLine = null,
        ?int $templateColumn = null,
        ?string $snippet = null,
    ): self {
        return new self(
            message: sprintf('Unsupported node type: %s', $nodeClass),
            templatePath: $templatePath,
            templateLine: $templateLine,
            templateColumn: $templateColumn,
            snippet: $snippet,
        );
    }
}
