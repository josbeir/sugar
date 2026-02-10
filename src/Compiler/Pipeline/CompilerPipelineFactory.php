<?php
declare(strict_types=1);

namespace Sugar\Compiler\Pipeline;

use Sugar\Config\SugarConfig;
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
 * Builds compiler pipelines with consistent pass ordering.
 */
final class CompilerPipelineFactory
{
    private ?TemplateInheritancePass $inheritancePass = null;

    private ?DirectiveExtractionPass $directiveExtractionPass = null;

    private ?DirectivePairingPass $directivePairingPass = null;

    private ?DirectiveCompilationPass $directiveCompilationPass = null;

    private ?ContextAnalysisPass $contextPass = null;

    private ?AstPipeline $componentTemplatePipeline = null;

    private ?ComponentExpansionPass $componentExpansionPass = null;

    /**
     * @param array<array{pass: \Sugar\Compiler\Pipeline\AstPassInterface, priority: int}> $customPasses
     */
    public function __construct(
        private readonly TemplateLoaderInterface $loader,
        private readonly Parser $parser,
        private readonly DirectiveRegistryInterface $registry,
        private readonly SugarConfig $config,
        private readonly array $customPasses = [],
    ) {
    }

    /**
     * Build the main compiler pipeline.
     */
    public function buildCompilerPipeline(
        bool $enableInheritance,
        ?ComponentVariantAdjustmentPass $variantAdjustments = null,
    ): AstPipeline {
        $pipeline = new AstPipeline();

        if ($enableInheritance) {
            $pipeline->addPass($this->getInheritancePass(), CompilerPassPriority::TEMPLATE_INHERITANCE);
        }

        $pipeline->addPass($this->getDirectiveExtractionPass(), CompilerPassPriority::DIRECTIVE_EXTRACTION);
        $pipeline->addPass($this->getDirectivePairingPass(), CompilerPassPriority::DIRECTIVE_PAIRING);
        $pipeline->addPass($this->getDirectiveCompilationPass(), CompilerPassPriority::DIRECTIVE_COMPILATION);
        $pipeline->addPass($this->getComponentExpansionPass(), CompilerPassPriority::COMPONENT_EXPANSION);

        if ($variantAdjustments instanceof ComponentVariantAdjustmentPass) {
            $pipeline->addPass($variantAdjustments, CompilerPassPriority::COMPONENT_VARIANTS);
        }

        $pipeline->addPass($this->getContextPass(), CompilerPassPriority::CONTEXT_ANALYSIS);
        $this->addCustomPasses($pipeline);

        return $pipeline;
    }

    /**
     * Build the component template pipeline.
     */
    public function buildComponentTemplatePipeline(): AstPipeline
    {
        if ($this->componentTemplatePipeline instanceof AstPipeline) {
            return $this->componentTemplatePipeline;
        }

        $pipeline = new AstPipeline();

        $pipeline->addPass($this->getInheritancePass(), CompilerPassPriority::TEMPLATE_INHERITANCE);
        $pipeline->addPass($this->getDirectiveExtractionPass(), CompilerPassPriority::DIRECTIVE_EXTRACTION);
        $pipeline->addPass($this->getDirectivePairingPass(), CompilerPassPriority::DIRECTIVE_PAIRING);
        $pipeline->addPass($this->getDirectiveCompilationPass(), CompilerPassPriority::DIRECTIVE_COMPILATION);

        $this->addCustomPasses(
            $pipeline,
            minPriority: CompilerPassPriority::DIRECTIVE_COMPILATION,
            maxPriority: CompilerPassPriority::COMPONENT_EXPANSION,
        );

        $this->componentTemplatePipeline = $pipeline;

        return $pipeline;
    }

    /**
     * Get the component expansion pass instance.
     */
    public function getComponentExpansionPass(): ComponentExpansionPass
    {
        if ($this->componentExpansionPass instanceof ComponentExpansionPass) {
            return $this->componentExpansionPass;
        }

        $this->componentExpansionPass = new ComponentExpansionPass(
            $this->loader,
            $this->parser,
            $this->registry,
            $this->config,
            $this->buildComponentTemplatePipeline(),
        );

        return $this->componentExpansionPass;
    }

    /**
     * Get the template inheritance pass instance.
     */
    private function getInheritancePass(): TemplateInheritancePass
    {
        if ($this->inheritancePass instanceof TemplateInheritancePass) {
            return $this->inheritancePass;
        }

        $this->inheritancePass = new TemplateInheritancePass($this->loader, $this->parser, $this->config);

        return $this->inheritancePass;
    }

    /**
     * Get the directive extraction pass instance.
     */
    private function getDirectiveExtractionPass(): DirectiveExtractionPass
    {
        if ($this->directiveExtractionPass instanceof DirectiveExtractionPass) {
            return $this->directiveExtractionPass;
        }

        $this->directiveExtractionPass = new DirectiveExtractionPass(
            registry: $this->registry,
            config: $this->config,
        );

        return $this->directiveExtractionPass;
    }

    /**
     * Get the directive pairing pass instance.
     */
    private function getDirectivePairingPass(): DirectivePairingPass
    {
        if ($this->directivePairingPass instanceof DirectivePairingPass) {
            return $this->directivePairingPass;
        }

        $this->directivePairingPass = new DirectivePairingPass($this->registry);

        return $this->directivePairingPass;
    }

    /**
     * Get the directive compilation pass instance.
     */
    private function getDirectiveCompilationPass(): DirectiveCompilationPass
    {
        if ($this->directiveCompilationPass instanceof DirectiveCompilationPass) {
            return $this->directiveCompilationPass;
        }

        $this->directiveCompilationPass = new DirectiveCompilationPass($this->registry);

        return $this->directiveCompilationPass;
    }

    /**
     * Get the context analysis pass instance.
     */
    private function getContextPass(): ContextAnalysisPass
    {
        if ($this->contextPass instanceof ContextAnalysisPass) {
            return $this->contextPass;
        }

        $this->contextPass = new ContextAnalysisPass();

        return $this->contextPass;
    }

    /**
     * Add custom passes within a priority range.
     */
    private function addCustomPasses(
        AstPipeline $pipeline,
        int $minPriority = PHP_INT_MIN,
        int $maxPriority = PHP_INT_MAX,
    ): void {
        foreach ($this->customPasses as $entry) {
            if ($entry['priority'] < $minPriority) {
                continue;
            }

            if ($entry['priority'] >= $maxPriority) {
                continue;
            }

            $pipeline->addPass($entry['pass'], $entry['priority']);
        }
    }
}
