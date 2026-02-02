<?php
declare(strict_types=1);

namespace Sugar;

use Sugar\CodeGen\CodeGenerator;
use Sugar\Directive\ClassCompiler;
use Sugar\Directive\EmptyCompiler;
use Sugar\Directive\ForeachCompiler;
use Sugar\Directive\ForelseCompiler;
use Sugar\Directive\IfCompiler;
use Sugar\Directive\IssetCompiler;
use Sugar\Directive\SpreadCompiler;
use Sugar\Directive\SwitchCompiler;
use Sugar\Directive\UnlessCompiler;
use Sugar\Directive\WhileCompiler;
use Sugar\Escape\Escaper;
use Sugar\Extension\ExtensionRegistry;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\Pass\Directive\DirectiveCompilationPass;
use Sugar\Pass\Directive\DirectiveExtractionPass;

/**
 * Orchestrates template compilation pipeline
 *
 * Pipeline: Parser → DirectiveExtractionPass → DirectiveCompilationPass → ContextAnalysisPass → CodeGenerator
 */
final class Compiler implements CompilerInterface
{
    private readonly ExtensionRegistry $registry;

    private readonly DirectiveExtractionPass $directiveExtractionPass;

    private readonly DirectiveCompilationPass $directiveCompilationPass;

    private readonly Escaper $escaper;

    /**
     * Constructor
     *
     * @param \Sugar\Parser\Parser $parser Template parser
     * @param \Sugar\Pass\ContextAnalysisPass $contextPass Context analysis pass
     * @param \Sugar\Escape\Escaper $escaper Escaper for code generation
     * @param \Sugar\Extension\ExtensionRegistry|null $registry Extension registry (optional, creates default if null)
     */
    public function __construct(
        private readonly Parser $parser,
        private readonly ContextAnalysisPass $contextPass,
        Escaper $escaper,
        ?ExtensionRegistry $registry = null,
    ) {
        $this->escaper = $escaper;

        // Use provided registry or create default with built-in directives
        $this->registry = $registry ?? $this->createDefaultRegistry();

        // Create passes
        $this->directiveExtractionPass = new DirectiveExtractionPass();
        $this->directiveCompilationPass = new DirectiveCompilationPass($this->registry);
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
     * @param bool $debug Enable debug mode with inline source comments (default: false)
     * @param string|null $sourceFile Original source file path for debug info (default: null)
     * @return string Compiled PHP code
     */
    public function compile(string $source, bool $debug = false, ?string $sourceFile = null): string
    {
        // Step 1: Parse template source into AST
        $ast = $this->parser->parse($source);

        // Step 2: Extract directives from elements (s:if → DirectiveNode)
        $extractedAst = $this->directiveExtractionPass->transform($ast);

        // Step 3: Compile DirectiveNodes into PHP control structures
        $transformedAst = $this->directiveCompilationPass->transform($extractedAst);

        // Step 4: Analyze context and update OutputNode contexts
        $analyzedAst = $this->contextPass->analyze($transformedAst);

        // Step 5: Generate executable PHP code with inline escaping
        $generator = new CodeGenerator($this->escaper, $debug, $sourceFile);

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
        $registry->registerDirective('none', ForelseCompiler::class);
        $registry->registerDirective('while', WhileCompiler::class);
        $registry->registerDirective('class', ClassCompiler::class);
        $registry->registerDirective('spread', SpreadCompiler::class);

        return $registry;
    }
}
