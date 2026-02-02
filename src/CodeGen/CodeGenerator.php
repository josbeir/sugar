<?php
declare(strict_types=1);

namespace Sugar\CodeGen;

use RuntimeException;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
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
     */
    public function __construct(
        private readonly Escaper $escaper,
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
        $buffer->writeln('// DO NOT EDIT - auto-generated');
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
            $buffer->write(sprintf('<?php echo %s; ?>', $escapedCode));
        } else {
            // Raw output
            $buffer->write(sprintf('<?php echo %s; ?>', $node->expression));
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
        $buffer->write(sprintf('<?php %s ?>', trim($node->code)));
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
        } else {
            $buffer->write('>');

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
}
