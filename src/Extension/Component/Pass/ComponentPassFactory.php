<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Pass;

use Sugar\Core\Compiler\Pipeline\AstPipeline;
use Sugar\Core\Compiler\Pipeline\Enum\PassPriority;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Extension\DirectiveRegistryInterface;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser;
use Sugar\Core\Pass\Directive\DirectiveCompilationPass;
use Sugar\Core\Pass\Directive\DirectiveExtractionPass;
use Sugar\Core\Pass\Directive\DirectivePairingPass;
use Sugar\Core\Template\TemplateComposer;
use Sugar\Extension\Component\Loader\ComponentLoaderInterface;

/**
 * Builds component extension pipeline artifacts.
 *
 * This factory is extension-owned and keeps component expansion assembly
 * outside of core pipeline factory responsibilities.
 */
final class ComponentPassFactory
{
    private ?AstPipeline $componentTemplatePipeline = null;

    private ?ComponentExpansionPass $componentExpansionPass = null;

    /**
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $customPasses
     */
    public function __construct(
        private readonly TemplateLoaderInterface $templateLoader,
        private readonly ComponentLoaderInterface $componentLoader,
        private readonly Parser $parser,
        private readonly DirectiveRegistryInterface $registry,
        private readonly SugarConfig $config,
        private readonly array $customPasses = [],
    ) {
    }

    /**
     * Build component expansion pass.
     */
    public function createExpansionPass(): ComponentExpansionPass
    {
        if ($this->componentExpansionPass instanceof ComponentExpansionPass) {
            return $this->componentExpansionPass;
        }

        $this->componentExpansionPass = new ComponentExpansionPass(
            loader: $this->componentLoader,
            parser: $this->parser,
            registry: $this->registry,
            config: $this->config,
            templateComposer: new TemplateComposer(
                loader: $this->templateLoader,
                parser: $this->parser,
                registry: $this->registry,
                config: $this->config,
            ),
            componentTemplatePipeline: $this->buildComponentTemplatePipeline(),
        );

        return $this->componentExpansionPass;
    }

    /**
     * Build component template pipeline.
     */
    private function buildComponentTemplatePipeline(): AstPipeline
    {
        if ($this->componentTemplatePipeline instanceof AstPipeline) {
            return $this->componentTemplatePipeline;
        }

        $pipeline = new AstPipeline();

        $pipeline->addPass(new DirectiveExtractionPass(
            registry: $this->registry,
            config: $this->config,
        ), PassPriority::DIRECTIVE_EXTRACTION);

        $pipeline->addPass(new DirectivePairingPass($this->registry), PassPriority::DIRECTIVE_PAIRING);
        $pipeline->addPass(new DirectiveCompilationPass($this->registry), PassPriority::DIRECTIVE_COMPILATION);

        $this->addCustomPasses(
            $pipeline,
            minPriority: PassPriority::DIRECTIVE_COMPILATION,
            maxPriority: PassPriority::CONTEXT_ANALYSIS,
        );

        $this->componentTemplatePipeline = $pipeline;

        return $pipeline;
    }

    /**
     * Add custom passes within a priority range.
     */
    private function addCustomPasses(
        AstPipeline $pipeline,
        PassPriority $minPriority,
        PassPriority $maxPriority,
    ): void {
        foreach ($this->customPasses as $entry) {
            if ($entry['priority']->value < $minPriority->value) {
                continue;
            }

            if ($entry['priority']->value >= $maxPriority->value) {
                continue;
            }

            $pipeline->addPass($entry['pass'], $entry['priority']);
        }
    }
}
