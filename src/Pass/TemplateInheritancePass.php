<?php
declare(strict_types=1);

namespace Sugar\Pass;

use RuntimeException;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Node;
use Sugar\Enum\InheritanceAttribute;
use Sugar\Parser\Parser;
use Sugar\TemplateInheritance\TemplateLoaderInterface;

final readonly class TemplateInheritancePass
{
    /**
     * Constructor.
     *
     * @param \Sugar\TemplateInheritance\TemplateLoaderInterface $loader Template loader
     */
    public function __construct(
        private TemplateLoaderInterface $loader,
    ) {
    }

    /**
     * Process template inheritance (s:extends, s:block, s:include).
     *
     * @param \Sugar\Ast\DocumentNode $document Document to process
     * @param string $currentTemplate Current template path
     * @param array<string> $loadedTemplates Stack of loaded templates for circular detection
     * @return \Sugar\Ast\DocumentNode Processed document
     * @throws \RuntimeException On circular inheritance
     * @throws \Sugar\Exception\TemplateNotFoundException If template not found
     */
    public function process(
        DocumentNode $document,
        string $currentTemplate,
        array $loadedTemplates = [],
    ): DocumentNode {
        // Check for circular inheritance
        if (in_array($currentTemplate, $loadedTemplates, true)) {
            $chain = [...$loadedTemplates, $currentTemplate];
            throw new RuntimeException(
                sprintf('Circular template inheritance detected: %s', implode(' -> ', $chain)),
            );
        }

        $loadedTemplates[] = $currentTemplate;

        // Find s:extends directive
        $extendsElement = $this->findExtendsDirective($document);

        if ($extendsElement instanceof ElementNode) {
            $document = $this->processExtends($extendsElement, $document, $currentTemplate, $loadedTemplates);
        } else {
            // Process s:include directives
            $document = $this->processIncludes($document, $currentTemplate, $loadedTemplates);
        }

        // Remove template inheritance attributes (s:block, s:extends, s:include, s:with)
        return $this->removeInheritanceAttributes($document);
    }

    /**
     * Find s:extends directive in document.
     *
     * @param \Sugar\Ast\DocumentNode $document Document to search
     * @return \Sugar\Ast\ElementNode|null Element with s:extends or null
     */
    private function findExtendsDirective(DocumentNode $document): ?ElementNode
    {
        foreach ($document->children as $child) {
            if ($child instanceof ElementNode) {
                foreach ($child->attributes as $attr) {
                    if ($attr instanceof AttributeNode && $attr->name === InheritanceAttribute::EXTENDS->value) {
                        return $child;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Process s:extends directive.
     *
     * @param \Sugar\Ast\ElementNode $extendsElement Element with s:extends
     * @param \Sugar\Ast\DocumentNode $childDocument Child document
     * @param string $currentTemplate Current template path
     * @param array<string> $loadedTemplates Loaded templates stack
     * @return \Sugar\Ast\DocumentNode Processed parent document
     */
    private function processExtends(
        ElementNode $extendsElement,
        DocumentNode $childDocument,
        string $currentTemplate,
        array $loadedTemplates,
    ): DocumentNode {
        // Get parent template path
        $parentPath = $this->getAttributeValue($extendsElement, InheritanceAttribute::EXTENDS->value);
        $resolvedPath = $this->loader->resolve($parentPath, $currentTemplate);

        // Load and parse parent template
        $parentContent = $this->loader->load($resolvedPath);
        $parser = new Parser();
        $parentDocument = $parser->parse($parentContent);

        // Collect blocks from child
        $childBlocks = $this->collectBlocks($childDocument);

        // Replace blocks in parent
        $parentDocument = $this->replaceBlocks($parentDocument, $childBlocks);

        // Recursively process parent (for multi-level inheritance)
        return $this->process($parentDocument, $resolvedPath, $loadedTemplates);
    }

    /**
     * Process s:include directives.
     *
     * @param \Sugar\Ast\DocumentNode $document Document to process
     * @param string $currentTemplate Current template path
     * @param array<string> $loadedTemplates Loaded templates stack
     * @return \Sugar\Ast\DocumentNode Processed document
     */
    private function processIncludes(
        DocumentNode $document,
        string $currentTemplate,
        array $loadedTemplates,
    ): DocumentNode {
        $newChildren = [];

        foreach ($document->children as $child) {
            if ($child instanceof ElementNode && $this->hasAttribute($child, InheritanceAttribute::INCLUDE->value)) {
                $includePath = $this->getAttributeValue($child, InheritanceAttribute::INCLUDE->value);
                $resolvedPath = $this->loader->resolve($includePath, $currentTemplate);
                // Load and parse included template
                $includeContent = $this->loader->load($resolvedPath);
                $parser = new Parser();
                $includeDocument = $parser->parse($includeContent);
                // Process includes recursively
                $includeDocument = $this->processIncludes($includeDocument, $resolvedPath, $loadedTemplates);
                // Check for s:with (scope isolation)
                if ($this->hasAttribute($child, InheritanceAttribute::WITH->value)) {
                    // Wrap in scope isolation
                    $newChildren[] = $this->wrapInScope($includeDocument);
                } else {
                    // Open scope - add children directly
                    array_push($newChildren, ...$includeDocument->children);
                }
            } elseif ($child instanceof ElementNode) {
                // Recursively process children
                $processedChild = new ElementNode(
                    $child->tag,
                    $child->attributes,
                    $this->processChildrenIncludes($child->children, $currentTemplate, $loadedTemplates),
                    $child->selfClosing,
                    $child->line,
                    $child->column,
                );
                $newChildren[] = $processedChild;
            } else {
                $newChildren[] = $child;
            }
        }

        return new DocumentNode($newChildren);
    }

    /**
     * Process includes in children nodes.
     *
     * @param array<\Sugar\Ast\Node> $children Children to process
     * @param string $currentTemplate Current template path
     * @param array<string> $loadedTemplates Loaded templates stack
     * @return array<\Sugar\Ast\Node> Processed children
     */
    private function processChildrenIncludes(array $children, string $currentTemplate, array $loadedTemplates): array
    {
        $doc = new DocumentNode($children);
        $processed = $this->processIncludes($doc, $currentTemplate, $loadedTemplates);

        return $processed->children;
    }

    /**
     * Collect s:block definitions from document.
     *
     * @param \Sugar\Ast\DocumentNode $document Document to search
     * @return array<string, \Sugar\Ast\Node> Block name => Block node
     */
    private function collectBlocks(DocumentNode $document): array
    {
        $blocks = [];

        foreach ($document->children as $child) {
            if ($child instanceof ElementNode || $child instanceof FragmentNode) {
                foreach ($child->attributes as $attr) {
                    if (
                        $attr instanceof AttributeNode &&
                        $attr->name === InheritanceAttribute::BLOCK->value &&
                        is_string($attr->value)
                    ) {
                        $blocks[$attr->value] = $child;
                    }
                }

                // Recursively collect from children
                $blocks = [...$blocks, ...$this->collectBlocksFromChildren($child->children)];
            }
        }

        return $blocks;
    }

    /**
     * Collect blocks from children recursively.
     *
     * @param array<\Sugar\Ast\Node> $children Children to search
     * @return array<string, \Sugar\Ast\Node> Block name => Block node
     */
    private function collectBlocksFromChildren(array $children): array
    {
        $doc = new DocumentNode($children);

        return $this->collectBlocks($doc);
    }

    /**
     * Replace blocks in document with child blocks.
     *
     * @param \Sugar\Ast\DocumentNode $document Parent document
     * @param array<string, \Sugar\Ast\Node> $childBlocks Child blocks
     * @return \Sugar\Ast\DocumentNode Document with replaced blocks
     */
    private function replaceBlocks(DocumentNode $document, array $childBlocks): DocumentNode
    {
        $newChildren = [];

        foreach ($document->children as $child) {
            $newChildren[] = $this->replaceBlocksInNode($child, $childBlocks);
        }

        return new DocumentNode($newChildren);
    }

    /**
     * Replace blocks in a node recursively.
     *
     * @param \Sugar\Ast\Node $node Node to process
     * @param array<string, \Sugar\Ast\Node> $childBlocks Child blocks
     * @return \Sugar\Ast\Node Processed node
     */
    private function replaceBlocksInNode(Node $node, array $childBlocks): Node
    {
        if (!($node instanceof ElementNode) && !($node instanceof FragmentNode)) {
            return $node;
        }

        // Check if this node has s:block attribute
        $blockName = null;
        foreach ($node->attributes as $attr) {
            if (
                $attr instanceof AttributeNode &&
                $attr->name === InheritanceAttribute::BLOCK->value &&
                is_string($attr->value)
            ) {
                $blockName = $attr->value;
                break;
            }
        }

        // If block exists in child, replace content
        if ($blockName !== null && isset($childBlocks[$blockName])) {
            $childBlock = $childBlocks[$blockName];

            if ($childBlock instanceof ElementNode) {
                // Child is element: keep parent wrapper, replace children
                if ($node instanceof ElementNode) {
                    return new ElementNode(
                        $node->tag,
                        $node->attributes,
                        $childBlock->children,
                        $node->selfClosing,
                        $node->line,
                        $node->column,
                    );
                } else {
                    // Parent is fragment, child is element: use child's children
                    return new FragmentNode(
                        $node->attributes,
                        $childBlock->children,
                        $node->line,
                        $node->column,
                    );
                }
            } elseif ($childBlock instanceof FragmentNode) {
                // Child is fragment: check if it has directive attributes
                $hasDirectives = false;
                foreach ($childBlock->attributes as $attr) {
                    // Check for s: attributes that are NOT inheritance attributes
                    if (
                        str_starts_with($attr->name, 's:') &&
                        !InheritanceAttribute::isInheritanceAttribute($attr->name)
                    ) {
                        $hasDirectives = true;
                        break;
                    }
                }

                if ($node instanceof ElementNode) {
                    // Parent is element
                    if ($hasDirectives) {
                        // Fragment has directives: return fragment so DirectiveExtractionPass can process it
                        // Remove s:block since it's already been processed
                        $cleanAttrs = [];
                        foreach ($childBlock->attributes as $attr) {
                            if ($attr->name !== InheritanceAttribute::BLOCK->value) {
                                $cleanAttrs[] = $attr;
                            }
                        }

                        $wrappedFragment = new FragmentNode(
                            $cleanAttrs,
                            $childBlock->children,
                            $childBlock->line,
                            $childBlock->column,
                        );

                        return new ElementNode(
                            $node->tag,
                            $node->attributes,
                            [$wrappedFragment],
                            $node->selfClosing,
                            $node->line,
                            $node->column,
                        );
                    } else {
                        // No directives: just use fragment's children
                        return new ElementNode(
                            $node->tag,
                            $node->attributes,
                            $childBlock->children,
                            $node->selfClosing,
                            $node->line,
                            $node->column,
                        );
                    }
                } elseif ($hasDirectives) {
                    // Both are fragments
                    // Child fragment has directives: return it for later processing
                    $cleanAttrs = [];
                    foreach ($childBlock->attributes as $attr) {
                        if ($attr->name !== InheritanceAttribute::BLOCK->value) {
                            $cleanAttrs[] = $attr;
                        }
                    }

                    return new FragmentNode(
                        $cleanAttrs,
                        $childBlock->children,
                        $childBlock->line,
                        $childBlock->column,
                    );
                } else {
                    // No directives: merge children
                    return new FragmentNode(
                        $node->attributes,
                        $childBlock->children,
                        $node->line,
                        $node->column,
                    );
                }
            }
        }

        // Recursively process children
        $newChildren = [];
        foreach ($node->children as $child) {
            $newChildren[] = $this->replaceBlocksInNode($child, $childBlocks);
        }

        if ($node instanceof ElementNode) {
            return new ElementNode(
                $node->tag,
                $node->attributes,
                $newChildren,
                $node->selfClosing,
                $node->line,
                $node->column,
            );
        } else {
            return new FragmentNode(
                $node->attributes,
                $newChildren,
                $node->line,
                $node->column,
            );
        }
    }

    /**
     * Wrap included document in scope isolation.
     *
     * @param \Sugar\Ast\DocumentNode $document Document to wrap
     * @return \Sugar\Ast\DocumentNode Wrapped node (likely RawPhpNode for scope)
     */
    private function wrapInScope(DocumentNode $document): DocumentNode
    {
        // For now, return document as-is
        // TODO: Implement proper scope wrapping with extract() or similar
        return new DocumentNode($document->children);
    }

    /**
     * Check if element has attribute.
     *
     * @param \Sugar\Ast\ElementNode $element Element to check
     * @param string $name Attribute name
     * @return bool True if attribute exists
     */
    private function hasAttribute(ElementNode $element, string $name): bool
    {
        foreach ($element->attributes as $attr) {
            if ($attr instanceof AttributeNode && $attr->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get attribute value from element.
     *
     * @param \Sugar\Ast\ElementNode $element Element to search
     * @param string $name Attribute name
     * @return string Attribute value
     */
    private function getAttributeValue(ElementNode $element, string $name): string
    {
        foreach ($element->attributes as $attr) {
            if ($attr instanceof AttributeNode && $attr->name === $name) {
                return is_string($attr->value) ? $attr->value : '';
            }
        }

        return '';
    }

    /**
     * Remove template inheritance attributes from the AST.
     *
     * @param \Sugar\Ast\DocumentNode $document Document to clean
     * @return \Sugar\Ast\DocumentNode Cleaned document
     */
    private function removeInheritanceAttributes(DocumentNode $document): DocumentNode
    {
        $newChildren = [];

        foreach ($document->children as $child) {
            $newChildren[] = $this->removeInheritanceAttributesFromNode($child);
        }

        return new DocumentNode($newChildren);
    }

    /**
     * Remove template inheritance attributes from a node recursively.
     *
     * @param \Sugar\Ast\Node $node Node to clean
     * @return \Sugar\Ast\Node Cleaned node
     */
    private function removeInheritanceAttributesFromNode(Node $node): Node
    {
        if (!($node instanceof ElementNode) && !($node instanceof FragmentNode)) {
            return $node;
        }

        // Filter out template inheritance attributes
        $cleanAttributes = [];
        foreach ($node->attributes as $attr) {
            if ($attr instanceof AttributeNode) {
                // Keep all attributes except inheritance attributes
                if (!InheritanceAttribute::isInheritanceAttribute($attr->name)) {
                    $cleanAttributes[] = $attr;
                }
            } else {
                $cleanAttributes[] = $attr;
            }
        }

        // Recursively clean children
        $cleanChildren = [];
        foreach ($node->children as $child) {
            $cleanChildren[] = $this->removeInheritanceAttributesFromNode($child);
        }

        if ($node instanceof ElementNode) {
            return new ElementNode(
                $node->tag,
                $cleanAttributes,
                $cleanChildren,
                $node->selfClosing,
                $node->line,
                $node->column,
            );
        } else {
            return new FragmentNode(
                $cleanAttributes,
                $cleanChildren,
                $node->line,
                $node->column,
            );
        }
    }
}
