<?php
declare(strict_types=1);

namespace Sugar\Core\Pass\Template;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Helper\NodeCloner;
use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Enum\BlockMergeMode;
use Sugar\Core\Extension\DirectiveRegistryInterface;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser;
use Sugar\Core\Pass\Directive\Helper\DirectiveClassifier;
use Sugar\Core\Pass\Trait\ScopeIsolationTrait;

final class TemplateInheritancePass implements AstPassInterface
{
    use ScopeIsolationTrait;

    private DirectivePrefixHelper $prefixHelper;

    private DirectiveClassifier $directiveClassifier;

    /**
     * Stack of loaded templates for circular detection
     *
     * @var array<string>
     */
    private array $loadedTemplates = [];

    /**
     * Cache of parsed template ASTs by resolved path.
     * Avoids re-parsing the same layout/include multiple times.
     *
     * @var array<string, \Sugar\Core\Ast\DocumentNode>
     */
    private array $templateAstCache = [];

    /**
     * Constructor.
     *
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $loader Template loader
     * @param \Sugar\Core\Parser\Parser $parser Template parser
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface $registry Directive registry
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     */
    public function __construct(
        private readonly TemplateLoaderInterface $loader,
        private readonly Parser $parser,
        private readonly DirectiveRegistryInterface $registry,
        SugarConfig $config,
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
        $this->directiveClassifier = new DirectiveClassifier($this->registry, $this->prefixHelper);
    }

    /**
     * Hook executed before child traversal.
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if (!$node instanceof DocumentNode) {
            return NodeAction::none();
        }

        // Reset loaded templates stack for this execution
        $this->loadedTemplates = [];
        $processed = $this->process($node, $context->compilation, $this->loadedTemplates);

        return NodeAction::replace($processed);
    }

    /**
     * Hook executed after child traversal.
     */
    public function after(Node $node, PipelineContext $context): NodeAction
    {
        return NodeAction::none();
    }

    /**
     * Process template inheritance (s:extends, s:block, s:include).
     *
     * @param \Sugar\Core\Ast\DocumentNode $document Document to process
     * @param \Sugar\Core\Compiler\CompilationContext $context Compilation context
     * @param array<string> $loadedTemplates Stack of loaded templates for circular detection
     * @return \Sugar\Core\Ast\DocumentNode Processed document
     * @throws \Sugar\Core\Exception\SyntaxException On circular inheritance
     * @throws \Sugar\Core\Exception\TemplateNotFoundException If template not found
     */
    private function process(
        DocumentNode $document,
        CompilationContext $context,
        array &$loadedTemplates,
    ): DocumentNode {
        $this->directiveClassifier->validateUnknownDirectivesInNodes($document->children, $context, false);
        $this->validateExtendsPlacement($document, $context);

        if ($context->blocks !== null) {
            $document = $this->processIncludes($document, $context, $loadedTemplates);
            $document = $this->extractBlocks($document, $context->blocks, $context);

            return $this->removeInheritanceAttributes($document);
        }

        $currentTemplate = $context->templatePath;

        // Check for circular inheritance
        if (in_array($currentTemplate, $loadedTemplates, true)) {
            $chain = [...$loadedTemplates, $currentTemplate];
            $extendsElement = $this->findExtendsDirective($document);

            // Find the s:extends attribute for better error positioning
            $extendsAttr = $extendsElement instanceof ElementNode || $extendsElement instanceof FragmentNode
                ? AttributeHelper::findAttribute($extendsElement->attributes, $this->prefixHelper->buildName('extends'))
                : null;

            $line = $extendsAttr instanceof AttributeNode ? $extendsAttr->line : $extendsElement?->line;
            $column = $extendsAttr instanceof AttributeNode ? $extendsAttr->column : $extendsElement?->column;

            if ($extendsAttr instanceof AttributeNode) {
                throw $context->createSyntaxExceptionForAttribute(
                    sprintf('Circular template inheritance detected: %s', implode(' -> ', $chain)),
                    $extendsAttr,
                    $line,
                    $column,
                );
            }

            if ($extendsElement instanceof ElementNode || $extendsElement instanceof FragmentNode) {
                throw $context->createSyntaxExceptionForNode(
                    sprintf('Circular template inheritance detected: %s', implode(' -> ', $chain)),
                    $extendsElement,
                    $line,
                    $column,
                );
            }

            throw $context->createSyntaxException(
                sprintf('Circular template inheritance detected: %s', implode(' -> ', $chain)),
                $line,
                $column,
            );
        }

        $loadedTemplates[] = $currentTemplate;

        // Find s:extends directive
        $extendsElement = $this->findExtendsDirective($document);

        if ($extendsElement instanceof ElementNode || $extendsElement instanceof FragmentNode) {
            $document = $this->processExtends($extendsElement, $document, $context, $loadedTemplates);
        } else {
            // Process s:include directives
            $document = $this->processIncludes($document, $context, $loadedTemplates);
        }

        // Remove template inheritance attributes (s:block, s:append, s:prepend, s:extends, s:include, s:with)
        return $this->removeInheritanceAttributes($document);
    }

    /**
     * Extract only the requested blocks in template order.
     *
     * @param array<string> $blockNames
     */
    private function extractBlocks(
        DocumentNode $document,
        array $blockNames,
        CompilationContext $context,
    ): DocumentNode {
        $targets = array_fill_keys($blockNames, true);
        $children = [];

        $this->collectBlockChildren($document->children, $targets, $children, $context);

        return new DocumentNode($children);
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $nodes
     * @param array<string, bool> $targets
     * @param array<\Sugar\Core\Ast\Node> $output
     */
    private function collectBlockChildren(
        array $nodes,
        array $targets,
        array &$output,
        CompilationContext $context,
    ): void {
        foreach ($nodes as $node) {
            if (!($node instanceof ElementNode) && !($node instanceof FragmentNode)) {
                continue;
            }

            $blockDirective = $this->getBlockDirective($node, $context);
            $blockName = $blockDirective['name'] ?? '';

            if ($blockName !== '' && isset($targets[$blockName])) {
                $output[] = $node;

                continue;
            }

            $this->collectBlockChildren($node->children, $targets, $output, $context);
        }
    }

    /**
     * Find s:extends directive in document.
     *
     * @param \Sugar\Core\Ast\DocumentNode $document Document to search
     * @return \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\FragmentNode|null Element or fragment with s:extends or null
     */
    private function findExtendsDirective(DocumentNode $document): ElementNode|FragmentNode|null
    {
        foreach ($document->children as $child) {
            if (
                ($child instanceof ElementNode || $child instanceof FragmentNode) &&
                AttributeHelper::hasAttribute($child, $this->prefixHelper->buildName('extends'))
            ) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Ensure s:extends is only used on root-level elements/fragments.
     */
    private function validateExtendsPlacement(DocumentNode $document, CompilationContext $context): void
    {
        $rootExtendsElements = [];

        foreach ($document->children as $child) {
            if (!($child instanceof ElementNode) && !($child instanceof FragmentNode)) {
                continue;
            }

            if (AttributeHelper::hasAttribute($child, $this->prefixHelper->buildName('extends'))) {
                $rootExtendsElements[] = $child;
            }

            $nested = $this->findNestedExtendsInChildren($child->children);
            if (!($nested instanceof ElementNode) && !($nested instanceof FragmentNode)) {
                continue;
            }

            $extendsAttr = AttributeHelper::findAttribute(
                $nested->attributes,
                $this->prefixHelper->buildName('extends'),
            );

            $message = 's:extends is only allowed on root-level template elements.';
            if ($extendsAttr instanceof AttributeNode) {
                throw $context->createSyntaxExceptionForAttribute($message, $extendsAttr);
            }

            throw $context->createSyntaxExceptionForNode($message, $nested);
        }

        if (count($rootExtendsElements) <= 1) {
            return;
        }

        $duplicateExtendsNode = $rootExtendsElements[1];
        $extendsAttribute = AttributeHelper::findAttribute(
            $duplicateExtendsNode->attributes,
            $this->prefixHelper->buildName('extends'),
        );

        $message = 'Only one s:extends directive is allowed per template.';

        if ($extendsAttribute instanceof AttributeNode) {
            throw $context->createSyntaxExceptionForAttribute($message, $extendsAttribute);
        }

        throw $context->createSyntaxExceptionForNode($message, $duplicateExtendsNode);
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $children
     */
    private function findNestedExtendsInChildren(array $children): ElementNode|FragmentNode|null
    {
        foreach ($children as $child) {
            if (!($child instanceof ElementNode) && !($child instanceof FragmentNode)) {
                continue;
            }

            if (AttributeHelper::hasAttribute($child, $this->prefixHelper->buildName('extends'))) {
                return $child;
            }

            $nested = $this->findNestedExtendsInChildren($child->children);
            if ($nested instanceof ElementNode || $nested instanceof FragmentNode) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * Get or parse a template with caching.
     *
     * @param string $resolvedPath Resolved template path
     * @param string $content Template content
     * @return \Sugar\Core\Ast\DocumentNode Parsed document
     */
    private function getOrParseTemplate(string $resolvedPath, string $content): DocumentNode
    {
        if (!isset($this->templateAstCache[$resolvedPath])) {
            $this->templateAstCache[$resolvedPath] = $this->parser->parse($content);
        }

        return $this->templateAstCache[$resolvedPath];
    }

    /**
     * Process s:extends directive.
     *
     * @param \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\FragmentNode $extendsElement Element or fragment with s:extends
     * @param \Sugar\Core\Ast\DocumentNode $childDocument Child document
     * @param \Sugar\Core\Compiler\CompilationContext $context Compilation context
     * @param array<string> $loadedTemplates Loaded templates stack
     * @return \Sugar\Core\Ast\DocumentNode Processed parent document
     */
    private function processExtends(
        ElementNode|FragmentNode $extendsElement,
        DocumentNode $childDocument,
        CompilationContext $context,
        array &$loadedTemplates,
    ): DocumentNode {
        // Get parent template path
        $parentPath = AttributeHelper::getStringAttributeValue(
            $extendsElement,
            $this->prefixHelper->buildName('extends'),
        );
        $resolvedPath = $this->loader->resolve($parentPath, $context->templatePath);

        // Track parent template as dependency
        $context->tracker?->addDependency(
            $this->loader->resolveToFilePath($parentPath, $context->templatePath),
        );

        // Load and parse parent template (with caching)
        $parentContent = $this->loader->load($resolvedPath);
        $parentDocument = $this->getOrParseTemplate($resolvedPath, $parentContent);

        // Create new context for parent template
        $parentContext = new CompilationContext(
            templatePath: $resolvedPath,
            source: $parentContent,
            debug: $context->debug,
            tracker: $context->tracker,
        );
        $parentContext->stampTemplatePath($parentDocument);

        // Resolve includes in child before merging so relative paths use the child template path
        $childDocument = $this->processIncludes($childDocument, $context, $loadedTemplates);

        // Collect blocks from child
        $childBlocks = $this->collectBlocks($childDocument, $context);

        // Replace blocks in parent
        $parentDocument = $this->replaceBlocks($parentDocument, $childBlocks);

        // Recursively process parent (for multi-level inheritance)
        return $this->process($parentDocument, $parentContext, $loadedTemplates);
    }

    /**
     * Process s:include directives.
     *
     * @param \Sugar\Core\Ast\DocumentNode $document Document to process
     * @param \Sugar\Core\Compiler\CompilationContext $context Compilation context
     * @param array<string> $loadedTemplates Loaded templates stack
     * @return \Sugar\Core\Ast\DocumentNode Processed document
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
                AttributeHelper::hasAttribute($child, $this->prefixHelper->buildName('include'))
            ) {
                $includeName = $this->prefixHelper->buildName('include');
                $withName = $this->prefixHelper->buildName('with');
                $includePath = AttributeHelper::getStringAttributeValue(
                    $child,
                    $includeName,
                );
                $resolvedPath = $this->loader->resolve($includePath, $context->templatePath);

                // Track included template as dependency
                $context->tracker?->addDependency(
                    $this->loader->resolveToFilePath($includePath, $context->templatePath),
                );

                // Load and parse included template (with caching)
                $includeContent = $this->loader->load($resolvedPath);
                $includeDocument = $this->getOrParseTemplate($resolvedPath, $includeContent);

                // Create new context for included template
                $includeContext = new CompilationContext(
                    templatePath: $resolvedPath,
                    source: $includeContent,
                    debug: $context->debug,
                    tracker: $context->tracker,
                );
                $includeContext->stampTemplatePath($includeDocument);

                $this->directiveClassifier->validateUnknownDirectivesInNodes(
                    $includeDocument->children,
                    $includeContext,
                    false,
                );

                // Process includes recursively
                $includeDocument = $this->processIncludes($includeDocument, $includeContext, $loadedTemplates);

                $includeChildren = $includeDocument->children;

                // Check for s:with (scope isolation)
                if (AttributeHelper::hasAttribute($child, $this->prefixHelper->buildName('with'))) {
                    // Wrap in scope isolation with variables from s:with
                    $withValue = AttributeHelper::getStringAttributeValue(
                        $child,
                        $withName,
                    );
                    $wrapped = $this->wrapInIsolatedScope($includeDocument, $withValue);
                    $includeChildren = $wrapped->children;
                }

                if ($child instanceof ElementNode) {
                    // Preserve wrapper element and inject included content as children
                    $cleanAttributes = AttributeHelper::filterAttributes(
                        $child->attributes,
                        fn(AttributeNode $attr): bool => !in_array(
                            $attr->name,
                            [$includeName, $withName],
                            true,
                        ),
                    );
                    $newChildren[] = NodeCloner::withAttributesAndChildren($child, $cleanAttributes, $includeChildren);
                } else {
                    // Fragment include remains wrapperless
                    array_push($newChildren, ...$includeChildren);
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
     * @param array<\Sugar\Core\Ast\Node> $children Children to process
     * @param \Sugar\Core\Compiler\CompilationContext $context Compilation context
     * @param array<string> $loadedTemplates Loaded templates stack
     * @return array<\Sugar\Core\Ast\Node> Processed children
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
     * @param \Sugar\Core\Ast\DocumentNode $document Document to search
     * @return array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Enum\BlockMergeMode, attributeName: string}>
     */
    private function collectBlocks(DocumentNode $document, CompilationContext $context): array
    {
        $blocks = [];

        foreach ($document->children as $child) {
            if ($child instanceof ElementNode || $child instanceof FragmentNode) {
                $blockDirective = $this->getBlockDirective($child, $context);

                if ($blockDirective !== null) {
                    $blocks[$blockDirective['name']] = [
                        'node' => $child,
                        'mode' => $blockDirective['mode'],
                        'attributeName' => $blockDirective['attributeName'],
                    ];
                }

                // Recursively collect from children
                $blocks = [...$blocks, ...$this->collectBlocksFromChildren($child->children, $context)];
            }
        }

        return $blocks;
    }

    /**
     * Collect blocks from children recursively.
     *
     * @param array<\Sugar\Core\Ast\Node> $children Children to search
     * @return array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Enum\BlockMergeMode, attributeName: string}>
     */
    private function collectBlocksFromChildren(array $children, CompilationContext $context): array
    {
        $doc = new DocumentNode($children);

        return $this->collectBlocks($doc, $context);
    }

    /**
     * Replace blocks in document with child blocks.
     *
     * @param \Sugar\Core\Ast\DocumentNode $document Parent document
     * @param array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Enum\BlockMergeMode, attributeName: string}> $childBlocks Child blocks
     * @return \Sugar\Core\Ast\DocumentNode Document with replaced blocks
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
     * @param \Sugar\Core\Ast\Node $node Node to process
     * @param array<string, array{node: \Sugar\Core\Ast\Node, mode: \Sugar\Core\Enum\BlockMergeMode, attributeName: string}> $childBlocks Child blocks
     * @return \Sugar\Core\Ast\Node Processed node
     */
    private function replaceBlocksInNode(Node $node, array $childBlocks): Node
    {
        if (!($node instanceof ElementNode) && !($node instanceof FragmentNode)) {
            return $node;
        }

        // Check if this node has s:block attribute
        $blockName = AttributeHelper::getStringAttributeValue(
            $node,
            $this->prefixHelper->buildName('block'),
        );

        if ($blockName === '') {
            $blockName = null;
        }

        // If block exists in child, replace content
        if ($blockName !== null && isset($childBlocks[$blockName])) {
            $childBlock = $childBlocks[$blockName]['node'];
            $mode = $childBlocks[$blockName]['mode'];
            $attributeName = $childBlocks[$blockName]['attributeName'];

            return $this->mergeBlock($node, $childBlock, $mode, $attributeName);
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
     * Remove template inheritance attributes from the AST.
     *
     * @param \Sugar\Core\Ast\DocumentNode $document Document to clean
     * @return \Sugar\Core\Ast\DocumentNode Cleaned document
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
     * @param \Sugar\Core\Ast\Node $node Node to clean
     * @return \Sugar\Core\Ast\Node Cleaned node
     */
    private function removeInheritanceAttributesFromNode(Node $node): Node
    {
        if (!($node instanceof ElementNode) && !($node instanceof FragmentNode)) {
            return $node;
        }

        // Filter out template inheritance attributes
        $cleanAttributes = AttributeHelper::filterAttributes(
            $node->attributes,
            fn(AttributeNode $attr): bool => !$this->prefixHelper->isInheritanceAttribute($attr->name),
        );

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

    /**
     * @return array{name: string, mode: \Sugar\Core\Enum\BlockMergeMode, attributeName: string}|null
     */
    private function getBlockDirective(ElementNode|FragmentNode $node, CompilationContext $context): ?array
    {
        $blockAttr = $this->prefixHelper->buildName('block');
        $appendAttr = $this->prefixHelper->buildName('append');
        $prependAttr = $this->prefixHelper->buildName('prepend');

        $found = [];
        if (AttributeHelper::hasAttribute($node, $blockAttr)) {
            $found[] = $blockAttr;
        }

        if (AttributeHelper::hasAttribute($node, $appendAttr)) {
            $found[] = $appendAttr;
        }

        if (AttributeHelper::hasAttribute($node, $prependAttr)) {
            $found[] = $prependAttr;
        }

        if (count($found) > 1) {
            $attr = AttributeHelper::findAttribute($node->attributes, $found[1])
                ?? AttributeHelper::findAttribute($node->attributes, $found[0]);
            $line = $attr instanceof AttributeNode ? $attr->line : $node->line;
            $column = $attr instanceof AttributeNode ? $attr->column : $node->column;

            if ($attr instanceof AttributeNode) {
                throw $context->createSyntaxExceptionForAttribute(
                    'Only one of s:block, s:append, or s:prepend is allowed on a single element.',
                    $attr,
                    $line,
                    $column,
                );
            }

            throw $context->createSyntaxExceptionForNode(
                'Only one of s:block, s:append, or s:prepend is allowed on a single element.',
                $node,
                $line,
                $column,
            );
        }

        $attributeName = $found[0] ?? null;
        if ($attributeName === null) {
            return null;
        }

        $name = AttributeHelper::getStringAttributeValue($node, $attributeName);
        if ($name === '') {
            return null;
        }

        $mode = match ($attributeName) {
            $appendAttr => BlockMergeMode::APPEND,
            $prependAttr => BlockMergeMode::PREPEND,
            default => BlockMergeMode::REPLACE,
        };

        return [
            'name' => $name,
            'mode' => $mode,
            'attributeName' => $attributeName,
        ];
    }

    /**
     * Merge child block into the parent based on merge mode.
     */
    private function mergeBlock(
        ElementNode|FragmentNode $parent,
        Node $child,
        BlockMergeMode $mode,
        string $attributeName,
    ): Node {
        if ($mode === BlockMergeMode::REPLACE) {
            return $this->replaceBlock($parent, $child, $attributeName);
        }

        return $this->mergeBlockChildren($parent, $child, $mode, $attributeName);
    }

    /**
     * Replace the parent block with the child block content.
     */
    private function replaceBlock(
        ElementNode|FragmentNode $parent,
        Node $child,
        string $attributeName,
    ): Node {
        if ($child instanceof ElementNode) {
            if ($parent instanceof ElementNode) {
                return NodeCloner::withChildren($parent, $child->children);
            }

            return $child;
        }

        if ($child instanceof FragmentNode) {
            $hasDirectives = $this->fragmentHasDirectives($child);
            $cleanAttrs = $this->removeBlockAttribute($child, $attributeName);

            if ($parent instanceof ElementNode) {
                if ($hasDirectives) {
                    $wrappedFragment = NodeCloner::fragmentWithChildren($child, $child->children);
                    $wrappedFragment = NodeCloner::fragmentWithAttributes($wrappedFragment, $cleanAttrs);

                    return NodeCloner::withChildren($parent, [$wrappedFragment]);
                }

                return NodeCloner::withChildren($parent, $child->children);
            }

            if ($hasDirectives) {
                return NodeCloner::fragmentWithAttributes($child, $cleanAttrs);
            }

            return NodeCloner::fragmentWithChildren($parent, $child->children);
        }

        return $parent;
    }

    /**
     * Append or prepend child block content into the parent block.
     */
    private function mergeBlockChildren(
        ElementNode|FragmentNode $parent,
        Node $child,
        BlockMergeMode $mode,
        string $attributeName,
    ): Node {
        if ($child instanceof ElementNode) {
            if ($parent instanceof ElementNode) {
                $merged = $this->mergeChildren($parent->children, $child->children, $mode);

                return NodeCloner::withChildren($parent, $merged);
            }

            $merged = $this->mergeChildren($parent->children, [$child], $mode);

            return NodeCloner::fragmentWithChildren($parent, $merged);
        }

        if ($child instanceof FragmentNode) {
            $hasDirectives = $this->fragmentHasDirectives($child);
            $cleanAttrs = $this->removeBlockAttribute($child, $attributeName);

            if ($parent instanceof ElementNode) {
                if ($hasDirectives) {
                    $wrappedFragment = NodeCloner::fragmentWithChildren($child, $child->children);
                    $wrappedFragment = NodeCloner::fragmentWithAttributes($wrappedFragment, $cleanAttrs);
                    $merged = $this->mergeChildren($parent->children, [$wrappedFragment], $mode);

                    return NodeCloner::withChildren($parent, $merged);
                }

                $merged = $this->mergeChildren($parent->children, $child->children, $mode);

                return NodeCloner::withChildren($parent, $merged);
            }

            if ($hasDirectives) {
                $wrappedFragment = NodeCloner::fragmentWithChildren($child, $child->children);
                $wrappedFragment = NodeCloner::fragmentWithAttributes($wrappedFragment, $cleanAttrs);
                $merged = $this->mergeChildren($parent->children, [$wrappedFragment], $mode);

                return NodeCloner::fragmentWithChildren($parent, $merged);
            }

            $merged = $this->mergeChildren($parent->children, $child->children, $mode);

            return NodeCloner::fragmentWithChildren($parent, $merged);
        }

        return $parent;
    }

    /**
     * @param array<\Sugar\Core\Ast\Node> $base
     * @param array<\Sugar\Core\Ast\Node> $additional
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function mergeChildren(array $base, array $additional, BlockMergeMode $mode): array
    {
        return match ($mode) {
            BlockMergeMode::APPEND => [...$base, ...$additional],
            BlockMergeMode::PREPEND => [...$additional, ...$base],
            BlockMergeMode::REPLACE => $additional,
        };
    }

    /**
     * Check for non-inheritance directives on a fragment.
     */
    private function fragmentHasDirectives(FragmentNode $node): bool
    {
        foreach ($node->attributes as $attr) {
            if (
                $this->prefixHelper->isDirective($attr->name) &&
                !$this->prefixHelper->isInheritanceAttribute($attr->name)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<\Sugar\Core\Ast\AttributeNode>
     */
    private function removeBlockAttribute(FragmentNode $node, string $attributeName): array
    {
        return AttributeHelper::removeAttribute($node->attributes, $attributeName);
    }
}
