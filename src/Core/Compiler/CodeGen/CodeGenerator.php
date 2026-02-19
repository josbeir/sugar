<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler\CodeGen;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\PhpImportNode;
use Sugar\Core\Ast\RawBodyNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\RuntimeCallNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\PhpImportRegistry;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Exception\UnsupportedNodeException;

/**
 * Generates PHP code from AST with inline escaping
 */
final class CodeGenerator
{
    private const RUNTIME_ENV_ALIAS = '__SugarRuntimeEnvironment';

    private const TEMPLATE_RENDERER_ALIAS = '__SugarTemplateRenderer';

    private const COMPONENT_RENDERER_ALIAS = '__SugarComponentRenderer';

    private const ESCAPER_ALIAS = '__SugarEscaper';

    /**
     * Constructor
     *
     * @param \Sugar\Core\Escape\Escaper $escaper Escaper instance
     * @param \Sugar\Core\Compiler\CompilationContext $context Compilation context with debug info and template path
     */
    public function __construct(
        private readonly Escaper $escaper,
        private readonly CompilationContext $context,
    ) {
    }

    /**
     * Generate PHP code from AST
     *
     * @param \Sugar\Core\Ast\DocumentNode $ast Document AST
     * @return string Generated PHP code
     */
    public function generate(DocumentNode $ast): string
    {
        $buffer = new OutputBuffer();

        // Prelude
        $buffer->writeln('<?php');
        $buffer->writeln('declare(strict_types=1);');
        $buffer->writeln('// phpcs:ignoreFile');
        $buffer->writeln('');
        $buffer->writeln('/**');
        $buffer->writeln(' * Compiled Sugar template');
        $buffer->writeln(' *');
        $buffer->writeln(' * @link https://github.com/josbeir/sugar');

        $hasRealPath = $this->context->templatePath !== ''
            && $this->context->templatePath !== 'inline-template';

        if ($this->context->debug && $hasRealPath) {
            $buffer->writeln(' * Source: ' . $this->context->templatePath);
            $buffer->writeln(' * Compiled: ' . date('Y-m-d H:i:s'));
            $buffer->writeln(' * Debug mode: enabled');
        } else {
            $buffer->writeln(' * DO NOT EDIT - auto-generated');
        }

        $buffer->writeln(' */');

        $registry = new PhpImportRegistry();
        $registry->add('use Sugar\\Core\\Runtime\\RuntimeEnvironment as ' . self::RUNTIME_ENV_ALIAS . ';');
        $registry->add('use Sugar\\Core\\Runtime\\TemplateRenderer as ' . self::TEMPLATE_RENDERER_ALIAS . ';');
        $registry->add('use Sugar\\Core\\Escape\\Escaper as ' . self::ESCAPER_ALIAS . ';');
        $registry->add(
            'use Sugar\\Extension\\Component\\Runtime\\ComponentRenderer as '
            . self::COMPONENT_RENDERER_ALIAS
            . ';',
        );
        foreach ($this->collectImportNodes($ast) as $importNode) {
            $registry->add($importNode->statement);
        }

        $imports = $registry->all();
        if ($imports !== []) {
            foreach ($imports as $import) {
                $buffer->writeln($import);
            }

            $buffer->writeln('');
        }

        $buffer->writeln('');
        $buffer->writeln('return function(array|object $__data = []): string {');
        $buffer->writeln('    ob_start();');
        $buffer->writeln('    try {');
        $buffer->writeln('        extract((array)$__data, EXTR_SKIP);');
        $buffer->write('        ?>');

        // Generate code for each node
        foreach ($ast->children as $node) {
            $this->generateNode($node, $buffer);
        }

        $buffer->write('<?php');
        $buffer->writeln('');
        $buffer->writeln('        return ob_get_clean();');
        $buffer->writeln('    } catch (\Throwable $__e) {');
        $buffer->writeln('        ob_end_clean();');
        $buffer->writeln('        throw $__e;');
        $buffer->writeln('    }');
        $buffer->writeln('};');

        return $buffer->getContent();
    }

    /**
     * Generate code for a single node
     *
     * @param \Sugar\Core\Ast\Node $node AST node
     * @param \Sugar\Core\Compiler\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateNode(Node $node, OutputBuffer $buffer): void
    {
        match ($node::class) {
            TextNode::class => $this->generateText($node, $buffer),
            RawBodyNode::class => $this->generateRawBody($node, $buffer),
            OutputNode::class => $this->generateOutput($node, $buffer),
            RawPhpNode::class => $this->generateRawPhp($node, $buffer),
            PhpImportNode::class => null, // already hoisted to file scope; skip in body
            ElementNode::class => $this->generateElement($node, $buffer),
            FragmentNode::class => $this->generateFragment($node, $buffer),
            DirectiveNode::class => $this->generateDirective($node, $buffer),
            RuntimeCallNode::class => $this->generateRuntimeCall($node, $buffer),
            default => throw UnsupportedNodeException::forNodeType(
                $node::class,
                $this->context->templatePath,
                $node->line,
                $node->column,
            ),
        };
    }

    /**
     * Generate static text output
     *
     * @param \Sugar\Core\Ast\TextNode $node Text node
     * @param \Sugar\Core\Compiler\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateText(TextNode $node, OutputBuffer $buffer): void
    {
        if (str_contains($node->content, '<?')) {
            $buffer->write(sprintf('<?php echo %s; ?>', var_export($node->content, true)));

            return;
        }

        $buffer->write($node->content);
    }

    /**
     * Generate verbatim raw-body output.
     *
     * @param \Sugar\Core\Ast\RawBodyNode $node Raw body node
     * @param \Sugar\Core\Compiler\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateRawBody(RawBodyNode $node, OutputBuffer $buffer): void
    {
        $buffer->write(sprintf('<?php echo %s; ?>', var_export($node->content, true)));
    }

    /**
     * Generate dynamic output with inline escaping
     *
     * @param \Sugar\Core\Ast\OutputNode $node Output node
     * @param \Sugar\Core\Compiler\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateOutput(OutputNode $node, OutputBuffer $buffer): void
    {
        // Compile pipe chain if present
        $expression = $node->expression;
        if ($node->pipes !== null) {
            $expression = $this->compilePipes($expression, $node->pipes);
        }

        if ($node->escape) {
            // Generate inline escaping code (compile-time optimization)
            $escapedCode = $this->escaper->generateEscapeCode($expression, $node->context);
            $escapedCode = $this->normalizeEscaperReference($escapedCode);
            $buffer->write(sprintf('<?php echo %s; ?>', $escapedCode));
        } else {
            // Raw output
            $buffer->write(sprintf('<?php echo %s; ?>', $expression));
        }
    }

    /**
     * Generate raw PHP code pass-through
     *
     * @param \Sugar\Core\Ast\RawPhpNode $node Raw PHP node
     * @param \Sugar\Core\Compiler\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateRawPhp(RawPhpNode $node, OutputBuffer $buffer): void
    {
        // Pass through trimmed code (removes excess whitespace from token parsing)
        $trimmedCode = trim($node->code);

        $buffer->write(sprintf('<?php %s ?>', $trimmedCode));
    }

    /**
     * Generate HTML element output
     *
     * @param \Sugar\Core\Ast\ElementNode $node Element node
     * @param \Sugar\Core\Compiler\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateElement(ElementNode $node, OutputBuffer $buffer): void
    {
        // Opening tag
        if ($node->dynamicTag !== null) {
            // Dynamic tag name (from s:tag directive)
            $buffer->write('<');
            $buffer->write('<?= ' . $node->dynamicTag . ' ?>');
        } else {
            // Static tag name
            $buffer->write('<' . $node->tag);
        }

        // Attributes
        foreach ($node->attributes as $attribute) {
            $this->generateAttribute($attribute, $buffer);
        }

        // Clean up trailing spaces before closing tag
        // Handles cases where conditional attributes output nothing
        $content = $buffer->getContent();
        if (str_ends_with($content, ' ')) {
            $cleaned = rtrim($content);
            $buffer->clear();
            $buffer->write($cleaned);
        }

        // Close opening tag
        if ($node->selfClosing) {
            $buffer->write(' />');
        } else {
            $buffer->write('>');

            // Children
            foreach ($node->children as $child) {
                $this->generateNode($child, $buffer);
            }

            // Closing tag
            if ($node->dynamicTag !== null) {
                // Dynamic closing tag
                $buffer->write('</');
                $buffer->write('<?= ' . $node->dynamicTag . ' ?>');
                $buffer->write('>');
            } else {
                // Static closing tag
                $buffer->write('</' . $node->tag . '>');
            }
        }
    }

    /**
     * Generate attribute output
     *
     * @param \Sugar\Core\Ast\AttributeNode $attribute Attribute node
     * @param \Sugar\Core\Compiler\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateAttribute(AttributeNode $attribute, OutputBuffer $buffer): void
    {
        // Special case: empty name means this is a spread directive output
        // Output it directly without name= wrapper (e.g., <php echo spreadAttrs($attrs) >)
        if ($attribute->name === '' && $attribute->value->isOutput()) {
            $output = $attribute->value->output;
            if (!$output instanceof OutputNode) {
                return;
            }

            $expression = $output->expression;
            if ($output->pipes !== null) {
                $expression = $this->compilePipes($expression, $output->pipes);
            }

            if ($output->escape) {
                $expression = $this->escaper->generateEscapeCode($expression, $output->context);
                $expression = $this->normalizeEscaperReference($expression);
            }

            $buffer->write(sprintf(
                '<?php $__attr = %s; if ($__attr !== \'\') { echo \' \' . $__attr; } ?>',
                $expression,
            ));

            return;
        }

        $buffer->write(' ' . $attribute->name);

        if (!$attribute->value->isBoolean()) {
            $buffer->write('="');

            $parts = $attribute->value->toParts() ?? [];
            if (count($parts) > 1) {
                foreach ($parts as $part) {
                    if ($part instanceof OutputNode) {
                        $this->generateOutput($part, $buffer);
                        continue;
                    }

                    $buffer->write($part);
                }
            } else {
                $part = $parts[0] ?? '';
                if ($part instanceof OutputNode) {
                    $this->generateOutput($part, $buffer);
                } else {
                    $buffer->write(htmlspecialchars($part, ENT_QUOTES, 'UTF-8'));
                }
            }

            $buffer->write('"');
        }
    }

    /**
     * Generate fragment output (renders only children, not the fragment element itself)
     *
     * @param \Sugar\Core\Ast\FragmentNode $node Fragment node
     * @param \Sugar\Core\Compiler\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateFragment(FragmentNode $node, OutputBuffer $buffer): void
    {
        // Fragments don't render themselves - only their children
        foreach ($node->children as $child) {
            $this->generateNode($child, $buffer);
        }
    }

    /**
     * Generate runtime call output
     *
     * @param \Sugar\Core\Ast\RuntimeCallNode $node Runtime call node
     * @param \Sugar\Core\Compiler\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateRuntimeCall(RuntimeCallNode $node, OutputBuffer $buffer): void
    {
        $arguments = implode(', ', $node->arguments);
        $buffer->write(sprintf('<?php echo %s(%s); ?>', $node->callableExpression, $arguments));
    }

    /**
     * Generate directive control structure
     *
     * @param \Sugar\Core\Ast\DirectiveNode $node Directive node
     * @param \Sugar\Core\Compiler\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateDirective(DirectiveNode $node, OutputBuffer $buffer): void
    {
        // For now, output as comment - full directive support comes in next phase
        $buffer->write('<!-- Directive: ' . $node->name . ' = ' . Escaper::html($node->expression) . ' -->');
    }

    /**
     * Recursively collect all {@see PhpImportNode} instances from a node tree.
     *
     * Import nodes may appear at any depth because the normalization pass traverses
     * the full AST. The code generator calls this before generating body code so
     * every import is hoisted and deduplicated at file scope.
     *
     * @return array<\Sugar\Core\Ast\PhpImportNode>
     */
    private function collectImportNodes(Node $node): array
    {
        if ($node instanceof PhpImportNode) {
            return [$node];
        }

        $collected = [];

        if ($node instanceof DocumentNode || $node instanceof ElementNode || $node instanceof FragmentNode) {
            foreach ($node->children as $child) {
                array_push($collected, ...$this->collectImportNodes($child));
            }

            return $collected;
        }

        if ($node instanceof DirectiveNode) {
            foreach ($node->children as $child) {
                array_push($collected, ...$this->collectImportNodes($child));
            }
        }

        return $collected;
    }

    /**
     * Compile pipe chain to nested function calls
     *
     * Transforms pipe syntax into nested function calls:
     * - Input: "$name", ["upper(...)", "truncate(..., 20)"]
     * - Output: "truncate(upper($name), 20)"
     *
     * The ... placeholder in each pipe is replaced with the result of the previous expression.
     *
     * @param string $baseExpression The initial expression
     * @param array<string> $pipes Array of pipe transformations
     * @return string Compiled nested function call expression
     */
    private function compilePipes(string $baseExpression, array $pipes): string
    {
        $result = $baseExpression;

        foreach ($pipes as $pipe) {
            if (str_contains($pipe, '...')) {
                // Replace ... placeholder with the current result
                $result = str_replace('...', $result, $pipe);
            } else {
                // Treat pipe stage as a callable expression
                $result = sprintf('(%s)(%s)', $pipe, $result);
            }
        }

        return $result;
    }

    /**
     * Rewrite generated Escaper FQCN calls to the reserved Sugar alias.
     */
    private function normalizeEscaperReference(string $expression): string
    {
        return str_replace(Escaper::class . '::', self::ESCAPER_ALIAS . '::', $expression);
    }
}
