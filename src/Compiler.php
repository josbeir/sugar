<?php
declare(strict_types=1);

namespace Sugar;

use Sugar\Ast\DocumentNode;
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
use Sugar\Pass\Component\ComponentVariantAdjustmentPass;
use Sugar\Pass\Context\ContextAnalysisPass;
use Sugar\Pass\Directive\DirectiveCompilationPass;
use Sugar\Pass\Directive\DirectiveExtractionPass;
use Sugar\Pass\Directive\DirectivePairingPass;
use Sugar\Pass\Middleware\AstMiddlewarePipeline;
use Sugar\Pass\Template\TemplateInheritancePass;

/**
 * Orchestrates template compilation pipeline
 *
 * Pipeline: Parser → TemplateInheritancePass (optional) → DirectiveExtractionPass → DirectivePairingPass → DirectiveCompilationPass → ComponentExpansionPass → ContextAnalysisPass → CodeGenerator
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
        $context = $this->createContext(
            $templatePath ?? 'inline-template',
            $source,
            $debug,
            $tracker,
        );

        // Step 1: Parse template source into AST
        $ast = $this->parser->parse($source);

        return $this->compileAst(
            $ast,
            $context,
            $templatePath !== null,
        );
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

        $slotVars = array_values(array_unique(array_merge(['slot'], $slotNames)));
        $variantAdjustments = new ComponentVariantAdjustmentPass($slotVars);

        return $this->compileAst(
            $ast,
            $context,
            true,
            $variantAdjustments,
        );
    }

    /**
     * Create a compilation context for a template.
     */
    private function createContext(
        string $templatePath,
        string $source,
        bool $debug,
        ?DependencyTracker $tracker,
    ): CompilationContext {
        return new CompilationContext(
            $templatePath,
            $source,
            $debug,
            $tracker,
        );
    }

    /**
     * Execute the middleware pipeline and generate PHP code.
     */
    private function compileAst(
        DocumentNode $ast,
        CompilationContext $context,
        bool $enableInheritance,
        ?ComponentVariantAdjustmentPass $variantAdjustments = null,
    ): string {
        $pipeline = $this->buildPipeline(
            $enableInheritance,
            $variantAdjustments,
        );
        $analyzedAst = $pipeline->execute($ast, $context);

        // Step 7: Generate executable PHP code with inline escaping
        $generator = new CodeGenerator($this->escaper, $context);

        return $generator->generate($analyzedAst);
    }

    /**
     * Build the middleware pipeline for compilation.
     */
    private function buildPipeline(
        bool $enableInheritance,
        ?ComponentVariantAdjustmentPass $variantAdjustments = null,
    ): AstMiddlewarePipeline {
        $passes = [];

        if ($enableInheritance && $this->templateInheritancePass instanceof TemplateInheritancePass) {
            $passes[] = $this->templateInheritancePass;
        }

        $passes[] = $this->directiveExtractionPass;
        $passes[] = $this->directivePairingPass;
        $passes[] = $this->directiveCompilationPass;

        if ($this->componentExpansionPass instanceof ComponentExpansionPass) {
            $passes[] = $this->componentExpansionPass;
        }

        if ($variantAdjustments instanceof ComponentVariantAdjustmentPass) {
            $passes[] = $variantAdjustments;
        }

        $passes[] = $this->contextPass;

        return new AstMiddlewarePipeline($passes);
    }
}
