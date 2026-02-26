<?php
declare(strict_types=1);

namespace Sugar\Core\Pass\Inheritance;

use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\Helper\AttributeHelper;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\PhpImportNode;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Ast\RuntimeCallNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Compiler\CodeGen\GeneratedAlias;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Config\Helper\DirectivePrefixHelper;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Loader\TemplateLoaderInterface;

/**
 * Compiler pass that transforms template inheritance attributes into runtime calls.
 *
 * Replaces compile-time AST merging with runtime rendering:
 * - `s:extends` emits block definitions and a `renderExtends()` call
 * - `s:block` emits `renderBlock()` calls with default content closures
 * - `s:append` / `s:prepend` emit `renderBlock()` with `renderParent()` calls
 * - `s:parent` emits `renderParent()` calls
 * - `s:include` emits `renderInclude()` calls
 * - `s:with` on includes passes explicit variable arrays
 *
 * This pass runs at POST_DIRECTIVE_COMPILATION priority, after s:foreach/s:if etc.
 * are already compiled, so block content is valid PHP when wrapped in closures.
 */
final class InheritanceCompilationPass implements AstPassInterface
{
    private readonly Escaper $escaper;

    private readonly DirectivePrefixHelper $prefixHelper;

    private readonly string $extendsAttr;

    private readonly string $blockAttr;

    private readonly string $appendAttr;

    private readonly string $prependAttr;

    private readonly string $parentAttr;

    private readonly string $includeAttr;

    private readonly string $withAttr;

    /**
     * Track the current block name when processing block content.
     */
    private ?string $currentBlockName = null;

    /**
     * Track whether the current document uses extends.
     */
    private bool $isExtendsDocument = false;

    /**
     * Track whether any layout blocks were processed in non-extends context.
     */
    private bool $hasLayoutBlocks = false;

    /**
     * Accumulated PHP use imports extracted from block closures.
     *
     * @var array<string>
     */
    private array $collectedImports = [];

    /**
     * Optional block name filter for blocks-only rendering.
     *
     * When set, only blocks whose names appear in this list are emitted;
     * all other content is stripped from the document.
     *
     * @var array<string>|null
     */
    private ?array $blocksFilter = null;

    /**
     * Top-level `s:include` nodes found in an extends-child document.
     *
     * These includes are removed from the document's regular content (which would
     * otherwise be stripped as non-block content) and emitted as pre-extends
     * `renderInclude()` calls. This lets included partials call `defineBlock()`
     * while in defining context — before `renderExtends()` fires.
     *
     * Each entry is an array with `path` (string) and `vars` (string) keys.
     *
     * @var array<array{path: string, vars: string, line: int, column: int}>
     */
    private array $topLevelExtendIncludes = [];

    /**
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration for directive prefix
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $loader Template loader for path resolution
     */
    public function __construct(
        SugarConfig $config,
        private readonly TemplateLoaderInterface $loader,
    ) {
        $this->escaper = new Escaper();
        $this->prefixHelper = new DirectivePrefixHelper($config->directivePrefix);
        $this->extendsAttr = $this->prefixHelper->buildName('extends');
        $this->blockAttr = $this->prefixHelper->buildName('block');
        $this->appendAttr = $this->prefixHelper->buildName('append');
        $this->prependAttr = $this->prefixHelper->buildName('prepend');
        $this->parentAttr = $this->prefixHelper->buildName('parent');
        $this->includeAttr = $this->prefixHelper->buildName('include');
        $this->withAttr = $this->prefixHelper->buildName('with');
    }

    /**
     * Process nodes before child traversal.
     *
     * Tracks extends and block context so that after() can correctly determine
     * whether s:parent placeholders are inside block definitions.
     */
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        // Detect extends context at document level
        if ($node instanceof DocumentNode) {
            $this->isExtendsDocument = $this->findExtendsElement($node) !== null;
            $this->hasLayoutBlocks = false;
            $this->collectedImports = [];
            $this->blocksFilter = $context->compilation->blocks;
            $this->topLevelExtendIncludes = [];
        }

        // Track when we enter a block definition in an extends context
        if (
            $this->isExtendsDocument
            && ($node instanceof ElementNode || $node instanceof FragmentNode)
        ) {
            $blockInfo = $this->getBlockName($node);
            if ($blockInfo !== null) {
                $this->currentBlockName = $blockInfo['name'];
            }
        }

        return NodeAction::none();
    }

    /**
     * Process nodes after child traversal (post-order).
     *
     * Handles inheritance transformations in bottom-up order so that
     * nested blocks and includes are already transformed when their
     * parent containers are processed.
     */
    public function after(Node $node, PipelineContext $context): NodeAction
    {
        // Handle document-level extends
        if ($node instanceof DocumentNode) {
            $result = $this->processDocument($node, $context);
            $this->isExtendsDocument = false;

            return $result;
        }

        // Handle element/fragment-level directives
        if ($node instanceof ElementNode || $node instanceof FragmentNode) {
            $result = $this->processNode($node, $context);

            // Clear block context after processing (in extends documents)
            if ($this->isExtendsDocument) {
                $blockInfo = $this->getBlockName($node);
                if ($blockInfo !== null) {
                    $this->currentBlockName = null;
                }
            }

            return $result;
        }

        return NodeAction::none();
    }

    /**
     * Process a document node for extends handling.
     *
     * When a document contains a root-level `s:extends`, collects all child block
     * definitions and emits a runtime extends call. When a blocks filter is active,
     * the extends is skipped and only the matching blocks are rendered directly.
     */
    private function processDocument(DocumentNode $node, PipelineContext $context): NodeAction
    {
        $extendsElement = $this->findExtendsElement($node);
        if ($extendsElement === null) {
            // No extends — just strip remaining inheritance attributes from all children
            return $this->stripInheritanceAttributes($node);
        }

        // Collect imports from non-block children (e.g. top-level use statements)
        foreach ($node->children as $child) {
            if ($child instanceof PhpImportNode) {
                $this->collectedImports[] = $child->statement;
            }
        }

        // When blocks filter is active, skip extends and emit matching blocks directly
        if ($this->blocksFilter !== null) {
            return $this->processExtendsWithBlocksFilter($node, $context);
        }

        $parentPath = AttributeHelper::getStringAttributeValue($extendsElement, $this->extendsAttr);
        $resolvedPath = $this->resolveTemplatePath($parentPath, $context);

        // Validate extends placement
        $this->validateExtendsPlacement($node, $context);

        // Collect block definitions from the child document
        $blockDefs = $this->collectBlockDefinitions($node, $context);

        // Build the runtime PHP code (this also populates $this->collectedImports)
        $phpNodes = $this->buildExtendsRuntimeNodes($resolvedPath, $blockDefs, $node);

        // Prepend any extracted imports as file-level nodes
        $importNodes = $this->buildImportNodes($node);
        $phpNodes = [...$importNodes, ...$phpNodes];

        $newDoc = new DocumentNode($phpNodes);
        $newDoc->inheritTemplatePathFrom($node);

        return NodeAction::replace($newDoc);
    }

    /**
     * Process an extends document when blocks filter is active.
     *
     * Instead of emitting a runtime extends call, skips the parent template
     * entirely and outputs only the matching child block contents directly.
     * This enables rendering specific blocks in isolation without inheritance.
     */
    private function processExtendsWithBlocksFilter(
        DocumentNode $node,
        PipelineContext $context,
    ): NodeAction {
        assert($this->blocksFilter !== null);
        $blockDefs = $this->collectBlockDefinitions($node, $context);
        $newChildren = [];

        foreach ($blockDefs as $name => $blockDef) {
            if (!in_array($name, $this->blocksFilter, true)) {
                continue;
            }

            $blockNode = $blockDef['node'];

            if ($blockNode instanceof ElementNode) {
                $cleanAttrs = $this->removeInheritanceAttributes($blockNode->attributes);
                $newElement = new ElementNode(
                    tag: $blockNode->tag,
                    attributes: $cleanAttrs,
                    children: $blockNode->children,
                    selfClosing: $blockNode->selfClosing,
                    line: $blockNode->line,
                    column: $blockNode->column,
                );
                $newElement->inheritTemplatePathFrom($blockNode);
                $newChildren[] = $newElement;
            } else {
                // Fragment — output children directly
                foreach ($blockNode->children as $child) {
                    $newChildren[] = $child;
                }
            }
        }

        // Prepend any extracted imports as file-level nodes
        $importNodes = $this->buildImportNodes($node);
        $newChildren = [...$importNodes, ...$newChildren];

        $newDoc = new DocumentNode($newChildren);
        $newDoc->inheritTemplatePathFrom($node);

        return NodeAction::replace($newDoc);
    }

    /**
     * Process an element or fragment for include/block/parent handling.
     *
     * In extends documents, blocks are NOT processed here — they're collected
     * by processDocument() and emitted as defineBlock() calls. Only s:parent
     * placeholders and s:include directives are processed during traversal.
     *
     * Top-level s:include nodes in extends documents are a special case: they are
     * collected for pre-extends emission (see buildExtendsRuntimeNodes) and removed
     * from the document so they are not treated as discarded non-block content.
     */
    private function processNode(
        ElementNode|FragmentNode $node,
        PipelineContext $context,
    ): NodeAction {
        // Handle s:include
        if (AttributeHelper::hasAttribute($node, $this->includeAttr)) {
            // Top-level includes in extends documents are collected for pre-extends
            // emission, allowing included partials to call defineBlock() before the
            // parent layout renders.
            if ($this->isExtendsDocument && $context->parent instanceof DocumentNode) {
                $this->collectTopLevelExtendInclude($node, $context);

                return NodeAction::replace([]);
            }

            return $this->processInclude($node, $context);
        }

        // Handle s:parent placeholder (valid in both extends child blocks and layout blocks)
        if (AttributeHelper::hasAttribute($node, $this->parentAttr)) {
            return $this->processParentPlaceholder($node, $context);
        }

        // Handle s:block / s:append / s:prepend ONLY in layout/parent templates (not in extends)
        if (!$this->isExtendsDocument) {
            $blockName = $this->getBlockName($node);
            if ($blockName !== null) {
                return $this->processBlock($node, $blockName);
            }
        }

        return NodeAction::none();
    }

    /**
     * Process an include directive on an element or fragment.
     *
     * Transforms `<div s:include="path">` into
     * `<div><?php echo $__tpl->renderInclude('path', get_defined_vars()); ?></div>`
     */
    private function processInclude(
        ElementNode|FragmentNode $node,
        PipelineContext $context,
    ): NodeAction {
        $includePath = AttributeHelper::getStringAttributeValue($node, $this->includeAttr);
        $resolvedPath = $this->resolveTemplatePath($includePath, $context);

        // Determine variable expression
        $hasWith = AttributeHelper::hasAttribute($node, $this->withAttr);
        if ($hasWith) {
            $varsExpression = AttributeHelper::getStringAttributeValue($node, $this->withAttr);
        } else {
            $varsExpression = $this->buildGetDefinedVarsExpression();
        }

        $runtimeCall = $this->buildIncludeRuntimeCall($resolvedPath, $varsExpression, $node);

        if ($node instanceof ElementNode) {
            // Keep the element tag, replace children with the include call
            $cleanAttrs = $this->removeInheritanceAttributes($node->attributes);
            $newElement = new ElementNode(
                tag: $node->tag,
                attributes: $cleanAttrs,
                children: [$runtimeCall],
                selfClosing: false,
                line: $node->line,
                column: $node->column,
            );
            $newElement->inheritTemplatePathFrom($node);

            return NodeAction::replace($newElement);
        }

        // Fragment — just output the runtime call directly
        return NodeAction::replace($runtimeCall);
    }

    /**
     * Process a block definition in a parent/layout template.
     *
     * Transforms `<main s:block="content">default content</main>` into
     * `<main><?php echo $__tpl->getBlockManager()->renderBlock('content', fn($__data) => ..., ...); ?></main>`
     *
     * @param array{name: string, mode: string} $blockInfo Block name and mode
     */
    private function processBlock(
        ElementNode|FragmentNode $node,
        array $blockInfo,
    ): NodeAction {
        $name = $blockInfo['name'];

        // When blocks filter is active, skip blocks not in the filter list
        if ($this->blocksFilter !== null && !in_array($name, $this->blocksFilter, true)) {
            return NodeAction::replace([]);
        }

        $this->hasLayoutBlocks = true;

        $runtimeCall = $this->buildBlockRuntimeCall($name, $node);

        if ($node instanceof ElementNode) {
            $cleanAttrs = $this->removeInheritanceAttributes($node->attributes);
            $newElement = new ElementNode(
                tag: $node->tag,
                attributes: $cleanAttrs,
                children: [$runtimeCall],
                selfClosing: false,
                line: $node->line,
                column: $node->column,
            );
            $newElement->inheritTemplatePathFrom($node);

            return NodeAction::replace($newElement);
        }

        // Fragment with block — output the runtime call
        return NodeAction::replace($runtimeCall);
    }

    /**
     * Process an s:parent placeholder.
     *
     * This is only valid inside a block definition in a child template.
     * It emits a renderParent() call that renders the parent's version of the block.
     */
    private function processParentPlaceholder(
        ElementNode|FragmentNode $node,
        PipelineContext $context,
    ): NodeAction {
        $this->validateParentPlaceholder($node, $context);

        if ($this->currentBlockName === null) {
            $parentAttrNode = AttributeHelper::findAttribute($node->attributes, $this->parentAttr);
            $fallbackAttr = new AttributeNode(
                $this->parentAttr,
                AttributeValue::static(''),
                $node->line,
                $node->column,
            );
            throw $context->compilation->createSyntaxExceptionForAttribute(
                sprintf('%s is only allowed inside %s.', $this->parentAttr, $this->blockAttr),
                $parentAttrNode ?? $fallbackAttr,
            );
        }

        // The parent placeholder becomes a special marker that the extends processing will handle.
        // In a runtime context, parent content is rendered by the BlockManager.
        // Output a marker that will be replaced during extends processing.
        $parentDefaultVar = '$__parentDefault_' . $this->sanitizeBlockVarName($this->currentBlockName);
        $runtimeCall = new RawPhpNode(
            sprintf(
                'echo $__tpl->getBlockManager()->renderParent('
                    . '%s, %s ?? static fn(array $__data): string => \'\', get_defined_vars()); ',
                var_export($this->currentBlockName, true),
                $parentDefaultVar,
            ),
            $node->line,
            $node->column,
        );
        $runtimeCall->inheritTemplatePathFrom($node);

        return NodeAction::replace($runtimeCall);
    }

    /**
     * Find the root-level extends element in a document.
     */
    private function findExtendsElement(DocumentNode $document): ElementNode|FragmentNode|null
    {
        foreach ($document->children as $child) {
            if (
                ($child instanceof ElementNode || $child instanceof FragmentNode)
                && AttributeHelper::hasAttribute($child, $this->extendsAttr)
            ) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Validate that extends is only used at root level.
     */
    private function validateExtendsPlacement(DocumentNode $document, PipelineContext $context): void
    {
        foreach ($document->children as $child) {
            if (!($child instanceof ElementNode) && !($child instanceof FragmentNode)) {
                continue;
            }

            $nested = $this->findNestedExtends($child->children);
            if ($nested === null) {
                continue;
            }

            $extendsAttrNode = AttributeHelper::findAttribute($nested->attributes, $this->extendsAttr);
            $message = sprintf('%s is only allowed on root-level template elements.', $this->extendsAttr);

            if ($extendsAttrNode instanceof AttributeNode) {
                throw $context->compilation->createSyntaxExceptionForAttribute($message, $extendsAttrNode);
            }

            throw $context->compilation->createSyntaxExceptionForNode($message, $nested);
        }
    }

    /**
     * Find nested extends directives in children.
     *
     * @param array<\Sugar\Core\Ast\Node> $children
     */
    private function findNestedExtends(array $children): ElementNode|FragmentNode|null
    {
        foreach ($children as $child) {
            if (!($child instanceof ElementNode) && !($child instanceof FragmentNode)) {
                continue;
            }

            if (AttributeHelper::hasAttribute($child, $this->extendsAttr)) {
                return $child;
            }

            $nested = $this->findNestedExtends($child->children);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * Collect block definitions from a child document that uses extends.
     *
     * Blocks can be either direct children of the document (siblings of the extends element)
     * or children of the extends element itself.
     *
     * @return array<string, array{node: \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\FragmentNode, mode: string, children: array<\Sugar\Core\Ast\Node>}>
     */
    private function collectBlockDefinitions(DocumentNode $document, PipelineContext $context): array
    {
        $blocks = [];
        $extendsElement = $this->findExtendsElement($document);

        // Collect from document-level children (siblings of extends)
        foreach ($document->children as $child) {
            if (!($child instanceof ElementNode) && !($child instanceof FragmentNode)) {
                continue;
            }

            // Skip the extends element itself
            if (AttributeHelper::hasAttribute($child, $this->extendsAttr)) {
                continue;
            }

            $this->addBlockDefinition($child, $blocks, $context);
        }

        // Collect from inside the extends element (children of extends)
        if ($extendsElement !== null) {
            foreach ($extendsElement->children as $child) {
                if (!($child instanceof ElementNode) && !($child instanceof FragmentNode)) {
                    continue;
                }

                $this->addBlockDefinition($child, $blocks, $context);
            }
        }

        return $blocks;
    }

    /**
     * Add a block definition from a node to the blocks array.
     *
     * @param array<string, array{node: \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\FragmentNode, mode: string, children: array<\Sugar\Core\Ast\Node>}> $blocks
     */
    private function addBlockDefinition(
        ElementNode|FragmentNode $node,
        array &$blocks,
        PipelineContext $context,
    ): void {
        $blockInfo = $this->getBlockName($node);
        if ($blockInfo === null) {
            return;
        }

        $name = $blockInfo['name'];

        if (isset($blocks[$name])) {
            throw $context->compilation->createSyntaxExceptionForNode(
                sprintf(
                    'Block "%s" is defined multiple times in the same child template. '
                    . 'Define it once and use %s inside %s.',
                    $name,
                    $this->parentAttr,
                    $this->blockAttr,
                ),
                $node,
            );
        }

        $blocks[$name] = [
            'node' => $node,
            'mode' => $blockInfo['mode'],
            'children' => $node->children,
        ];
    }

    /**
     * Get the block name and mode from a node's inheritance attributes.
     *
     * @return array{name: string, mode: string}|null
     */
    private function getBlockName(ElementNode|FragmentNode $node): ?array
    {
        $blockValue = AttributeHelper::getStringAttributeValue($node, $this->blockAttr);
        if ($blockValue !== '') {
            return ['name' => $blockValue, 'mode' => 'replace'];
        }

        $appendValue = AttributeHelper::getStringAttributeValue($node, $this->appendAttr);
        if ($appendValue !== '') {
            return ['name' => $appendValue, 'mode' => 'append'];
        }

        $prependValue = AttributeHelper::getStringAttributeValue($node, $this->prependAttr);
        if ($prependValue !== '') {
            return ['name' => $prependValue, 'mode' => 'prepend'];
        }

        return null;
    }

    /**
     * Build the runtime PHP nodes for an extends template.
     *
     * Emits defineBlock() calls for each child block, then renderExtends().
     *
     * @param array<string, array{node: \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\FragmentNode, mode: string, children: array<\Sugar\Core\Ast\Node>}> $blockDefs
     * @return array<\Sugar\Core\Ast\Node>
     */
    private function buildExtendsRuntimeNodes(
        string $parentPath,
        array $blockDefs,
        DocumentNode $document,
    ): array {
        $nodes = [];

        // Emit: $__tpl = __SugarRuntimeEnvironment::requireService(__SugarTemplateRenderer::class);
        $nodes[] = $this->createPhpNode(
            '$__tpl = ' . GeneratedAlias::RUNTIME_ENV . '::requireService('
            . GeneratedAlias::TEMPLATE_RENDERER . '::class'
            . '); ',
            $document->line ?? 0,
            $document->column ?? 0,
            $document,
        );

        // Emit pre-extends include calls so that partials with s:block can register
        // their content as child block overrides before the parent layout renders.
        // Each include is wrapped in enter/exitBlockRegistration() so that renderBlock()
        // in the partial stores the content via defineBlock() instead of rendering it.
        // Output of the include is intentionally discarded (no echo).
        foreach ($this->topLevelExtendIncludes as $include) {
            $nodes[] = $this->createPhpNode(
                '$__tpl->getBlockManager()->enterBlockRegistration(); '
                . 'try { $__tpl->renderInclude('
                . var_export($include['path'], true) . ', '
                . $include['vars'] . '); } '
                . 'finally { $__tpl->getBlockManager()->exitBlockRegistration(); } ',
                $include['line'],
                $include['column'],
                $document,
            );
        }

        // Emit block definitions
        foreach ($blockDefs as $name => $blockDef) {
            $nodes[] = $this->buildBlockDefinitionNode($name, $blockDef);
        }

        // Emit: echo $__tpl->renderExtends('parentPath', get_defined_vars());
        $nodes[] = $this->createPhpNode(
            'echo $__tpl->renderExtends('
            . var_export($parentPath, true)
            . ', get_defined_vars()); ',
            $document->line ?? 0,
            $document->column ?? 0,
            $document,
        );

        return $nodes;
    }

    /**
     * Build a defineBlock() RawPhpNode for a child block definition.
     *
     * @param array{mode: string, node: \Sugar\Core\Ast\ElementNode|\Sugar\Core\Ast\FragmentNode} $blockDef Block definition data
     */
    private function buildBlockDefinitionNode(
        string $name,
        array $blockDef,
    ): RawPhpNode {
        $mode = $blockDef['mode'];
        $node = $blockDef['node'];

        // For append/prepend modes, we need to wrap the content to include a renderParent call
        $blockContent = $this->captureNodeContent($node);
        $sanitizedName = $this->sanitizeBlockVarName($name);
        $parentDefaultVar = '$__parentDefault_' . $sanitizedName;
        $exportedName = var_export($name, true);
        $defaultFn = ' = static fn(array $__data): string => \'\'; ';

        if ($mode === 'append') {
            // Append: parent content first, then child content
            $code = '$__tpl->getBlockManager()->defineBlock('
                . $exportedName
                . ', function(array $__data) use ($__tpl): string { extract($__data, EXTR_SKIP); '
                . $parentDefaultVar . $defaultFn
                . 'ob_start(); '
                . 'echo $__tpl->getBlockManager()->renderParent(' . $exportedName . ', '
                . $parentDefaultVar
                . ', $__data); ?>'
                . $blockContent
                . '<?php return ob_get_clean(); }); ';
        } elseif ($mode === 'prepend') {
            // Prepend: child content first, then parent content
            $code = '$__tpl->getBlockManager()->defineBlock('
                . $exportedName
                . ', function(array $__data) use ($__tpl): string { extract($__data, EXTR_SKIP); '
                . $parentDefaultVar . $defaultFn
                . 'ob_start(); ?>'
                . $blockContent
                . '<?php echo $__tpl->getBlockManager()->renderParent(' . $exportedName . ', '
                . $parentDefaultVar
                . ', $__data); '
                . 'return ob_get_clean(); }); ';
        } else {
            // Replace mode
            $code = '$__tpl->getBlockManager()->defineBlock('
                . $exportedName
                . ', function(array $__data) use ($__tpl): string { extract($__data, EXTR_SKIP); '
                . $parentDefaultVar . $defaultFn
                . 'ob_start(); ?>'
                . $blockContent
                . '<?php return ob_get_clean(); }); ';
        }

        return $this->createPhpNode($code, $node->line, $node->column, $node);
    }

    /**
     * Build a renderBlock() RuntimeCallNode for a block in a parent/layout template.
     */
    private function buildBlockRuntimeCall(
        string $name,
        ElementNode|FragmentNode $node,
    ): RawPhpNode {
        $blockContent = $this->captureChildrenContent($node);

        // The block in a layout template emits a renderBlock() call.
        // The default closure contains the inline parent content.
        $code = 'echo $__tpl->getBlockManager()->renderBlock('
            . var_export($name, true) . ', '
            . 'function(array $__data) use ($__tpl): string { extract($__data, EXTR_SKIP); '
            . 'ob_start(); ?>'
            . $blockContent
            . '<?php return ob_get_clean(); }, '
            . 'get_defined_vars()); ';

        return $this->createPhpNode($code, $node->line, $node->column, $node);
    }

    /**
     * Collect a top-level s:include from an extends-child document.
     *
     * These includes are emitted before the defineBlock() calls so that any
     * s:block directives inside the partial can call defineBlock() while still
     * in defining context (before renderExtends() pushes the rendering level).
     */
    private function collectTopLevelExtendInclude(
        ElementNode|FragmentNode $node,
        PipelineContext $context,
    ): void {
        $includePath = AttributeHelper::getStringAttributeValue($node, $this->includeAttr);
        $resolvedPath = $this->resolveTemplatePath($includePath, $context);

        $hasWith = AttributeHelper::hasAttribute($node, $this->withAttr);
        $varsExpression = $hasWith
            ? AttributeHelper::getStringAttributeValue($node, $this->withAttr)
            : $this->buildGetDefinedVarsExpression();

        $this->topLevelExtendIncludes[] = [
            'path' => $resolvedPath,
            'vars' => $varsExpression,
            'line' => $node->line,
            'column' => $node->column,
        ];
    }

    /**
     * Build a renderInclude() RuntimeCallNode.
     */
    private function buildIncludeRuntimeCall(
        string $templatePath,
        string $varsExpression,
        ElementNode|FragmentNode $node,
    ): RawPhpNode {
        $tplInit = '$__tpl = $__tpl ?? ' . GeneratedAlias::RUNTIME_ENV . '::requireService('
            . GeneratedAlias::TEMPLATE_RENDERER . '::class' . '); ';

        $code = $tplInit
            . 'echo $__tpl->renderInclude('
            . var_export($templatePath, true) . ', '
            . $varsExpression . '); ';

        return $this->createPhpNode($code, $node->line, $node->column, $node);
    }

    /**
     * Build a get_defined_vars() expression that cleans up internal variables.
     */
    private function buildGetDefinedVarsExpression(): string
    {
        return 'get_defined_vars()';
    }

    /**
     * Capture the already-compiled content of a node's children as an HTML string.
     *
     * Since this pass runs after directive compilation, the children are already
     * compiled AST nodes (RawPhpNode, TextNode, OutputNode, etc.). We need to
     * serialize them back to the HTML/PHP string they represent.
     */
    private function captureNodeContent(ElementNode|FragmentNode $node): string
    {
        return $this->captureChildrenContent($node);
    }

    /**
     * Capture children content as HTML/PHP string.
     *
     * PhpImportNode instances (created by PhpNormalizationPass during child
     * walking) are collected in $collectedImports for hoisting to file level,
     * since use-imports cannot appear inside closure bodies.
     */
    private function captureChildrenContent(ElementNode|FragmentNode $node): string
    {
        $buffer = '';
        foreach ($node->children as $child) {
            if ($child instanceof PhpImportNode) {
                $this->collectedImports[] = $child->statement;

                continue;
            }

            $buffer .= $this->nodeToString($child);
        }

        return $buffer;
    }

    /**
     * Build RawPhpNodes for accumulated use-import statements.
     *
     * These nodes will be added to the document level where PhpNormalizationPass
     * can hoist them above the render closure.
     *
     * @return array<\Sugar\Core\Ast\RawPhpNode>
     */
    private function buildImportNodes(DocumentNode $node): array
    {
        if ($this->collectedImports === []) {
            return [];
        }

        $unique = array_unique($this->collectedImports);
        $nodes = [];

        foreach ($unique as $import) {
            $nodes[] = $this->createPhpNode(
                $import . ' ',
                0,
                0,
                $node,
            );
        }

        return $nodes;
    }

    /**
     * Convert an already-compiled AST node back to its PHP/HTML string representation.
     *
     * This is needed because the InheritanceCompilationPass runs after directive
     * compilation, so the AST nodes already contain compiled PHP code. We need to
     * serialize them into strings to wrap them in closures.
     */
    private function nodeToString(Node $node): string
    {
        if ($node instanceof TextNode) {
            return $node->content;
        }

        // PhpImportNode is handled-by-collection in captureChildrenContent();
        // if we encounter one here it was already collected, so skip it.
        if ($node instanceof PhpImportNode) {
            return '';
        }

        if ($node instanceof RawPhpNode) {
            return '<?php ' . trim($node->code) . ' ?>';
        }

        if ($node instanceof RuntimeCallNode) {
            $arguments = implode(', ', $node->arguments);

            return sprintf('<?php echo %s(%s); ?>', $node->callableExpression, $arguments);
        }

        if ($node instanceof ElementNode) {
            return $this->elementToString($node);
        }

        if ($node instanceof FragmentNode) {
            $buffer = '';
            foreach ($node->children as $child) {
                $buffer .= $this->nodeToString($child);
            }

            return $buffer;
        }

        // For any other node type (OutputNode, etc.), fallback
        if ($node instanceof OutputNode) {
            if ($node->escape) {
                return $this->escapedOutputToString($node);
            }

            return sprintf('<?php echo %s; ?>', $node->expression);
        }

        // DirectiveNode — should already be compiled by this point
        if ($node instanceof DirectiveNode) {
            $buffer = '';
            foreach ($node->children as $child) {
                $buffer .= $this->nodeToString($child);
            }

            return $buffer;
        }

        return '';
    }

    /**
     * Convert an ElementNode to its HTML string representation.
     */
    private function elementToString(ElementNode $node): string
    {
        $buffer = '<' . $node->tag;

        foreach ($node->attributes as $attr) {
            $buffer .= $this->attributeToString($attr);
        }

        if ($node->selfClosing) {
            return $buffer . ' />';
        }

        $buffer .= '>';
        foreach ($node->children as $child) {
            $buffer .= $this->nodeToString($child);
        }

        return $buffer . ('</' . $node->tag . '>');
    }

    /**
     * Convert an AttributeNode to its HTML string representation.
     */
    private function attributeToString(AttributeNode $attr): string
    {
        // Skip inheritance attributes
        if ($this->prefixHelper->isInheritanceAttribute($attr->name)) {
            return '';
        }

        if ($attr->value->isBoolean()) {
            return ' ' . $attr->name;
        }

        $parts = $attr->value->toParts() ?? [];
        $value = '';
        foreach ($parts as $part) {
            if ($part instanceof OutputNode) {
                if ($part->escape) {
                    $value .= $this->escapedOutputToString($part);
                } else {
                    $value .= sprintf('<?php echo %s; ?>', $part->expression);
                }
            } else {
                $value .= htmlspecialchars((string)$part, ENT_QUOTES, 'UTF-8');
            }
        }

        return ' ' . $attr->name . '="' . $value . '"';
    }

    /**
     * Convert an escaped output node into executable PHP output.
     */
    private function escapedOutputToString(OutputNode $outputNode): string
    {
        $expression = $this->escaper->generateEscapeCode($outputNode->expression, $outputNode->context);
        $expression = str_replace(Escaper::class, GeneratedAlias::ESCAPER, $expression);

        return sprintf('<?php echo %s; ?>', $expression);
    }

    /**
     * Resolve a template path relative to the current template.
     */
    private function resolveTemplatePath(string $path, PipelineContext $context): string
    {
        $resolved = $this->loader->resolve($path, $context->compilation->templatePath);

        // Track compile-time dependency for cache invalidation
        $tracker = $context->compilation->tracker;
        if ($tracker instanceof DependencyTracker) {
            $sourcePath = $this->loader->sourcePath($resolved);
            if ($sourcePath !== null) {
                $tracker->addDependency($sourcePath);
            }
        }

        return $resolved;
    }

    /**
     * Remove inheritance attributes from an attribute array.
     *
     * @param array<\Sugar\Core\Ast\AttributeNode> $attributes
     * @return array<\Sugar\Core\Ast\AttributeNode>
     */
    private function removeInheritanceAttributes(array $attributes): array
    {
        return array_values(array_filter(
            $attributes,
            fn(AttributeNode $attr): bool => !$this->prefixHelper->isInheritanceAttribute($attr->name),
        ));
    }

    /**
     * Strip all inheritance attributes from a document's nodes.
     *
     * Used for documents that do NOT have extends (e.g., layout templates),
     * where inheritance attributes like s:block must still be transformed.
     */
    private function stripInheritanceAttributes(DocumentNode $node): NodeAction
    {
        // For non-extends documents, s:block nodes in layouts are handled individually
        // by processNode(). Check whether any blocks were actually processed.
        if (!$this->hasLayoutBlocks && $this->collectedImports === []) {
            return NodeAction::none();
        }

        $newChildren = $node->children;

        if ($this->hasLayoutBlocks) {
            // Prepend $__tpl initialization for layout templates
            $initNode = $this->createPhpNode(
                '$__tpl = $__tpl ?? ' . GeneratedAlias::RUNTIME_ENV . '::requireService('
                . GeneratedAlias::TEMPLATE_RENDERER . '::class'
                . '); ',
                0,
                0,
                $node,
            );

            $newChildren = [$initNode, ...$newChildren];
        }

        // Prepend any extracted imports as file-level nodes
        $importNodes = $this->buildImportNodes($node);
        $newChildren = [...$importNodes, ...$newChildren];

        $newDoc = new DocumentNode($newChildren);
        $newDoc->inheritTemplatePathFrom($node);

        return NodeAction::replace($newDoc, restartPass: true);
    }

    /**
     * Validate the structural shape of an s:parent placeholder.
     */
    private function validateParentPlaceholder(
        ElementNode|FragmentNode $node,
        PipelineContext $context,
    ): void {
        $parentAttrNode = AttributeHelper::findAttribute($node->attributes, $this->parentAttr);

        if (!$parentAttrNode instanceof AttributeNode) {
            return;
        }

        if (!$node instanceof FragmentNode) {
            throw $context->compilation->createSyntaxExceptionForAttribute(
                sprintf('%s must be used on <s-template>.', $this->parentAttr),
                $parentAttrNode,
            );
        }

        if (count($node->attributes) !== 1) {
            throw $context->compilation->createSyntaxExceptionForAttribute(
                sprintf('%s cannot be combined with other attributes.', $this->parentAttr),
                $parentAttrNode,
            );
        }

        foreach ($node->children as $child) {
            if ($child instanceof TextNode && trim($child->content) === '') {
                continue;
            }

            throw $context->compilation->createSyntaxExceptionForAttribute(
                sprintf('%s cannot have child content.', $this->parentAttr),
                $parentAttrNode,
            );
        }
    }

    /**
     * Create a RawPhpNode with template path inheritance.
     */
    private function createPhpNode(string $code, int $line, int $column, Node $from): RawPhpNode
    {
        $phpNode = new RawPhpNode($code, $line, $column);
        $phpNode->inheritTemplatePathFrom($from);

        return $phpNode;
    }

    /**
     * Sanitize a block name for use as a PHP variable name suffix.
     */
    private function sanitizeBlockVarName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? $name;
    }
}
