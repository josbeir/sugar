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
     * Validate template-local output expressions for precise diagnostics.
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
}
