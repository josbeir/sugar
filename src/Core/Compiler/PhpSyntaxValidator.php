<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler;

use PhpParser\Error;
use PhpParser\Parser as PhpAstParser;
use PhpParser\ParserFactory;
use Sugar\Core\Ast\ComponentNode;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\PhpImportNode;
use Sugar\Core\Ast\RawPhpNode;
use Throwable;

/**
 * Validates generated/template-local PHP syntax during compilation.
 *
 * This helper keeps parser-specific concerns out of the compiler orchestrator.
 */
final class PhpSyntaxValidator
{
    private ?PhpAstParser $cachedParser = null;

    private bool $parserInitialized = false;

    private readonly PhpImportExtractor $importExtractor;

    /**
     * Create syntax validator helper.
     */
    public function __construct()
    {
        $this->importExtractor = new PhpImportExtractor();
    }

    /**
     * Validate full generated PHP output.
     */
    public function generated(string $compiledCode, CompilationContext $context): void
    {
        if (!$context->debug) {
            return;
        }

        $parser = $this->parser();
        if (!$parser instanceof PhpAstParser) {
            return;
        }

        try {
            $parser->parse($compiledCode);
        } catch (Throwable $throwable) {
            if (!$throwable instanceof Error) {
                throw $throwable;
            }

            throw $context->createSyntaxException(
                message: sprintf('Generated PHP validation failed: %s', $throwable->getMessage()),
                line: $throwable->getStartLine(),
            );
        }
    }

    /**
     * Validate template-local PHP segments for precise diagnostics.
     */
    public function templateSegments(DocumentNode $document, CompilationContext $context): void
    {
        if (!$context->debug) {
            return;
        }

        $parser = $this->parser();
        if (!$parser instanceof PhpAstParser) {
            return;
        }

        $this->nodeSegments($document, $context, $parser);
    }

    /**
     * Create a nikic/php-parser instance when available.
     */
    private function parser(): ?PhpAstParser
    {
        if ($this->parserInitialized) {
            return $this->cachedParser;
        }

        $this->parserInitialized = true;

        if (!class_exists(ParserFactory::class) || !class_exists(Error::class)) {
            return null;
        }

        $parserFactory = new ParserFactory();

        $candidates = ['createForHostVersion', 'createForNewestSupportedVersion'];
        foreach ($candidates as $method) {
            if (!is_callable([$parserFactory, $method])) {
                continue;
            }

            $createdParser = $parserFactory->{$method}();
            $this->cachedParser = $createdParser;

            return $this->cachedParser;
        }

        return null;
    }

    /**
     * Recursively validate output expressions across supported AST nodes.
     *
     * @param \PhpParser\Parser $parser PHP parser instance from nikic/php-parser
     */
    private function nodeSegments(
        Node $node,
        CompilationContext $context,
        PhpAstParser $parser,
    ): void {
        if ($node instanceof OutputNode) {
            $this->expression($node, $context, $parser);

            return;
        }

        if ($node instanceof RawPhpNode) {
            $this->rawPhp($node, $context, $parser);

            return;
        }

        if ($node instanceof PhpImportNode) {
            $this->import($node, $context, $parser);

            return;
        }

        if (
            $node instanceof DocumentNode
            || $node instanceof ElementNode
            || $node instanceof FragmentNode
            || $node instanceof DirectiveNode
            || $node instanceof ComponentNode
        ) {
            if ($node instanceof ElementNode || $node instanceof FragmentNode || $node instanceof ComponentNode) {
                foreach ($node->attributes as $attribute) {
                    $parts = $attribute->value->toParts();
                    if ($parts === null) {
                        continue;
                    }

                    foreach ($parts as $part) {
                        if ($part instanceof OutputNode) {
                            $this->expression($part, $context, $parser);
                        }
                    }
                }
            }

            foreach ($node->children as $child) {
                $this->nodeSegments($child, $context, $parser);
            }
        }
    }

    /**
     * Validate a template output expression and throw node-local syntax errors.
     *
     * @param \PhpParser\Parser $parser PHP parser instance from nikic/php-parser
     */
    private function expression(
        OutputNode $node,
        CompilationContext $context,
        PhpAstParser $parser,
    ): void {
        try {
            $parser->parse("<?php\nreturn ({$node->expression});\n");
        } catch (Throwable $throwable) {
            if (!$throwable instanceof Error) {
                throw $throwable;
            }

            throw $context->createSyntaxExceptionForNode(
                message: sprintf('Invalid PHP expression: %s', $throwable->getMessage()),
                node: $node,
            );
        }
    }

    /**
     * Validate raw PHP template blocks as function-body code.
     *
     * Template raw PHP executes inside the compiled template closure, so this
     * validation wraps the snippet in a function to mirror runtime scope rules.
     *
     * @param \PhpParser\Parser $parser PHP parser instance from nikic/php-parser
     */
    private function rawPhp(
        RawPhpNode $node,
        CompilationContext $context,
        PhpAstParser $parser,
    ): void {
        [$validationCode, $lineOffset] = $this->prepareRawPhpValidationCode($node);
        if ($validationCode === '') {
            return;
        }

        try {
            $parser->parse(
                "<?php\nreturn static function (): void {\n{$validationCode}\n};\n",
            );
        } catch (Throwable $throwable) {
            if (!$throwable instanceof Error) {
                throw $throwable;
            }

            $errorLine = $throwable->getStartLine();
            $codeStartOffset = $this->detectCodeStartOffsetAtLine($context, $node->line);
            $templateLineOffset = $codeStartOffset + $lineOffset + max(0, $errorLine - 3);

            throw $context->createSyntaxExceptionForNode(
                message: sprintf('Invalid PHP block: %s', $throwable->getMessage()),
                node: $node,
                line: $node->line + $templateLineOffset,
            );
        }
    }

    /**
     * Validate a canonicalized import statement extracted from raw PHP.
     *
     * @param \PhpParser\Parser $parser PHP parser instance from nikic/php-parser
     */
    private function import(
        PhpImportNode $node,
        CompilationContext $context,
        PhpAstParser $parser,
    ): void {
        try {
            $parser->parse("<?php\n{$node->statement}\n");
        } catch (Throwable $throwable) {
            if (!$throwable instanceof Error) {
                throw $throwable;
            }

            $errorLine = $throwable->getStartLine();
            $codeStartOffset = $this->detectCodeStartOffsetAtLine($context, $node->line);
            $templateLineOffset = $codeStartOffset + max(0, $errorLine - 2);

            throw $context->createSyntaxExceptionForNode(
                message: sprintf('Invalid PHP import: %s', $throwable->getMessage()),
                node: $node,
                line: $node->line + $templateLineOffset,
            );
        }
    }

    /**
     * Prepare raw PHP code for parser validation.
     *
     * Mirrors raw PHP normalization behavior by extracting leading import
     * statements before validating function-body syntax.
     *
     * @return array{0: string, 1: int} Validation code and line offset from node start
     */
    private function prepareRawPhpValidationCode(RawPhpNode $node): array
    {
        $normalizedCode = $this->stripPhpTags($node->code);
        if ($normalizedCode === '') {
            return ['', 0];
        }

        $lineOffset = 0;
        if (str_contains($normalizedCode, 'use')) {
            $normalizedNode = new RawPhpNode($normalizedCode, $node->line, $node->column);
            [$importNodes, $remainingCode] = $this->importExtractor->extractImportNodes($normalizedNode);

            if ($importNodes !== []) {
                $trimmedRemainingCode = ltrim($remainingCode);
                if ($trimmedRemainingCode === '') {
                    return ['', 0];
                }

                $remainingPosition = strpos($normalizedCode, $trimmedRemainingCode);
                if ($remainingPosition !== false) {
                    $lineOffset = substr_count(substr($normalizedCode, 0, $remainingPosition), "\n");
                }

                $normalizedCode = trim($remainingCode);
            }
        }

        return [$normalizedCode, $lineOffset];
    }

    /**
     * Strip optional PHP open/close tags from raw snippet content.
     */
    private function stripPhpTags(string $code): string
    {
        $code = preg_replace('/^\s*<\?(?:php|=)?/i', '', $code) ?? $code;
        $code = preg_replace('/\?>\s*$/', '', $code) ?? $code;

        return trim($code);
    }

    /**
     * Detect if raw PHP code starts on the line after the opening tag.
     *
     * Parser-level raw block extraction trims leading whitespace/newlines from
     * block contents. When the opening tag is on its own line, the first code
     * line therefore maps to template line `line + 1`.
     */
    private function detectCodeStartOffsetAtLine(CompilationContext $context, int $line): int
    {
        $sourceLines = preg_split('/\R/', $context->source) ?: [];
        $lineIndex = $line - 1;

        if (!isset($sourceLines[$lineIndex])) {
            return 0;
        }

        $line = trim($sourceLines[$lineIndex]);
        if ($line === '<?php') {
            return 1;
        }

        return 0;
    }
}
