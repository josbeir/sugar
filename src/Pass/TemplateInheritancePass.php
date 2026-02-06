<?php
declare(strict_types=1);

namespace Sugar\Pass;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\Helper\AttributeHelper;
use Sugar\Ast\Helper\DirectivePrefixHelper;
use Sugar\Ast\Helper\NodeCloner;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\Exception\SyntaxException;
use Sugar\Loader\TemplateLoaderInterface;
use Sugar\Parser\Parser;

final class TemplateInheritancePass implements PassInterface
{
    private DirectivePrefixHelper $prefixHelper;

    private SugarConfig $config;

    /**
     * Stack of loaded templates for circular detection
     *
     * @var array<string>
     */
    private array $loadedTemplates = [];

    /**
     * Constructor.
     *
     * @param \Sugar\Loader\TemplateLoaderInterface $loader Template loader
     * @param \Sugar\Config\SugarConfig $config Sugar configuration
     */
    public function __construct(
        private readonly TemplateLoaderInterface $loader,
        SugarConfig $config,
    ) {
        $this->config = $config;
        $this->prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
    }

    /**
     * Execute the pass: process template inheritance (s:extends, s:block, s:include)
     *
     * @param \Sugar\Ast\DocumentNode $ast Document to process
     * @param \Sugar\Context\CompilationContext $context Compilation context
     * @return \Sugar\Ast\DocumentNode Processed document
     * @throws \Sugar\Exception\SyntaxException On circular inheritance
     * @throws \Sugar\Exception\TemplateNotFoundException If template not found
     */
    public function execute(DocumentNode $ast, CompilationContext $context): DocumentNode
    {
        // Reset loaded templates stack for this execution
        $this->loadedTemplates = [];

        return $this->process($ast, $context, $this->loadedTemplates);
    }

    /**
     * Process template inheritance (s:extends, s:block, s:include).
     *
     * @param \Sugar\Ast\DocumentNode $document Document to process
     * @param \Sugar\Context\CompilationContext $context Compilation context
     * @param array<string> $loadedTemplates Stack of loaded templates for circular detection
     * @return \Sugar\Ast\DocumentNode Processed document
     * @throws \Sugar\Exception\SyntaxException On circular inheritance
     * @throws \Sugar\Exception\TemplateNotFoundException If template not found
     */
    private function process(
        DocumentNode $document,
        CompilationContext $context,
        array &$loadedTemplates,
    ): DocumentNode {
        $currentTemplate = $context->templatePath;

        // Check for circular inheritance
        if (in_array($currentTemplate, $loadedTemplates, true)) {
            $chain = [...$loadedTemplates, $currentTemplate];
            $extendsElement = $this->findExtendsDirective($document);

            // Find the s:extends attribute for better error positioning
            $extendsAttr = $extendsElement instanceof ElementNode
                ? AttributeHelper::findAttribute($extendsElement->attributes, $this->prefixHelper->buildName('extends'))
                : null;

            $line = $extendsAttr instanceof AttributeNode ? $extendsAttr->line : $extendsElement?->line;
            $column = $extendsAttr instanceof AttributeNode ? $extendsAttr->column : $extendsElement?->column;

            throw $context->createException(
                SyntaxException::class,
                sprintf('Circular template inheritance detected: %s', implode(' -> ', $chain)),
                $line,
                $column,
            );
        }

        $loadedTemplates[] = $currentTemplate;

        // Find s:extends directive
        $extendsElement = $this->findExtendsDirective($document);

        if ($extendsElement instanceof ElementNode) {
            $document = $this->processExtends($extendsElement, $document, $context, $loadedTemplates);
        } else {
            // Process s:include directives
            $document = $this->processIncludes($document, $context, $loadedTemplates);
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
            if (
                $child instanceof ElementNode &&
                AttributeHelper::hasAttribute($child, $this->prefixHelper->buildName('extends'))
            ) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Process s:extends directive.
     *
     * @param \Sugar\Ast\ElementNode $extendsElement Element with s:extends
     * @param \Sugar\Ast\DocumentNode $childDocument Child document
     * @param \Sugar\Context\CompilationContext $context Compilation context
     * @param array<string> $loadedTemplates Loaded templates stack
     * @return \Sugar\Ast\DocumentNode Processed parent document
     */
    private function processExtends(
        ElementNode $extendsElement,
        DocumentNode $childDocument,
        CompilationContext $context,
        array &$loadedTemplates,
    ): DocumentNode {
        // Get parent template path
        $parentPath = $this->getAttributeValue($extendsElement, $this->prefixHelper->buildName('extends'));
        $resolvedPath = $this->loader->resolve($parentPath, $context->templatePath);

        // Track parent template as dependency
        $context->tracker?->addDependency($resolvedPath);

        // Load and parse parent template
        $parentContent = $this->loader->load($resolvedPath);
        $parser = new Parser($this->config);
        $parentDocument = $parser->parse($parentContent);

        // Collect blocks from child
        $childBlocks = $this->collectBlocks($childDocument);

        // Replace blocks in parent
        $parentDocument = $this->replaceBlocks($parentDocument, $childBlocks);

        // Create new context for parent template
        $parentContext = new CompilationContext(
            templatePath: $resolvedPath,
            source: $parentContent,
            debug: $context->debug,
            tracker: $context->tracker,
        );

        // Recursively process parent (for multi-level inheritance)
        return $this->process($parentDocument, $parentContext, $loadedTemplates);
    }

    /**
     * Process s:include directives.
     *
     * @param \Sugar\Ast\DocumentNode $document Document to process
     * @param \Sugar\Context\CompilationContext $context Compilation context
     * @param array<string> $loadedTemplates Loaded templates stack
     * @return \Sugar\Ast\DocumentNode Processed document
     */
    private function processIncludes(
        DocumentNode $document,
        CompilationContext $context,
        array &$loadedTemplates,
    ): DocumentNode {
        $newChildren = [];

        foreach ($document->children as $child) {
            if (
                ($child instanceof ElementNode || $child instanceof FragmentNode) &&
                $this->hasAttribute($child, $this->prefixHelper->buildName('include'))
            ) {
                $includePath = $this->getAttributeValue($child, $this->prefixHelper->buildName('include'));
                $resolvedPath = $this->loader->resolve($includePath, $context->templatePath);

                // Track included template as dependency
                $context->tracker?->addDependency($resolvedPath);

                // Load and parse included template
                $includeContent = $this->loader->load($resolvedPath);
                $parser = new Parser($this->config);
                $includeDocument = $parser->parse($includeContent);

                // Create new context for included template
                $includeContext = new CompilationContext(
                    templatePath: $resolvedPath,
                    source: $includeContent,
                    debug: $context->debug,
                    tracker: $context->tracker,
                );

                // Process includes recursively
                $includeDocument = $this->processIncludes($includeDocument, $includeContext, $loadedTemplates);
                // Check for s:with (scope isolation)
                if ($this->hasAttribute($child, $this->prefixHelper->buildName('with'))) {
                    // Wrap in scope isolation with variables from s:with
                    $withValue = $this->getAttributeValue($child, $this->prefixHelper->buildName('with'));
                    $wrapped = $this->wrapInScope($includeDocument, $withValue);
                    array_push($newChildren, ...$wrapped->children);
                } else {
                    // Open scope - add children directly
                    array_push($newChildren, ...$includeDocument->children);
                }
            } elseif ($child instanceof ElementNode || $child instanceof FragmentNode) {
                // Recursively process children
                $processedChildren = $this->processChildrenIncludes(
                    $child->children,
                    $context,
                    $loadedTemplates,
                );
                $processedChild = $child instanceof FragmentNode
                    ? NodeCloner::fragmentWithChildren($child, $processedChildren)
                    : NodeCloner::withChildren($child, $processedChildren);
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
     * @param \Sugar\Context\CompilationContext $context Compilation context
     * @param array<string> $loadedTemplates Loaded templates stack
     * @return array<\Sugar\Ast\Node> Processed children
     */
    private function processChildrenIncludes(
        array $children,
        CompilationContext $context,
        array &$loadedTemplates,
    ): array {
        $doc = new DocumentNode($children);
        $processed = $this->processIncludes($doc, $context, $loadedTemplates);

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
                $blockName = AttributeHelper::getAttributeValue($child, $this->prefixHelper->buildName('block'));

                if (is_string($blockName) && $blockName !== '') {
                    $blocks[$blockName] = $child;
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
        $blockName = AttributeHelper::getAttributeValue($node, $this->prefixHelper->buildName('block'));

        if (!is_string($blockName) || $blockName === '') {
            $blockName = null;
        }

        // If block exists in child, replace content
        if ($blockName !== null && isset($childBlocks[$blockName])) {
            $childBlock = $childBlocks[$blockName];
            if ($childBlock instanceof ElementNode) {
                // Child is element: keep parent wrapper, replace children
                if ($node instanceof ElementNode) {
                    return NodeCloner::withChildren($node, $childBlock->children);
                }

                // Parent is fragment, child is element: use child's children
                return NodeCloner::fragmentWithChildren($node, $childBlock->children);
            }

            if ($childBlock instanceof FragmentNode) {
                // Child is fragment: check if it has directive attributes
                $hasDirectives = false;
                foreach ($childBlock->attributes as $attr) {
                    // Check for directive attributes that are NOT inheritance attributes
                    if (
                        $this->prefixHelper->isDirective($attr->name) &&
                        !$this->prefixHelper->isInheritanceAttribute($attr->name)
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
                            if ($attr->name !== $this->prefixHelper->buildName('block')) {
                                $cleanAttrs[] = $attr;
                            }
                        }

                        $wrappedFragment = NodeCloner::fragmentWithChildren(
                            $childBlock,
                            $childBlock->children,
                        );
                        $wrappedFragment = NodeCloner::fragmentWithAttributes(
                            $wrappedFragment,
                            $cleanAttrs,
                        );

                        return NodeCloner::withChildren($node, [$wrappedFragment]);
                    }

                    // No directives: just use fragment's children
                    return NodeCloner::withChildren($node, $childBlock->children);
                }

                if ($hasDirectives) {
                    // Both are fragments
                    // Child fragment has directives: return it for later processing
                    $cleanAttrs = [];
                    foreach ($childBlock->attributes as $attr) {
                        if ($attr->name !== $this->prefixHelper->buildName('block')) {
                            $cleanAttrs[] = $attr;
                        }
                    }

                    return NodeCloner::fragmentWithAttributes($childBlock, $cleanAttrs);
                }

                // No directives: merge children
                return NodeCloner::fragmentWithChildren($node, $childBlock->children);
            }
        }

        // Recursively process children
        $newChildren = [];
        foreach ($node->children as $child) {
            $newChildren[] = $this->replaceBlocksInNode($child, $childBlocks);
        }

        if ($node instanceof ElementNode) {
            return NodeCloner::withChildren($node, $newChildren);
        }

        return NodeCloner::fragmentWithChildren($node, $newChildren);
    }

    /**
     * Wrap included document in scope isolation.
     *
     * @param \Sugar\Ast\DocumentNode $document Document to wrap
     * @param string $withExpression PHP expression that evaluates to array of variables
     * @return \Sugar\Ast\DocumentNode Wrapped document with closure
     */
    private function wrapInScope(DocumentNode $document, string $withExpression): DocumentNode
    {
        // Wrap in closure with extract for variable isolation
        // Pattern: (function($__vars) { extract($__vars); ...template... })([...]);

        $openingCode = '(function($__vars) { extract($__vars);';
        $closingCode = '})(' . $withExpression . ');';

        return new DocumentNode([
            new RawPhpNode($openingCode, 0, 0),
            ...$document->children,
            new RawPhpNode($closingCode, 0, 0),
        ]);
    }

    /**
     * Check if element has attribute.
     *
     * @param \Sugar\Ast\ElementNode $element Element to check
     * @param string $name Attribute name
     * @return bool True if attribute exists
     */
    private function hasAttribute(ElementNode|FragmentNode $element, string $name): bool
    {
        return AttributeHelper::hasAttribute($element, $name);
    }

    /**
     * Get attribute value from element.
     *
     * @param \Sugar\Ast\ElementNode|\Sugar\Ast\FragmentNode $element Element to search
     * @param string $name Attribute name
     * @return string Attribute value
     */
    private function getAttributeValue(ElementNode|FragmentNode $element, string $name): string
    {
        $value = AttributeHelper::getAttributeValue($element, $name, '');

        return is_string($value) ? $value : '';
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
                if (!$this->prefixHelper->isInheritanceAttribute($attr->name)) {
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
            return NodeCloner::withAttributesAndChildren($node, $cleanAttributes, $cleanChildren);
        }

        return NodeCloner::fragmentWithAttributes(
            NodeCloner::fragmentWithChildren($node, $cleanChildren),
            $cleanAttributes,
        );
    }
}
