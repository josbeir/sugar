<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler\Pipeline;

use Sugar\Core\Compiler\Pipeline\Enum\PassPriority;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Extension\DirectiveRegistryInterface;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Pass\Context\ContextAnalysisPass;
use Sugar\Core\Pass\Directive\DirectiveCompilationPass;
use Sugar\Core\Pass\Directive\DirectiveExtractionPass;
use Sugar\Core\Pass\Directive\DirectivePairingPass;
use Sugar\Core\Pass\Element\ElementRoutingPass;
use Sugar\Core\Pass\Inheritance\InheritanceCompilationPass;
use Sugar\Core\Pass\RawPhp\PhpNormalizationPass;

/**
 * Builds compiler pipelines with consistent pass ordering.
 */
final class CompilerPipelineFactory
{
    private ?ElementRoutingPass $elementRoutingPass = null;

    private ?DirectiveExtractionPass $directiveExtractionPass = null;

    private ?DirectivePairingPass $directivePairingPass = null;

    private ?DirectiveCompilationPass $directiveCompilationPass = null;

    private ?PhpNormalizationPass $phpNormalizationPass = null;

    private ?ContextAnalysisPass $contextPass = null;

    private ?InheritanceCompilationPass $inheritancePass = null;

    /**
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface $registry Directive registry
     * @param \Sugar\Core\Config\SugarConfig $config Sugar configuration
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $templateLoader Template loader for inheritance path resolution
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $customPasses Custom extension passes
     */
    public function __construct(
        private readonly DirectiveRegistryInterface $registry,
        private readonly SugarConfig $config,
        private readonly TemplateLoaderInterface $templateLoader,
        private readonly array $customPasses = [],
    ) {
    }

    /**
     * Build the main compiler pipeline.
     *
     * @param bool $enableInheritance Whether to include the inheritance compilation pass
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $inlinePasses Additional per-compilation passes
     */
    public function buildCompilerPipeline(
        bool $enableInheritance = true,
        array $inlinePasses = [],
    ): AstPipeline {
        $pipeline = new AstPipeline();

        $pipeline->addPass($this->getElementRoutingPass(), PassPriority::ELEMENT_ROUTING);
        $pipeline->addPass($this->getDirectiveExtractionPass(), PassPriority::DIRECTIVE_EXTRACTION);
        $pipeline->addPass($this->getDirectivePairingPass(), PassPriority::DIRECTIVE_PAIRING);
        $pipeline->addPass($this->getDirectiveCompilationPass(), PassPriority::DIRECTIVE_COMPILATION);

        if ($enableInheritance) {
            $pipeline->addPass($this->getInheritancePass(), PassPriority::INHERITANCE_COMPILATION);
        }

        foreach ($inlinePasses as $entry) {
            $pipeline->addPass($entry['pass'], $entry['priority']);
        }

        $pipeline->addPass($this->getPhpNormalizationPass(), PassPriority::PHP_NORMALIZATION);
        $pipeline->addPass($this->getContextPass(), PassPriority::CONTEXT_ANALYSIS);
        $this->addCustomPasses($pipeline);

        return $pipeline;
    }

    /**
     * Get the element routing pass instance.
     *
     * Runs before directive extraction and converts <s-NAME> custom element tags whose
     * directive implements ElementClaimingDirectiveInterface into a FragmentNode with
     * directive attributes, which DirectiveExtractionPass then processes normally.
     */
    private function getElementRoutingPass(): ElementRoutingPass
    {
        if ($this->elementRoutingPass instanceof ElementRoutingPass) {
            return $this->elementRoutingPass;
        }

        $this->elementRoutingPass = new ElementRoutingPass(
            registry: $this->registry,
            config: $this->config,
        );

        return $this->elementRoutingPass;
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
     * Get the inheritance compilation pass instance.
     */
    private function getInheritancePass(): InheritanceCompilationPass
    {
        if ($this->inheritancePass instanceof InheritanceCompilationPass) {
            return $this->inheritancePass;
        }

        $this->inheritancePass = new InheritanceCompilationPass(
            config: $this->config,
            loader: $this->templateLoader,
        );

        return $this->inheritancePass;
    }

    /**
     * Get the PHP normalization pass instance.
     */
    private function getPhpNormalizationPass(): PhpNormalizationPass
    {
        if ($this->phpNormalizationPass instanceof PhpNormalizationPass) {
            return $this->phpNormalizationPass;
        }

        $this->phpNormalizationPass = new PhpNormalizationPass();

        return $this->phpNormalizationPass;
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
