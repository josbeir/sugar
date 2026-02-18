<?php
declare(strict_types=1);

namespace Sugar\Core\Template;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Helper\NodeCloner;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\PhpImportExtractor;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Directive\Helper\DirectiveClassifier;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser;
use Sugar\Core\Template\Support\ScopeIsolationTrait;

/**
 * Resolves template graph semantics for extends and include directives.
 */
final class TemplateResolver
{
    use ScopeIsolationTrait;

    /**
     * Cache of parsed template ASTs by resolved path.
     *
     * @var array<string, \Sugar\Core\Ast\DocumentNode>
     */
    private array $templateAstCache = [];

    private readonly PhpImportExtractor $phpImportExtractor;

    /**
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $loader Template loader
     * @param \Sugar\Core\Parser\Parser $parser Template parser
     * @param \Sugar\Core\Config\Helper\DirectivePrefixHelper $prefixHelper Directive prefix helper
     * @param \Sugar\Core\Directive\Helper\DirectiveClassifier $directiveClassifier Directive classifier
     * @param \Sugar\Core\Template\BlockMerger $blockMerger Block merger
     */
    public function __construct(
        private readonly TemplateLoaderInterface $loader,
        private readonly Parser $parser,
        private readonly DirectivePrefixHelper $prefixHelper,
        private readonly DirectiveClassifier $directiveClassifier,
        private readonly BlockMerger $blockMerger,
    ) {
        $this->phpImportExtractor = new PhpImportExtractor();
    }

    /**
     * Resolve inheritance/includes and block-merges for a template document.
     *
     * @param array<string> $loadedTemplates
     */
    public function resolve(
        DocumentNode $document,
        CompilationContext $context,
        array &$loadedTemplates,
    ): DocumentNode {
        $this->directiveClassifier->validateUnknownDirectivesInNodes($document->children, $context, false);
        $this->validateExtendsPlacement($document, $context);

        if ($context->blocks !== null) {
            $includeStack = [$context->templatePath];
            $resolved = $this->processIncludes($document, $context, $loadedTemplates, $includeStack);
            $extracted = $this->blockMerger->extractBlocks($resolved, $context->blocks, $context);

            return $this->prependTopLevelImportRawPhpNodes($resolved, $extracted);
        }

        $currentTemplate = $context->templatePath;

        if (in_array($currentTemplate, $loadedTemplates, true)) {
            $chain = [...$loadedTemplates, $currentTemplate];
            $extendsElement = $this->findExtendsDirective($document);

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
        $extendsElement = $this->findExtendsDirective($document);

        if ($extendsElement instanceof ElementNode || $extendsElement instanceof FragmentNode) {
            return $this->processExtends($extendsElement, $document, $context, $loadedTemplates);
        }

        $includeStack = [$context->templatePath];

        return $this->processIncludes($document, $context, $loadedTemplates, $includeStack);
    }

    /**
     * Find the root-level extends directive, if present.
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
     * Validate that extends appears only on root-level template nodes.
     */
    private function validateExtendsPlacement(DocumentNode $document, CompilationContext $context): void
    {
        foreach ($document->children as $child) {
            if (!($child instanceof ElementNode) && !($child instanceof FragmentNode)) {
                continue;
            }

            $nested = $this->findNestedExtendsInChildren($child->children);
            if (!($nested instanceof ElementNode) && !($nested instanceof FragmentNode)) {
                continue;
            }

            $extendsAttr = AttributeHelper::findAttribute(
                $nested->attributes,
                $this->prefixHelper->buildName('extends'),
            );

            $extendsName = $this->prefixHelper->buildName('extends');
            $message = sprintf('%s is only allowed on root-level template elements.', $extendsName);
            if ($extendsAttr instanceof AttributeNode) {
                throw $context->createSyntaxExceptionForAttribute($message, $extendsAttr);
            }

            throw $context->createSyntaxExceptionForNode($message, $nested);
        }
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
     * Get cached parsed template or parse and cache it.
     */
    private function getOrParseTemplate(string $resolvedPath, string $content): DocumentNode
    {
        if (!isset($this->templateAstCache[$resolvedPath])) {
            $this->templateAstCache[$resolvedPath] = $this->parser->parse($content);
        }

        return NodeCloner::cloneDocument($this->templateAstCache[$resolvedPath]);
    }

    /**
     * @param array<string> $loadedTemplates
     */
    private function processExtends(
        ElementNode|FragmentNode $extendsElement,
        DocumentNode $childDocument,
        CompilationContext $context,
        array &$loadedTemplates,
    ): DocumentNode {
        $parentPath = AttributeHelper::getStringAttributeValue(
            $extendsElement,
            $this->prefixHelper->buildName('extends'),
        );
        $resolvedPath = $this->loader->resolve($parentPath, $context->templatePath);
        $dependencyPath = $this->loader->sourcePath($resolvedPath) ?? $this->loader->sourceId($resolvedPath);

        $context->tracker?->addDependency($dependencyPath);

        $parentContent = $this->loader->load($resolvedPath);
        $parentDocument = $this->getOrParseTemplate($resolvedPath, $parentContent);

        $parentContext = new CompilationContext(
            templatePath: $resolvedPath,
            source: $parentContent,
            debug: $context->debug,
            tracker: $context->tracker,
        );
        $parentContext->stampTemplatePath($parentDocument);

        $includeStack = [$context->templatePath];
        $childDocument = $this->processIncludes($childDocument, $context, $loadedTemplates, $includeStack);
        $childImportNodes = $this->collectTopLevelPhpImportNodes($childDocument);
        $childBlocks = $this->blockMerger->collectBlocks($childDocument, $context);
        $parentDocument = $this->blockMerger->replaceBlocks($parentDocument, $childBlocks);

        if ($childImportNodes !== []) {
            $parentDocument = new DocumentNode([...$childImportNodes, ...$parentDocument->children]);
        }

        return $this->resolve($parentDocument, $parentContext, $loadedTemplates);
    }

    /**
     * @param array<string> $loadedTemplates
     * @param array<string> $includeStack
     */
    private function processIncludes(
        DocumentNode $document,
        CompilationContext $context,
        array &$loadedTemplates,
        array &$includeStack,
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
                $dependencyPath = $this->loader->sourcePath($resolvedPath) ?? $this->loader->sourceId($resolvedPath);

                if (in_array($resolvedPath, $includeStack, true)) {
                    $chain = [...$includeStack, $resolvedPath];
                    $message = sprintf('Circular template include detected: %s', implode(' -> ', $chain));
                    $includeAttr = AttributeHelper::findAttribute($child->attributes, $includeName);

                    if ($includeAttr instanceof AttributeNode) {
                        throw $context->createSyntaxExceptionForAttribute($message, $includeAttr);
                    }

                    throw $context->createSyntaxExceptionForNode($message, $child);
                }

                $context->tracker?->addDependency($dependencyPath);

                $includeContent = $this->loader->load($resolvedPath);
                $includeDocument = $this->getOrParseTemplate($resolvedPath, $includeContent);

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

                $includeStack[] = $resolvedPath;
                $includeDocument = $this->processIncludes(
                    $includeDocument,
                    $includeContext,
                    $loadedTemplates,
                    $includeStack,
                );
                array_pop($includeStack);
                $includeChildren = $includeDocument->children;

                if (AttributeHelper::hasAttribute($child, $this->prefixHelper->buildName('with'))) {
                    $withValue = AttributeHelper::getStringAttributeValue(
                        $child,
                        $withName,
                    );
                    $wrapped = $this->wrapInIsolatedScope($includeDocument, $withValue);
                    $includeChildren = $wrapped->children;
                }

                if ($child instanceof ElementNode) {
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
                    array_push($newChildren, ...$includeChildren);
                }
            } elseif ($child instanceof ElementNode || $child instanceof FragmentNode) {
                $processedChildren = $this->processChildrenIncludes(
                    $child->children,
                    $context,
                    $loadedTemplates,
                    $includeStack,
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
     * @param array<\Sugar\Core\Ast\Node> $children
     * @param array<string> $loadedTemplates
     * @param array<string> $includeStack
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function processChildrenIncludes(
        array $children,
        CompilationContext $context,
        array &$loadedTemplates,
        array &$includeStack,
    ): array {
        $doc = new DocumentNode($children);
        $processed = $this->processIncludes($doc, $context, $loadedTemplates, $includeStack);

        return $processed->children;
    }

    /**
     * Prepend top-level PHP import nodes from source to extracted block document.
     *
     * Used when rendering specific blocks: imports declared anywhere in the template's
     * top-level raw PHP blocks are hoisted into the extracted document so the
     * normalization pass can later emit them at file scope.
     */
    private function prependTopLevelImportRawPhpNodes(DocumentNode $source, DocumentNode $extracted): DocumentNode
    {
        $imports = $this->collectTopLevelPhpImportNodes($source);

        if ($imports === []) {
            return $extracted;
        }

        return new DocumentNode([...$imports, ...$extracted->children]);
    }

    /**
     * Collect canonical {@see PhpImportNode} instances from top-level raw PHP blocks.
     *
     * Only leading import statements are extracted from each block; remaining
     * executable code stays at its original location in the document.
     *
     * @return array<\Sugar\Core\Ast\PhpImportNode>
     */
    private function collectTopLevelPhpImportNodes(DocumentNode $source): array
    {
        $imports = [];

        foreach ($source->children as $child) {
            if (!($child instanceof RawPhpNode)) {
                continue;
            }

            [$importNodes] = $this->phpImportExtractor->extractImportNodes($child);
            array_push($imports, ...$importNodes);
        }

        return $imports;
    }
}
