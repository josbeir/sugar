<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Trait;

use Sugar\Core\Compiler\Compiler;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser;

/**
 * Helper trait for setting up compiler-related objects in tests
 *
 * Provides pre-configured instances of common compiler dependencies
 */
trait CompilerTestTrait
{
    protected Parser $parser;

    protected Escaper $escaper;

    protected DirectiveRegistry $registry;

    protected Compiler $compiler;

    protected TemplateLoaderInterface $templateLoader;

    /**
     * Set up compiler dependencies
     *
     * Call this in your setUp() method.
     * Pass $withDefaultDirectives=false if you need to register custom directives.
     *
     * @param SugarConfig|null $config Optional configuration
     * @param bool $withTemplateLoader Ignored; template loader is always created
     * @param bool $withDefaultDirectives Whether to load default directives
     * @param array<string> $templatePaths Template paths for loader (only used if withTemplateLoader=true)
     * @param array<string> $componentPaths Component paths for loader (only used if withTemplateLoader=true)
     * @param bool $absolutePathsOnly When true, resolve() ignores current template paths
     */
    protected function setUpCompiler(
        ?SugarConfig $config = null,
        bool $withTemplateLoader = false,
        bool $withDefaultDirectives = true,
        array $templatePaths = [],
        array $componentPaths = [],
        bool $absolutePathsOnly = false,
    ): void {
        $this->parser = new Parser($config);
        $this->escaper = new Escaper();

        // Create registry - either with defaults or empty for custom directives
        $registry = $withDefaultDirectives
            ? new DirectiveRegistry()
            : DirectiveRegistry::empty();

        $this->templateLoader = new FileTemplateLoader(
            $config ?? new SugarConfig(),
            $templatePaths,
            $componentPaths,
            $absolutePathsOnly,
        );
        $this->compiler = new Compiler(
            parser: $this->parser,
            escaper: $this->escaper,
            registry: $registry,
            templateLoader: $this->templateLoader,
            config: $config,
        );

        // Set registry property for tests that need access
        $this->registry = $registry;
    }

    /**
     * Set up compiler dependencies using a StringTemplateLoader.
     *
     * @param array<string, string> $templates
     * @param array<string, string> $components
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: int}> $customPasses
     */
    protected function setUpCompilerWithStringLoader(
        array $templates = [],
        array $components = [],
        ?SugarConfig $config = null,
        bool $withDefaultDirectives = true,
        bool $absolutePathsOnly = false,
        array $customPasses = [],
    ): void {
        $this->parser = new Parser($config);
        $this->escaper = new Escaper();

        $registry = $withDefaultDirectives
            ? new DirectiveRegistry()
            : DirectiveRegistry::empty();

        $this->templateLoader = new StringTemplateLoader(
            $config ?? new SugarConfig(),
            $templates,
            $components,
            $absolutePathsOnly,
        );

        $this->compiler = new Compiler(
            parser: $this->parser,
            escaper: $this->escaper,
            registry: $registry,
            templateLoader: $this->templateLoader,
            config: $config,
            customPasses: $customPasses,
        );

        $this->registry = $registry;
    }

    /**
     * Create a standalone parser instance
     *
     * @param SugarConfig|null $config Optional configuration
     * @return Parser Parser instance
     */
    protected function createParser(?SugarConfig $config = null): Parser
    {
        return new Parser($config);
    }

    /**
     * Create a standalone escaper instance
     *
     * @return Escaper Escaper instance
     */
    protected function createEscaper(): Escaper
    {
        return new Escaper();
    }

    /**
     * Create a standalone extension registry
     *
     * @return DirectiveRegistry Extension registry instance
     */
    protected function createRegistry(): DirectiveRegistry
    {
        return new DirectiveRegistry();
    }
}
