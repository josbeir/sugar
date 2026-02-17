<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler\Pipeline;

use Sugar\Core\Compiler\Pipeline\Enum\PassPriority;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Extension\DirectiveRegistryInterface;
use Sugar\Core\Pass\Context\ContextAnalysisPass;
use Sugar\Core\Pass\Directive\DirectiveCompilationPass;
use Sugar\Core\Pass\Directive\DirectiveExtractionPass;
use Sugar\Core\Pass\Directive\DirectivePairingPass;

/**
 * Builds compiler pipelines with consistent pass ordering.
 */
final class CompilerPipelineFactory
{
    private ?DirectiveExtractionPass $directiveExtractionPass = null;

    private ?DirectivePairingPass $directivePairingPass = null;

    private ?DirectiveCompilationPass $directiveCompilationPass = null;

    private ?ContextAnalysisPass $contextPass = null;

    /**
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $customPasses
     */
    public function __construct(
        private readonly DirectiveRegistryInterface $registry,
        private readonly SugarConfig $config,
        private readonly array $customPasses = [],
    ) {
    }

    /**
     * Build the main compiler pipeline.
     *
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $inlinePasses Additional per-compilation passes
     */
    public function buildCompilerPipeline(
        array $inlinePasses = [],
    ): AstPipeline {
        $pipeline = new AstPipeline();

        $pipeline->addPass($this->getDirectiveExtractionPass(), PassPriority::DIRECTIVE_EXTRACTION);
        $pipeline->addPass($this->getDirectivePairingPass(), PassPriority::DIRECTIVE_PAIRING);
        $pipeline->addPass($this->getDirectiveCompilationPass(), PassPriority::DIRECTIVE_COMPILATION);

        foreach ($inlinePasses as $entry) {
            $pipeline->addPass($entry['pass'], $entry['priority']);
        }

        $pipeline->addPass($this->getContextPass(), PassPriority::CONTEXT_ANALYSIS);
        $this->addCustomPasses($pipeline);

        return $pipeline;
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
    ): void {
        foreach ($this->customPasses as $entry) {
            $pipeline->addPass($entry['pass'], $entry['priority']);
        }
    }
}
