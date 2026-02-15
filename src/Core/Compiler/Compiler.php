<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler;

use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\CodeGen\CodeGenerator;
use Sugar\Core\Compiler\Pipeline\CompilerPipelineFactory;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Extension\DirectiveRegistryInterface;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser as TemplateParser;
use Sugar\Core\Pass\Component\ComponentVariantAdjustmentPass;

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

    private readonly PhpSyntaxValidator $phpSyntaxValidator;

    private readonly bool $phpSyntaxValidationEnabled;

    /**
     * Constructor
     *
     * @param \Sugar\Core\Parser\Parser $parser Template parser
     * @param \Sugar\Core\Escape\Escaper $escaper Escaper for code generation
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface $registry Directive registry with registered compilers
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $templateLoader Template loader for inheritance
     * @param \Sugar\Core\Config\SugarConfig|null $config Configuration (optional, creates default if null)
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: int}> $customPasses Custom compiler passes with priorities
     * @param bool $phpSyntaxValidationEnabled Enable nikic/php-parser syntax validation when available
     */
    public function __construct(
        private readonly TemplateParser $parser,
        Escaper $escaper,
        DirectiveRegistryInterface $registry,
        TemplateLoaderInterface $templateLoader,
        ?SugarConfig $config = null,
        array $customPasses = [],
        bool $phpSyntaxValidationEnabled = false,
    ) {
        $config = $config ?? new SugarConfig();
        $this->escaper = $escaper;
        $this->registry = $registry;
        $this->templateLoader = $templateLoader;
        $this->phpSyntaxValidationEnabled = $phpSyntaxValidationEnabled;
        $this->phpSyntaxValidator = new PhpSyntaxValidator();
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
        if ($this->phpSyntaxValidationEnabled) {
            $this->phpSyntaxValidator->templateSegments($ast, $context);
        }

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
        if ($this->phpSyntaxValidationEnabled) {
            $this->phpSyntaxValidator->templateSegments($ast, $context);
        }

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
        $compiledCode = $generator->generate($analyzedAst);
        if ($this->phpSyntaxValidationEnabled) {
            $this->phpSyntaxValidator->generated($compiledCode, $context);
        }

        return $compiledCode;
    }
}
