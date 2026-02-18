<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler;

use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Cache\DependencyTracker;
use Sugar\Core\Compiler\CodeGen\CodeGenerator;
use Sugar\Core\Compiler\Pipeline\CompilerPipelineFactory;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Extension\DirectiveRegistryInterface;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser as TemplateParser;
use Sugar\Core\Template\TemplateComposer;

/**
 * Orchestrates template compilation pipeline.
 *
 * Pipeline: Parser -> Template composition (optional) -> DirectiveExtractionPass
 * -> DirectivePairingPass -> DirectiveCompilationPass -> ContextAnalysisPass -> CodeGenerator
 */
final class Compiler implements CompilerInterface
{
    private readonly DirectiveRegistryInterface $registry;

    private readonly Escaper $escaper;

    private readonly TemplateLoaderInterface $templateLoader;

    private readonly CompilerPipelineFactory $pipelineFactory;

    private readonly TemplateComposer $templateComposer;

    private readonly PhpSyntaxValidator $phpSyntaxValidator;

    private readonly bool $phpSyntaxValidationEnabled;

    /**
     * @param \Sugar\Core\Parser\Parser $parser Template parser
     * @param \Sugar\Core\Escape\Escaper $escaper Escaper for code generation
     * @param \Sugar\Core\Extension\DirectiveRegistryInterface $registry Directive registry with registered compilers
     * @param \Sugar\Core\Loader\TemplateLoaderInterface $templateLoader Template loader for inheritance
     * @param \Sugar\Core\Config\SugarConfig|null $config Configuration (optional, creates default if null)
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $customPasses Custom compiler passes with priorities
     * @param bool $phpSyntaxValidationEnabled Enable nikic/php-parser syntax validation when available
     */
    public function __construct(
        private readonly TemplateParser $parser,
        Escaper $escaper,
        DirectiveRegistryInterface $registry,
        TemplateLoaderInterface $templateLoader,
        ?SugarConfig $config = null,
        array $customPasses = [],
        bool $phpSyntaxValidationEnabled = false,
    ) {
        $config = $config ?? new SugarConfig();
        $this->escaper = $escaper;
        $this->registry = $registry;
        $this->templateLoader = $templateLoader;
        $this->phpSyntaxValidationEnabled = $phpSyntaxValidationEnabled;
        $this->phpSyntaxValidator = new PhpSyntaxValidator();
        $this->pipelineFactory = new CompilerPipelineFactory(
            $this->registry,
            $config,
            $customPasses,
        );
        $this->templateComposer = new TemplateComposer(
            $this->templateLoader,
            $this->parser,
            $this->registry,
            $config,
        );
    }

    /**
     * Compile template source to executable PHP code.
     *
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $inlinePasses Additional per-compilation passes
     */
    public function compile(
        string $source,
        ?string $templatePath = null,
        bool $debug = false,
        ?DependencyTracker $tracker = null,
        ?array $blocks = null,
        array $inlinePasses = [],
    ): string {
        $context = $this->createContext(
            $templatePath ?? 'inline-template',
            $source,
            $debug,
            $tracker,
            $blocks,
        );

        $ast = $this->parser->parse($source);
        $context->stampTemplatePath($ast);

        return $this->compileAst(
            $ast,
            $context,
            $templatePath !== null,
            $inlinePasses,
        );
    }

    /**
     * @param array<string>|null $blocks
     */
    private function createContext(
        string $templatePath,
        string $source,
        bool $debug,
        ?DependencyTracker $tracker,
        ?array $blocks = null,
    ): CompilationContext {
        return new CompilationContext(
            $templatePath,
            $source,
            $debug,
            $tracker,
            $blocks,
        );
    }

    /**
     * Execute the middleware pipeline and generate PHP code.
     *
     * @param array<array{pass: \Sugar\Core\Compiler\Pipeline\AstPassInterface, priority: \Sugar\Core\Compiler\Pipeline\Enum\PassPriority}> $inlinePasses
     */
    private function compileAst(
        DocumentNode $ast,
        CompilationContext $context,
        bool $enableInheritance,
        array $inlinePasses = [],
    ): string {
        if ($enableInheritance) {
            $ast = $this->templateComposer->compose($ast, $context);
        }

        if ($this->phpSyntaxValidationEnabled) {
            $this->phpSyntaxValidator->templateSegments($ast, $context);
        }

        $pipeline = $this->pipelineFactory->buildCompilerPipeline(inlinePasses: $inlinePasses);
        $analyzedAst = $pipeline->execute($ast, $context);

        $generator = new CodeGenerator($this->escaper, $context);
        $compiledCode = $generator->generate($analyzedAst);
        if ($this->phpSyntaxValidationEnabled) {
            $this->phpSyntaxValidator->generated($compiledCode, $context);
        }

        return $compiledCode;
    }
}
