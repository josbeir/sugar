<?php
declare(strict_types=1);

namespace Sugar\CodeGen;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\RuntimeCallNode;
use Sugar\Ast\TextNode;
use Sugar\Context\CompilationContext;
use Sugar\Escape\Escaper;
use Sugar\Exception\UnsupportedNodeException;

/**
 * Generates PHP code from AST with inline escaping
 */
final class CodeGenerator
{
    /**
     * Constructor
     *
     * @param \Sugar\Escape\Escaper $escaper Escaper instance
     * @param \Sugar\Context\CompilationContext $context Compilation context with debug info and template path
     */
    public function __construct(
        private readonly Escaper $escaper,
        private readonly CompilationContext $context,
    ) {
    }

    /**
     * Generate PHP code from AST
     *
     * @param \Sugar\Ast\DocumentNode $ast Document AST
     * @return string Generated PHP code
     */
    public function generate(DocumentNode $ast): string
    {
        $buffer = new OutputBuffer();

        // Prelude
        $buffer->writeln('<?php');
        $buffer->writeln('declare(strict_types=1);');
        $buffer->writeln('// Compiled Sugar template');

        $hasRealPath = $this->context->templatePath !== ''
            && $this->context->templatePath !== 'inline-template';

        if ($this->context->debug && $hasRealPath) {
            $buffer->writeln('// Source: ' . $this->context->templatePath);
            $buffer->writeln('// Compiled: ' . date('Y-m-d H:i:s'));
            $buffer->writeln('// Debug mode: enabled');
        } else {
            $buffer->writeln('// DO NOT EDIT - auto-generated');
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
     * @param \Sugar\Ast\Node $node AST node
     * @param \Sugar\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateNode(Node $node, OutputBuffer $buffer): void
    {
        match ($node::class) {
            TextNode::class => $this->generateText($node, $buffer),
            OutputNode::class => $this->generateOutput($node, $buffer),
            RawPhpNode::class => $this->generateRawPhp($node, $buffer),
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
     * @param \Sugar\Ast\TextNode $node Text node
     * @param \Sugar\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateText(TextNode $node, OutputBuffer $buffer): void
    {
        $buffer->write($node->content);
    }

    /**
     * Generate dynamic output with inline escaping
     *
     * @param \Sugar\Ast\OutputNode $node Output node
     * @param \Sugar\CodeGen\OutputBuffer $buffer Output buffer
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
            $buffer->write(sprintf('<?php echo %s;%s ?>', $escapedCode, $this->debugComment($node, 's:text')));
        } else {
            // Raw output
            $buffer->write(sprintf('<?php echo %s;%s ?>', $expression, $this->debugComment($node, 's:html')));
        }
    }

    /**
     * Generate raw PHP code pass-through
     *
     * @param \Sugar\Ast\RawPhpNode $node Raw PHP node
     * @param \Sugar\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateRawPhp(RawPhpNode $node, OutputBuffer $buffer): void
    {
        // Pass through trimmed code (removes excess whitespace from token parsing)
        $trimmedCode = trim($node->code);

        if ($this->context->debug) {
            // Add debug comment before closing tag
            $buffer->write(sprintf('<?php %s%s ?>', $trimmedCode, $this->debugComment($node)));
        } else {
            $buffer->write(sprintf('<?php %s ?>', $trimmedCode));
        }
    }

    /**
     * Generate HTML element output
     *
     * @param \Sugar\Ast\ElementNode $node Element node
     * @param \Sugar\CodeGen\OutputBuffer $buffer Output buffer
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
     * @param \Sugar\Ast\AttributeNode $attribute Attribute node
     * @param \Sugar\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateAttribute(AttributeNode $attribute, OutputBuffer $buffer): void
    {
        // Special case: empty name means this is a spread directive output
        // Output it directly without name= wrapper (e.g., <php echo spreadAttrs($attrs) >)
        if ($attribute->name === '' && $attribute->value instanceof OutputNode) {
            $expression = $attribute->value->expression;
            if ($attribute->value->pipes !== null) {
                $expression = $this->compilePipes($expression, $attribute->value->pipes);
            }

            if ($attribute->value->escape) {
                $expression = $this->escaper->generateEscapeCode($expression, $attribute->value->context);
            }

            $buffer->write(sprintf(
                '<?php $__attr = %s; if ($__attr !== \'\') { echo \' \' . $__attr;%s } ?>',
                $expression,
                $this->debugComment($attribute->value, 'attr'),
            ));

            return;
        }

        $buffer->write(' ' . $attribute->name);

        if ($attribute->value !== null) {
            $buffer->write('="');

            if (is_array($attribute->value)) {
                foreach ($attribute->value as $part) {
                    if ($part instanceof OutputNode) {
                        $this->generateOutput($part, $buffer);
                        continue;
                    }

                    $buffer->write($part);
                }
            } elseif ($attribute->value instanceof OutputNode) {
                // Dynamic attribute value
                $this->generateOutput($attribute->value, $buffer);
            } else {
                // Static attribute value
                $buffer->write(htmlspecialchars($attribute->value, ENT_QUOTES, 'UTF-8'));
            }

            $buffer->write('"');
        }
    }

    /**
     * Generate fragment output (renders only children, not the fragment element itself)
     *
     * @param \Sugar\Ast\FragmentNode $node Fragment node
     * @param \Sugar\CodeGen\OutputBuffer $buffer Output buffer
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
     * @param \Sugar\Ast\RuntimeCallNode $node Runtime call node
     * @param \Sugar\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateRuntimeCall(RuntimeCallNode $node, OutputBuffer $buffer): void
    {
        $arguments = implode(', ', $node->arguments);
        $buffer->write(sprintf(
            '<?php echo %s(%s);%s ?>',
            $node->callableExpression,
            $arguments,
            $this->debugComment($node, 'runtime'),
        ));
    }

    /**
     * Generate directive control structure
     *
     * @param \Sugar\Ast\DirectiveNode $node Directive node
     * @param \Sugar\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateDirective(DirectiveNode $node, OutputBuffer $buffer): void
    {
        // For now, output as comment - full directive support comes in next phase
        $buffer->write('<!-- Directive: ' . $node->name . ' = ' . Escaper::html($node->expression) . ' -->');
    }

    /**
     * Generate debug comment for a node
     *
     * @param \Sugar\Ast\Node $node AST node
     * @param string $context Optional context info (e.g., 's:if', 's:text')
     * @return string Debug comment or empty string if debug disabled
     */
    private function debugComment(Node $node, string $context = ''): string
    {
        if (!$this->context->debug) {
            return '';
        }

        $templatePath = $this->context->templatePath !== ''
            ? $this->context->templatePath
            : 'inline-template';
        $location = sprintf('%s:%d:%d', $templatePath, $node->line, $node->column);

        if ($context !== '') {
            return sprintf("\n/* sugar: %s %s */", $location, $context);
        }

        return sprintf("\n/* sugar: %s */", $location);
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
}
