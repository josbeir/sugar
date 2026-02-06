<?php
declare(strict_types=1);

namespace Sugar\Tests\Helper\Trait;

use Sugar\Compiler;
use Sugar\Config\SugarConfig;
use Sugar\Directive\ClassCompiler;
use Sugar\Directive\ContentCompiler;
use Sugar\Directive\EmptyCompiler;
use Sugar\Directive\ForeachCompiler;
use Sugar\Directive\ForelseCompiler;
use Sugar\Directive\IfCompiler;
use Sugar\Directive\IssetCompiler;
use Sugar\Directive\SpreadCompiler;
use Sugar\Directive\SwitchCompiler;
use Sugar\Directive\UnlessCompiler;
use Sugar\Directive\WhileCompiler;
use Sugar\Enum\OutputContext;
use Sugar\Escape\Escaper;
use Sugar\Extension\DirectiveRegistry;
use Sugar\Loader\FileTemplateLoader;
use Sugar\Parser\Parser;

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

        // Create registry - either with defaults or empty for custom directives
        $registry = $withDefaultDirectives
            ? $this->createDefaultRegistry()
            : new DirectiveRegistry();

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

    /**
     * Create default directive registry with all built-in directives
     *
     * @return DirectiveRegistry Registry with built-in directives
     */
    private function createDefaultRegistry(): DirectiveRegistry
    {
        $registry = new DirectiveRegistry();

        // Register built-in directives (same as EngineBuilder)
        $registry->register('if', IfCompiler::class);
        $registry->register('elseif', IfCompiler::class);
        $registry->register('else', IfCompiler::class);
        $registry->register('unless', UnlessCompiler::class);
        $registry->register('isset', IssetCompiler::class);
        $registry->register('empty', EmptyCompiler::class);
        $registry->register('switch', SwitchCompiler::class);
        $registry->register('case', SwitchCompiler::class);
        $registry->register('default', SwitchCompiler::class);
        $registry->register('foreach', ForeachCompiler::class);
        $registry->register('forelse', ForelseCompiler::class);
        $registry->register('while', WhileCompiler::class);
        $registry->register('class', ClassCompiler::class);
        $registry->register('spread', SpreadCompiler::class);
        $registry->register('text', new ContentCompiler(escape: true));
        $registry->register('html', new ContentCompiler(escape: false, context: OutputContext::RAW));

        return $registry;
    }
}
