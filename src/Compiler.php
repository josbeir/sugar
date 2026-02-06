<?php
declare(strict_types=1);

namespace Sugar;

use Sugar\Cache\DependencyTracker;
use Sugar\CodeGen\CodeGenerator;
use Sugar\Config\SugarConfig;
use Sugar\Context\CompilationContext;
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
use Sugar\Extension\ExtensionRegistry;
use Sugar\Parser\Parser;
use Sugar\Pass\ComponentExpansionPass;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\Pass\Directive\DirectiveCompilationPass;
use Sugar\Pass\Directive\DirectiveExtractionPass;
use Sugar\Pass\Directive\DirectivePairingPass;
use Sugar\Pass\TemplateInheritancePass;
use Sugar\Loader\TemplateLoaderInterface;

/**
 * Orchestrates template compilation pipeline
 *
 * Pipeline: Parser → DirectiveExtractionPass → DirectivePairingPass → DirectiveCompilationPass → ContextAnalysisPass → CodeGenerator
 */
final class Compiler implements CompilerInterface
{
    private readonly ExtensionRegistry $registry;

    private readonly DirectivePairingPass $directivePairingPass;

    private readonly DirectiveExtractionPass $directiveExtractionPass;

    private readonly DirectiveCompilationPass $directiveCompilationPass;

    private readonly ContextAnalysisPass $contextPass;

    private readonly Escaper $escaper;

    private readonly ?TemplateInheritancePass $templateInheritancePass;

    private readonly ?ComponentExpansionPass $componentExpansionPass;

    /**
     * Constructor
     *
     * @param \Sugar\Parser\Parser $parser Template parser
     * @param \Sugar\Escape\Escaper $escaper Escaper for code generation
     * @param \Sugar\Extension\ExtensionRegistry|null $registry Extension registry (optional, creates default if null)
     * @param \Sugar\Loader\TemplateLoaderInterface|null $templateLoader Template loader for inheritance (optional)
     * @param \Sugar\Config\SugarConfig|null $config Configuration (optional, creates default if null)
     */
    public function __construct(
        private readonly Parser $parser,
        Escaper $escaper,
        ?ExtensionRegistry $registry = null,
        ?TemplateLoaderInterface $templateLoader = null,
        ?SugarConfig $config = null,
    ) {
        $config = $config ?? new SugarConfig();
        $this->escaper = $escaper;

        // Use provided registry or create default with built-in directives
        $this->registry = $registry ?? $this->createDefaultRegistry();

        // Create passes
        $this->directivePairingPass = new DirectivePairingPass($this->registry);
        $this->directiveExtractionPass = new DirectiveExtractionPass(
            registry: $this->registry,
            config: $config,
        );
        $this->directiveCompilationPass = new DirectiveCompilationPass($this->registry);
        $this->contextPass = new ContextAnalysisPass();

        // Create template inheritance pass if loader is provided
        $this->templateInheritancePass = $templateLoader instanceof TemplateLoaderInterface
            ? new TemplateInheritancePass($templateLoader, $config)
            : null;

        // Create component expansion pass if loader is provided
        $this->componentExpansionPass = $templateLoader instanceof TemplateLoaderInterface
            ? new ComponentExpansionPass($templateLoader, $this->parser, $this->registry, $config)
            : null;
    }

    /**
     * Get the extension registry for framework customization
     *
     * Allows frameworks to register custom directives, components, etc.
     */
    public function getExtensionRegistry(): ExtensionRegistry
    {
        return $this->registry;
    }

    /**
     * Compile template source to executable PHP code
     *
     * @param string $source Template source code
     * @param string|null $templatePath Template path for inheritance resolution and debug info (default: null)
     * @param bool $debug Enable debug mode with inline source comments (default: false)
     * @param \Sugar\Cache\DependencyTracker|null $tracker Optional dependency tracker for cache metadata
     * @return string Compiled PHP code
     */
    public function compile(
        string $source,
        ?string $templatePath = null,
        bool $debug = false,
        ?DependencyTracker $tracker = null,
    ): string {
        // Create compilation context for error handling with snippets
        $context = new CompilationContext(
            $templatePath ?? 'inline-template',
            $source,
            $debug,
            $tracker,
        );

        // Step 1: Parse template source into AST
        $ast = $this->parser->parse($source);

        // Step 1.5: Process template inheritance if enabled
        if ($this->templateInheritancePass instanceof TemplateInheritancePass && $templatePath !== null) {
            $ast = $this->templateInheritancePass->execute($ast, $context);
        }

        // Step 1.75: Expand components (s-button → template content)
        if ($this->componentExpansionPass instanceof ComponentExpansionPass) {
            $ast = $this->componentExpansionPass->execute($ast, $context);
        }

        // Step 2: Extract directives from elements (s:if → DirectiveNode)
        $extractedAst = $this->directiveExtractionPass->execute($ast, $context);

        // Step 3: Wire up parent references and pair sibling directives
        $pairedAst = $this->directivePairingPass->execute($extractedAst, $context);

        // Step 4: Compile DirectiveNodes into PHP control structures
        $transformedAst = $this->directiveCompilationPass->execute($pairedAst, $context);

        // Step 5: Analyze context and update OutputNode contexts
        $analyzedAst = $this->contextPass->execute($transformedAst, $context);

        // Step 6: Generate executable PHP code with inline escaping
        $generator = new CodeGenerator($this->escaper, $context);

        return $generator->generate($analyzedAst);
    }

    /**
     * Create default registry with built-in directives
     */
    private function createDefaultRegistry(): ExtensionRegistry
    {
        $registry = new ExtensionRegistry();

        // Register built-in directives (lazy instantiation via class names)
        $registry->registerDirective('if', IfCompiler::class);
        $registry->registerDirective('elseif', IfCompiler::class);
        $registry->registerDirective('else', IfCompiler::class);
        $registry->registerDirective('unless', UnlessCompiler::class);
        $registry->registerDirective('isset', IssetCompiler::class);
        $registry->registerDirective('empty', EmptyCompiler::class);
        $registry->registerDirective('switch', SwitchCompiler::class);
        $registry->registerDirective('case', SwitchCompiler::class);
        $registry->registerDirective('default', SwitchCompiler::class);
        $registry->registerDirective('foreach', ForeachCompiler::class);
        $registry->registerDirective('forelse', ForelseCompiler::class);
        $registry->registerDirective('while', WhileCompiler::class);
        $registry->registerDirective('class', ClassCompiler::class);
        $registry->registerDirective('spread', SpreadCompiler::class);
        $registry->registerDirective('text', new ContentCompiler(escape: true));
        $registry->registerDirective('html', new ContentCompiler(escape: false, context: OutputContext::RAW));

        return $registry;
    }
}
