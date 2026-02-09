<?php
declare(strict_types=1);

namespace Sugar\Compiler;

use Sugar\Ast\DocumentNode;
use Sugar\Cache\DependencyTracker;
use Sugar\CodeGen\CodeGenerator;
use Sugar\Compiler\Pipeline\AstPipeline;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\Escape\Escaper;
use Sugar\Extension\DirectiveRegistryInterface;
use Sugar\Loader\TemplateLoaderInterface;
use Sugar\Parser\Parser;
use Sugar\Pass\Component\ComponentExpansionPass;
use Sugar\Pass\Component\ComponentVariantAdjustmentPass;
use Sugar\Pass\Context\ContextAnalysisPass;
use Sugar\Pass\Directive\DirectiveCompilationPass;
use Sugar\Pass\Directive\DirectiveExtractionPass;
use Sugar\Pass\Directive\DirectivePairingPass;
use Sugar\Pass\Template\TemplateInheritancePass;

/**
 * Orchestrates template compilation pipeline
 *
 * Pipeline: Parser -> TemplateInheritancePass (optional) -> DirectiveExtractionPass -> DirectivePairingPass -> DirectiveCompilationPass -> ComponentExpansionPass -> ContextAnalysisPass -> CodeGenerator
 */
final class Compiler implements CompilerInterface
{
    private readonly DirectiveRegistryInterface $registry;

    private readonly DirectivePairingPass $directivePairingPass;

    private readonly DirectiveExtractionPass $directiveExtractionPass;

    private readonly DirectiveCompilationPass $directiveCompilationPass;

    private readonly ContextAnalysisPass $contextPass;

    private readonly Escaper $escaper;

    private readonly TemplateInheritancePass $templateInheritancePass;

    private readonly ComponentExpansionPass $componentExpansionPass;

    private readonly TemplateLoaderInterface $templateLoader;

    /**
     * Constructor
     *
     * @param \Sugar\Parser\Parser $parser Template parser
     * @param \Sugar\Escape\Escaper $escaper Escaper for code generation
     * @param \Sugar\Extension\DirectiveRegistryInterface $registry Directive registry with registered compilers
     * @param \Sugar\Loader\TemplateLoaderInterface $templateLoader Template loader for inheritance
     * @param \Sugar\Config\SugarConfig|null $config Configuration (optional, creates default if null)
     */
    public function __construct(
        private readonly Parser $parser,
        Escaper $escaper,
        DirectiveRegistryInterface $registry,
        TemplateLoaderInterface $templateLoader,
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

        $this->templateInheritancePass = new TemplateInheritancePass($this->templateLoader, $this->parser, $config);
        $this->componentExpansionPass = new ComponentExpansionPass(
            $this->templateLoader,
            $this->parser,
            $this->registry,
            $config,
        );
    }

    /**
     * @inheritDoc
     */
    public function compile(
        string $source,
        ?string $templatePath = null,
        bool $debug = false,
        ?DependencyTracker $tracker = null,
        ?array $blocks = null,
    ): string {
        $context = $this->createContext(
            $templatePath ?? 'inline-template',
            $source,
            $debug,
            $tracker,
            $blocks,
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
     * @inheritDoc
     */
    public function compileComponent(
        string $componentName,
        array $slotNames = [],
        bool $debug = false,
        ?DependencyTracker $tracker = null,
    ): string {
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
     * @param array<string>|null $blocks
     */
    private function createContext(
        string $templatePath,
        string $source,
        bool $debug,
        ?DependencyTracker $tracker,
        ?array $blocks = null,
    ): CompilationContext {
        return new CompilationContext(
            $templatePath,
            $source,
            $debug,
            $tracker,
            $blocks,
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
    ): AstPipeline {
        $passes = [];

        if ($enableInheritance) {
            $passes[] = $this->templateInheritancePass;
        }

        $passes[] = $this->directiveExtractionPass;
        $passes[] = $this->directivePairingPass;
        $passes[] = $this->directiveCompilationPass;
        $passes[] = $this->componentExpansionPass;

        if ($variantAdjustments instanceof ComponentVariantAdjustmentPass) {
            $passes[] = $variantAdjustments;
        }

        $passes[] = $this->contextPass;

        return new AstPipeline($passes);
    }
}
