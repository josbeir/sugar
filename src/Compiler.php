<?php
declare(strict_types=1);

namespace Sugar;

use Sugar\Cache\DependencyTracker;
use Sugar\CodeGen\CodeGenerator;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\Escape\Escaper;
use Sugar\Exception\TemplateRuntimeException;
use Sugar\Extension\DirectiveRegistryInterface;
use Sugar\Loader\TemplateLoaderInterface;
use Sugar\Parser\Parser;
use Sugar\Pass\Component\ComponentExpansionPass;
use Sugar\Pass\Component\Helper\ComponentAttributeOverrideHelper;
use Sugar\Pass\Component\Helper\SlotOutputHelper;
use Sugar\Pass\Context\ContextAnalysisPass;
use Sugar\Pass\Directive\DirectiveCompilationPass;
use Sugar\Pass\Directive\DirectiveExtractionPass;
use Sugar\Pass\Directive\DirectivePairingPass;
use Sugar\Pass\Template\TemplateInheritancePass;

/**
 * Orchestrates template compilation pipeline
 *
 * Pipeline: Parser → DirectiveExtractionPass → DirectivePairingPass → DirectiveCompilationPass → ContextAnalysisPass → CodeGenerator
 */
final class Compiler implements CompilerInterface
{
    private readonly DirectiveRegistryInterface $registry;

    private readonly DirectivePairingPass $directivePairingPass;

    private readonly DirectiveExtractionPass $directiveExtractionPass;

    private readonly DirectiveCompilationPass $directiveCompilationPass;

    private readonly ContextAnalysisPass $contextPass;

    private readonly Escaper $escaper;

    private readonly ?TemplateInheritancePass $templateInheritancePass;

    private readonly ?ComponentExpansionPass $componentExpansionPass;

    private readonly ?TemplateLoaderInterface $templateLoader;

    /**
     * Constructor
     *
     * @param \Sugar\Parser\Parser $parser Template parser
     * @param \Sugar\Escape\Escaper $escaper Escaper for code generation
     * @param \Sugar\Extension\DirectiveRegistryInterface $registry Directive registry with registered compilers
     * @param \Sugar\Loader\TemplateLoaderInterface|null $templateLoader Template loader for inheritance (optional)
     * @param \Sugar\Config\SugarConfig|null $config Configuration (optional, creates default if null)
     */
    public function __construct(
        private readonly Parser $parser,
        Escaper $escaper,
        DirectiveRegistryInterface $registry,
        ?TemplateLoaderInterface $templateLoader = null,
        ?SugarConfig $config = null,
    ) {
        $config = $config ?? new SugarConfig();
        $this->escaper = $escaper;
        $this->registry = $registry;
        $this->templateLoader = $templateLoader;

        // Create passes
        $this->directivePairingPass = new DirectivePairingPass($this->registry);
        $this->directiveExtractionPass = new DirectiveExtractionPass(
            registry: $this->registry,
            config: $config,
        );
        $this->directiveCompilationPass = new DirectiveCompilationPass($this->registry);
        $this->contextPass = new ContextAnalysisPass();

        // Create template inheritance pass if loader is provided
        $this->templateInheritancePass = $this->templateLoader instanceof TemplateLoaderInterface
            ? new TemplateInheritancePass($this->templateLoader, $config)
            : null;

        // Create component expansion pass if loader is provided
        $this->componentExpansionPass = $this->templateLoader instanceof TemplateLoaderInterface
            ? new ComponentExpansionPass($this->templateLoader, $this->parser, $this->registry, $config)
            : null;
    }

    /**
     * Compile template source to executable PHP code
     *
     * @param string $source Template source code
     * @param string|null $templatePath Template path for inheritance resolution and debug info (default: null)
     * @param bool $debug Enable debug mode with inline source comments (default: false)
     * @param \Sugar\Cache\DependencyTracker|null $tracker Optional dependency tracker for cache metadata
     * @return string Compiled PHP code
     */
    public function compile(
        string $source,
        ?string $templatePath = null,
        bool $debug = false,
        ?DependencyTracker $tracker = null,
    ): string {
        // Create compilation context for error handling with snippets
        $context = new CompilationContext(
            $templatePath ?? 'inline-template',
            $source,
            $debug,
            $tracker,
        );

        // Step 1: Parse template source into AST
        $ast = $this->parser->parse($source);

        // Step 1.5: Process template inheritance if enabled
        if ($this->templateInheritancePass instanceof TemplateInheritancePass && $templatePath !== null) {
            $ast = $this->templateInheritancePass->execute($ast, $context);
        }

        // Step 2: Extract directives from elements (s:if, s:component → DirectiveNode)
        $extractedAst = $this->directiveExtractionPass->execute($ast, $context);

        // Step 3: Wire up parent references and pair sibling directives
        $pairedAst = $this->directivePairingPass->execute($extractedAst, $context);

        // Step 4: Compile DirectiveNodes into PHP control structures and component nodes
        $transformedAst = $this->directiveCompilationPass->execute($pairedAst, $context);

        // Step 5: Expand components (ComponentNode/DynamicComponentNode → template content)
        if ($this->componentExpansionPass instanceof ComponentExpansionPass) {
            $transformedAst = $this->componentExpansionPass->execute($transformedAst, $context);
        }

        // Step 6: Analyze context and update OutputNode contexts
        $analyzedAst = $this->contextPass->execute($transformedAst, $context);

        // Step 7: Generate executable PHP code with inline escaping
        $generator = new CodeGenerator($this->escaper, $context);

        return $generator->generate($analyzedAst);
    }

    /**
     * Compile a component template variant with runtime slots and attributes
     *
     * @param string $componentName Component name
     * @param array<string> $slotNames Slot variable names to mark as raw
     * @param bool $debug Enable debug mode
     * @param \Sugar\Cache\DependencyTracker|null $tracker Dependency tracker
     * @return string Compiled PHP code
     */
    public function compileComponentVariant(
        string $componentName,
        array $slotNames = [],
        bool $debug = false,
        ?DependencyTracker $tracker = null,
    ): string {
        if (!$this->templateLoader instanceof TemplateLoaderInterface) {
            throw new TemplateRuntimeException('Template loader is required for components.');
        }

        $templateContent = $this->templateLoader->loadComponent($componentName);
        $componentPath = $this->templateLoader->getComponentPath($componentName);

        $tracker?->addComponent($componentName);

        $context = new CompilationContext(
            $componentPath,
            $templateContent,
            $debug,
            $tracker,
        );

        $ast = $this->parser->parse($templateContent);

        if ($this->templateInheritancePass instanceof TemplateInheritancePass) {
            $ast = $this->templateInheritancePass->execute($ast, $context);
        }

        $ast = $this->directiveExtractionPass->execute($ast, $context);
        $ast = $this->directivePairingPass->execute($ast, $context);
        $ast = $this->directiveCompilationPass->execute($ast, $context);

        if ($this->componentExpansionPass instanceof ComponentExpansionPass) {
            $ast = $this->componentExpansionPass->execute($ast, $context);
        }

        ComponentAttributeOverrideHelper::apply($ast, '$__sugar_attrs');

        $slotVars = array_values(array_unique(array_merge(['slot'], $slotNames)));
        SlotOutputHelper::disableEscaping($ast, $slotVars);

        $analyzedAst = $this->contextPass->execute($ast, $context);
        $generator = new CodeGenerator($this->escaper, $context);

        return $generator->generate($analyzedAst);
    }
}
