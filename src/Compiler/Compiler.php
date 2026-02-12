<?php
declare(strict_types=1);

namespace Sugar\Compiler;

use Sugar\Ast\DocumentNode;
use Sugar\Cache\DependencyTracker;
use Sugar\CodeGen\CodeGenerator;
use Sugar\Compiler\Pipeline\CompilerPipelineFactory;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
use Sugar\Escape\Escaper;
use Sugar\Extension\DirectiveRegistryInterface;
use Sugar\Loader\TemplateLoaderInterface;
use Sugar\Parser\Parser;
use Sugar\Pass\Component\ComponentVariantAdjustmentPass;

/**
 * Orchestrates template compilation pipeline
 *
 * Pipeline: Parser -> TemplateInheritancePass (optional) -> DirectiveExtractionPass -> DirectivePairingPass -> DirectiveCompilationPass -> ComponentExpansionPass -> ContextAnalysisPass -> CodeGenerator
 */
final class Compiler implements CompilerInterface
{
    private readonly DirectiveRegistryInterface $registry;

    private readonly Escaper $escaper;

    private readonly TemplateLoaderInterface $templateLoader;

    private readonly CompilerPipelineFactory $pipelineFactory;

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
        $this->pipelineFactory = new CompilerPipelineFactory(
            $this->templateLoader,
            $this->parser,
            $this->registry,
            $config,
            $customPasses,
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
        $context->stampTemplatePath($ast);

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
        $context->stampTemplatePath($ast);

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
        $pipeline = $this->pipelineFactory->buildCompilerPipeline(
            enableInheritance: $enableInheritance,
            variantAdjustments: $variantAdjustments,
        );
        $analyzedAst = $pipeline->execute($ast, $context);

        // Step 7: Generate executable PHP code with inline escaping
        $generator = new CodeGenerator($this->escaper, $context);

        return $generator->generate($analyzedAst);
    }
}
