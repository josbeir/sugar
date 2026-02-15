<?php
declare(strict_types=1);

namespace Sugar\Extension\Component\Compiler;

use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Compiler\CompilerInterface;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Extension\Component\Exception\ComponentNotFoundException;
use Sugar\Extension\Component\Pass\ComponentPassPriority;
use Sugar\Extension\Component\Pass\ComponentVariantAdjustmentPass;

/**
 * Compiles component templates through the core compiler pipeline.
 *
 * This service keeps component-specific compilation concerns in the
 * component extension while delegating generic template compilation
 * to the core compiler.
 */
final readonly class ComponentTemplateCompiler
{
    /**
     * @param \Sugar\Core\Compiler\CompilerInterface $compiler Core template compiler
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $loader Template loader
     */
    public function __construct(
        private CompilerInterface $compiler,
        private TemplateLoaderInterface $loader,
    ) {
    }

    /**
     * Compile a component template variant.
     *
     * @param string $componentName Component name
     * @param array<string> $slotNames Slot names that should be considered raw
     * @param bool $debug Debug mode
     * @param \Sugar\Core\Cache\DependencyTracker|null $tracker Optional dependency tracker
     */
    public function compileComponent(
        string $componentName,
        array $slotNames = [],
        bool $debug = false,
        ?DependencyTracker $tracker = null,
    ): string {
        try {
            $templateContent = $this->loader->loadComponent($componentName);
            $componentPath = $this->loader->getComponentPath($componentName);
        } catch (TemplateRuntimeException $templateRuntimeException) {
            throw new ComponentNotFoundException(
                $templateRuntimeException->getRawMessage(),
                previous: $templateRuntimeException,
            );
        }

        $tracker?->addComponent($this->loader->getComponentFilePath($componentName));

        return $this->compiler->compile(
            source: $templateContent,
            templatePath: $componentPath,
            debug: $debug,
            tracker: $tracker,
            inlinePasses: $this->buildInlinePasses($slotNames),
        );
    }

    /**
     * Build inline passes needed for component variant compilation.
     *
     * @param array<string> $slotNames
     * @return array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: int}>
     */
    private function buildInlinePasses(array $slotNames): array
    {
        $slotVars = array_values(array_unique(array_merge(['slot'], $slotNames)));

        return [[
            'pass' => new ComponentVariantAdjustmentPass($slotVars),
            'priority' => ComponentPassPriority::VARIANT_ADJUSTMENTS,
        ]];
    }
}
