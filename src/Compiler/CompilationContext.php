<?php
declare(strict_types=1);

namespace Sugar\Compiler;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Node;
use Sugar\Cache\DependencyTracker;
use Sugar\Exception\SugarException;
use Sugar\Exception\SyntaxException;

/**
 * Compilation context holding template metadata for error reporting
 *
 * Provides template path, source code, and utilities for creating
 * exceptions with location information.
 */
final class CompilationContext
{
    /**
     * Constructor
     *
     * @param string $templatePath Path to the template file
     * @param string $source Template source code
     * @param bool $debug Enable debug mode
     * @param \Sugar\Cache\DependencyTracker|null $tracker Dependency tracker for cache invalidation
     * @param array<string>|null $blocks Restrict output to these block names
     */
    public function __construct(
        public readonly string $templatePath,
        public readonly string $source,
        public readonly bool $debug = false,
        public readonly ?DependencyTracker $tracker = null,
        public readonly ?array $blocks = null,
    ) {
    }

    /**
     * Stamp a template path onto all nodes and attributes in a document.
     */
    public function stampTemplatePath(DocumentNode $document, ?string $templatePath = null): void
    {
        $path = $templatePath ?? $this->templatePath;
        $this->stampNode($document, $path);
    }

    /**
     * Create an exception with template location metadata
     *
     * @param class-string<\Sugar\Exception\SugarException> $exceptionClass Exception class to instantiate
     * @param string $message Error message
     * @param int|null $line Line number in template
     * @param int|null $column Column number in template
     * @return \Sugar\Exception\SugarException The created exception
     */
    public function createException(
        string $exceptionClass,
        string $message,
        ?int $line = null,
        ?int $column = null,
    ): SugarException {
        return $this->createExceptionWithPath(
            exceptionClass: $exceptionClass,
            message: $message,
            templatePath: $this->templatePath,
            line: $line,
            column: $column,
        );
    }

    /**
     * Create a syntax exception with template location metadata.
     */
    public function createSyntaxException(
        string $message,
        ?int $line = null,
        ?int $column = null,
    ): SyntaxException {
        $exception = $this->createExceptionWithPath(
            exceptionClass: SyntaxException::class,
            message: $message,
            templatePath: $this->templatePath,
            line: $line,
            column: $column,
        );

        /** @var \Sugar\Exception\SyntaxException $exception */
        return $exception;
    }

    /**
     * Create an exception using node origin metadata when available.
     *
     * @param class-string<\Sugar\Exception\SugarException> $exceptionClass Exception class to instantiate
     * @param string $message Error message
     * @param \Sugar\Ast\Node $node Node providing location metadata
     * @param int|null $line Line number override
     * @param int|null $column Column number override
     * @return \Sugar\Exception\SugarException The created exception
     */
    public function createExceptionForNode(
        string $exceptionClass,
        string $message,
        Node $node,
        ?int $line = null,
        ?int $column = null,
    ): SugarException {
        return $this->createExceptionWithPath(
            exceptionClass: $exceptionClass,
            message: $message,
            templatePath: $node->getTemplatePath() ?? $this->templatePath,
            line: $line ?? $node->line,
            column: $column ?? $node->column,
        );
    }

    /**
     * Create a syntax exception using node origin metadata when available.
     */
    public function createSyntaxExceptionForNode(
        string $message,
        Node $node,
        ?int $line = null,
        ?int $column = null,
    ): SyntaxException {
        $exception = $this->createExceptionWithPath(
            exceptionClass: SyntaxException::class,
            message: $message,
            templatePath: $node->getTemplatePath() ?? $this->templatePath,
            line: $line ?? $node->line,
            column: $column ?? $node->column,
        );

        /** @var \Sugar\Exception\SyntaxException $exception */
        return $exception;
    }

    /**
     * Create an exception using attribute origin metadata when available.
     *
     * @param class-string<\Sugar\Exception\SugarException> $exceptionClass Exception class to instantiate
     * @param string $message Error message
     * @param \Sugar\Ast\AttributeNode $attribute Attribute providing location metadata
     * @param int|null $line Line number override
     * @param int|null $column Column number override
     * @return \Sugar\Exception\SugarException The created exception
     */
    public function createExceptionForAttribute(
        string $exceptionClass,
        string $message,
        AttributeNode $attribute,
        ?int $line = null,
        ?int $column = null,
    ): SugarException {
        return $this->createExceptionWithPath(
            exceptionClass: $exceptionClass,
            message: $message,
            templatePath: $attribute->getTemplatePath() ?? $this->templatePath,
            line: $line ?? $attribute->line,
            column: $column ?? $attribute->column,
        );
    }

    /**
     * Create a syntax exception using attribute origin metadata when available.
     */
    public function createSyntaxExceptionForAttribute(
        string $message,
        AttributeNode $attribute,
        ?int $line = null,
        ?int $column = null,
    ): SyntaxException {
        $exception = $this->createExceptionWithPath(
            exceptionClass: SyntaxException::class,
            message: $message,
            templatePath: $attribute->getTemplatePath() ?? $this->templatePath,
            line: $line ?? $attribute->line,
            column: $column ?? $attribute->column,
        );

        /** @var \Sugar\Exception\SyntaxException $exception */
        return $exception;
    }

    /**
     * Create an exception with explicit template metadata.
     *
     * @param class-string<\Sugar\Exception\SugarException> $exceptionClass Exception class to instantiate
     * @param string $message Error message
     * @param string $templatePath Template path for location metadata
     * @param int|null $line Line number in template
     * @param int|null $column Column number in template
     * @return \Sugar\Exception\SugarException The created exception
     */
    private function createExceptionWithPath(
        string $exceptionClass,
        string $message,
        string $templatePath,
        ?int $line = null,
        ?int $column = null,
    ): SugarException {
        return new $exceptionClass(
            message: $message,
            templatePath: $templatePath,
            templateLine: $line,
            templateColumn: $column,
        );
    }

    /**
     * Recursively stamp template path onto a node tree.
     */
    private function stampNode(Node $node, string $templatePath): void
    {
        $node->setTemplatePath($templatePath);

        if ($node instanceof DocumentNode) {
            foreach ($node->children as $child) {
                $this->stampNode($child, $templatePath);
            }

            return;
        }

        if ($node instanceof ElementNode || $node instanceof FragmentNode || $node instanceof ComponentNode) {
            foreach ($node->attributes as $attribute) {
                $attribute->setTemplatePath($templatePath);
            }

            foreach ($node->children as $child) {
                $this->stampNode($child, $templatePath);
            }

            return;
        }

        if ($node instanceof DirectiveNode) {
            foreach ($node->children as $child) {
                $this->stampNode($child, $templatePath);
            }
        }
    }
}
