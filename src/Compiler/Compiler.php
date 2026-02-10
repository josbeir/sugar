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
    private const PRIORITY_TEMPLATE_INHERITANCE = 0;

    private const PRIORITY_DIRECTIVE_EXTRACTION = 10;

    private const PRIORITY_DIRECTIVE_PAIRING = 20;

    private const PRIORITY_DIRECTIVE_COMPILATION = 30;

    private const PRIORITY_COMPONENT_EXPANSION = 40;

    private const PRIORITY_COMPONENT_VARIANTS = 45;

    private const PRIORITY_CONTEXT_ANALYSIS = 50;

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
     * @var array<array{pass: \Sugar\Compiler\Pipeline\AstPassInterface, priority: int}>
     */
    private readonly array $customPasses;

    /**
     * Constructor
     *
     * @param \Sugar\Parser\Parser $parser Template parser
     * @param \Sugar\Escape\Escaper $escaper Escaper for code generation
     * @param \Sugar\Extension\DirectiveRegistryInterface $registry Directive registry with registered compilers
     * @param \Sugar\Loader\TemplateLoaderInterface $templateLoader Template loader for inheritance
     * @param \Sugar\Config\SugarConfig|null $config Configuration (optional, creates default if null)
     * @param array<array{pass: \Sugar\Compiler\Pipeline\AstPassInterface, priority: int}> $customPasses Custom compiler passes with priorities
     */
    public function __construct(
        private readonly Parser $parser,
        Escaper $escaper,
        DirectiveRegistryInterface $registry,
        TemplateLoaderInterface $templateLoader,
        ?SugarConfig $config = null,
        array $customPasses = [],
    ) {
        $config = $config ?? new SugarConfig();
        $this->escaper = $escaper;
        $this->registry = $registry;
        $this->templateLoader = $templateLoader;
        $this->customPasses = $customPasses;

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

        $tracker?->addComponent($this->templateLoader->getComponentFilePath($componentName));

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
        $pipeline = new AstPipeline();

        if ($enableInheritance) {
            $pipeline->addPass($this->templateInheritancePass, self::PRIORITY_TEMPLATE_INHERITANCE);
        }

        $pipeline->addPass($this->directiveExtractionPass, self::PRIORITY_DIRECTIVE_EXTRACTION);
        $pipeline->addPass($this->directivePairingPass, self::PRIORITY_DIRECTIVE_PAIRING);
        $pipeline->addPass($this->directiveCompilationPass, self::PRIORITY_DIRECTIVE_COMPILATION);
        $pipeline->addPass($this->componentExpansionPass, self::PRIORITY_COMPONENT_EXPANSION);

        if ($variantAdjustments instanceof ComponentVariantAdjustmentPass) {
            $pipeline->addPass($variantAdjustments, self::PRIORITY_COMPONENT_VARIANTS);
        }

        $pipeline->addPass($this->contextPass, self::PRIORITY_CONTEXT_ANALYSIS);

        foreach ($this->customPasses as $entry) {
            $pipeline->addPass($entry['pass'], $entry['priority']);
        }

        return $pipeline;
    }
}
