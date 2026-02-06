<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Trait;

use Sugar\Compiler;
use Sugar\Config\SugarConfig;
use Sugar\Escape\Escaper;
use Sugar\Extension\ExtensionRegistry;
use Sugar\Parser\Parser;
use Sugar\Loader\FileTemplateLoader;

/**
 * Helper trait for setting up compiler-related objects in tests
 *
 * Provides pre-configured instances of common compiler dependencies
 */
trait CompilerTestTrait
{
    protected Parser $parser;

    protected Escaper $escaper;

    protected ExtensionRegistry $registry;

    protected Compiler $compiler;

    protected ?FileTemplateLoader $templateLoader = null;

    /**
     * Set up compiler dependencies
     *
     * Call this in your setUp() method.
     * Pass $withDefaultDirectives=false if you need to register custom directives.
     */
    protected function setUpCompiler(
        ?SugarConfig $config = null,
        bool $withTemplateLoader = false,
        bool $withDefaultDirectives = true,
    ): void {
        $this->parser = new Parser($config);
        $this->escaper = new Escaper();

        // Create registry for tests that need to register custom directives
        // For tests using defaults, pass null to Compiler (it creates its own)
        $registry = $withDefaultDirectives ? null : new ExtensionRegistry();

        if ($withTemplateLoader && $config instanceof SugarConfig) {
            $this->templateLoader = new FileTemplateLoader($config);
            $this->compiler = new Compiler(
                parser: $this->parser,
                escaper: $this->escaper,
                registry: $registry,
                templateLoader: $this->templateLoader,
            );
        } else {
            $this->compiler = new Compiler(
                parser: $this->parser,
                escaper: $this->escaper,
                registry: $registry,
            );
        }

        // Set registry property for tests that need access
        // Get it from compiler or create new one
        if (!$withDefaultDirectives && $registry instanceof ExtensionRegistry) {
            $this->registry = $registry;
        } else {
            // For tests using default directives, create a throwaway registry
            $this->registry = new ExtensionRegistry();
        }
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
     * @return ExtensionRegistry Extension registry instance
     */
    protected function createRegistry(): ExtensionRegistry
    {
        return new ExtensionRegistry();
    }
}
