<?php
declare(strict_types=1);

namespace Sugar\Core\Template;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Helper\NodeCloner;
use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\Helper\DirectiveClassifier;
use Sugar\Core\Extension\DirectiveRegistryInterface;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser;

/**
 * Orchestrates template composition and inheritance-attribute cleanup.
 */
final class TemplateComposer
{
    private readonly DirectivePrefixHelper $prefixHelper;

    private readonly TemplateResolver $resolver;

    /**
     * Stack of loaded templates for circular detection.
     *
     * @var array<string>
     */
    private array $loadedTemplates = [];

    /**
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $loader Template loader
     * @param \Sugar\Core\Parser\Parser $parser Template parser
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface $registry Directive registry
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     */
    public function __construct(
        TemplateLoaderInterface $loader,
        Parser $parser,
        DirectiveRegistryInterface $registry,
        SugarConfig $config,
    ) {
        $this->prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
        $directiveClassifier = new DirectiveClassifier($registry, $this->prefixHelper);
        $blockMerger = new BlockMerger($this->prefixHelper);
        $this->resolver = new TemplateResolver(
            loader: $loader,
            parser: $parser,
            prefixHelper: $this->prefixHelper,
            directiveClassifier: $directiveClassifier,
            blockMerger: $blockMerger,
        );
    }

    /**
     * Compose inheritance and include semantics for a template document.
     */
    public function compose(DocumentNode $document, CompilationContext $context): DocumentNode
    {
        $this->loadedTemplates = [];

        return $this->process($document, $context, $this->loadedTemplates);
    }

    /**
     * Process template inheritance (s:extends, s:block, s:include).
     *
     * @param array<string> $loadedTemplates Stack of loaded templates for circular detection
     */
    private function process(
        DocumentNode $document,
        CompilationContext $context,
        array &$loadedTemplates,
    ): DocumentNode {
        $resolved = $this->resolver->resolve($document, $context, $loadedTemplates);

        return $this->removeInheritanceAttributes($resolved);
    }

    /**
     * Remove template inheritance attributes from the AST.
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
     */
    private function removeInheritanceAttributesFromNode(Node $node): Node
    {
        if (!($node instanceof ElementNode) && !($node instanceof FragmentNode)) {
            return $node;
        }

        $cleanAttributes = AttributeHelper::filterAttributes(
            $node->attributes,
            fn(AttributeNode $attr): bool => !$this->prefixHelper->isInheritanceAttribute($attr->name),
        );

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
