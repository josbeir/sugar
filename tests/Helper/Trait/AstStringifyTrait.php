<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Trait;

use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\TextNode;

/**
 * Trait providing deterministic AST-to-string helpers for test assertions.
 */
trait AstStringifyTrait
{
    /**
     * Convert an AST document to a string representation.
     */
    protected function astToString(DocumentNode $ast): string
    {
        $output = '';
        foreach ($ast->children as $child) {
            $output .= $this->nodeToString($child);
        }

        return $output;
    }

    /**
     * Convert an AST document to a string representation.
     */
    protected function documentToString(DocumentNode $document): string
    {
        return $this->astToString($document);
    }

    /**
     * Convert a single AST node to a string representation.
     */
    protected function nodeToString(Node $node): string
    {
        if ($node instanceof TextNode) {
            return $node->content;
        }

        if ($node instanceof RawPhpNode) {
            return $node->code;
        }

        if ($node instanceof OutputNode) {
            return '<?= ' . $node->expression . ' ?>';
        }

        if ($node instanceof FragmentNode) {
            $output = '';
            foreach ($node->children as $child) {
                $output .= $this->nodeToString($child);
            }

            return $output;
        }

        if ($node instanceof ElementNode) {
            $html = '<' . $node->tag;
            foreach ($node->attributes as $attr) {
                $html .= ' ' . $attr->name;
                if ($attr->value->isBoolean()) {
                    continue;
                }

                $parts = $attr->value->toParts() ?? [];
                if (count($parts) > 1) {
                    $html .= '="';
                    foreach ($parts as $part) {
                        if ($part instanceof OutputNode) {
                            $html .= '<?= ' . $part->expression . ' ?>';
                            continue;
                        }

                        $html .= $part;
                    }

                    $html .= '"';
                    continue;
                }

                $part = $parts[0] ?? '';
                if ($part instanceof OutputNode) {
                    $html .= '="<?= ' . $part->expression . ' ?>"';
                    continue;
                }

                $html .= '="' . $part . '"';
            }

            $html .= '>';
            foreach ($node->children as $child) {
                $html .= $this->nodeToString($child);
            }

            if (!$node->selfClosing) {
                $html .= '</' . $node->tag . '>';
            }

            return $html;
        }

        return '';
    }
}
