<?php
declare(strict_types=1);

namespace Sugar\CodeGen;

use RuntimeException;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Node;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Escape\Escaper;

/**
 * Generates PHP code from AST with inline escaping
 */
final class CodeGenerator
{
    /**
     * Constructor
     *
     * @param \Sugar\Escape\Escaper $escaper Escaper instance
     * @param bool $debug Enable debug comments with line numbers (default: false)
     * @param string|null $sourceFile Source template filename for debug comments
     */
    public function __construct(
        private readonly Escaper $escaper,
        private readonly bool $debug = false,
        private readonly ?string $sourceFile = null,
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

        if ($this->debug && $this->sourceFile !== null) {
            $buffer->writeln('// Source: ' . $this->sourceFile);
            $buffer->writeln('// Compiled: ' . date('Y-m-d H:i:s'));
            $buffer->writeln('// Debug mode: enabled');
        } else {
            $buffer->writeln('// DO NOT EDIT - auto-generated');
        }

        $buffer->writeln('?>');

        // Generate code for each node
        foreach ($ast->children as $node) {
            $this->generateNode($node, $buffer);
        }

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
            default => throw new RuntimeException('Unsupported node type: ' . $node::class),
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
        if ($node->escape) {
            // Generate inline escaping code (compile-time optimization)
            $escapedCode = $this->escaper->generateEscapeCode($node->expression, $node->context);
            $buffer->write(sprintf('<?php echo %s;%s ?>', $escapedCode, $this->debugComment($node, 's:text')));
        } else {
            // Raw output
            $buffer->write(sprintf('<?php echo %s;%s ?>', $node->expression, $this->debugComment($node, 's:html')));
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

        if ($this->debug) {
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
        $buffer->write('<' . $node->tag);

        // Attributes
        foreach ($node->attributes as $attribute) {
            $this->generateAttribute($attribute, $buffer);
        }

        // Close opening tag
        if ($node->selfClosing) {
            $buffer->write(' />');

            // Add debug comment after self-closing tag
            if ($this->debug) {
                $buffer->write(sprintf(' <!-- L%d:C%d -->', $node->line, $node->column));
            }
        } else {
            $buffer->write('>');

            // Add debug comment after opening tag
            if ($this->debug) {
                $buffer->write(sprintf(' <!-- L%d:C%d -->', $node->line, $node->column));
            }

            // Children
            foreach ($node->children as $child) {
                $this->generateNode($child, $buffer);
            }

            // Closing tag
            $buffer->write('</' . $node->tag . '>');
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
            $buffer->write(' ');
            $this->generateOutput($attribute->value, $buffer);

            return;
        }

        $buffer->write(' ' . $attribute->name);

        if ($attribute->value !== null) {
            $buffer->write('="');

            if ($attribute->value instanceof OutputNode) {
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
     * Generate directive control structure
     *
     * @param \Sugar\Ast\DirectiveNode $node Directive node
     * @param \Sugar\CodeGen\OutputBuffer $buffer Output buffer
     */
    private function generateDirective(DirectiveNode $node, OutputBuffer $buffer): void
    {
        // For now, output as comment - full directive support comes in next phase
        $buffer->write('<!-- Directive: ' . $node->name . ' = ' . htmlspecialchars($node->expression) . ' -->');
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
        if (!$this->debug) {
            return '';
        }

        $location = sprintf('L%d:C%d', $node->line, $node->column);

        if ($context !== '') {
            return sprintf(' /* %s %s */', $location, $context);
        }

        return sprintf(' /* %s */', $location);
    }
}
