<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler;

use Sugar\Core\CodeGen\CodeGenerator;
use Sugar\Core\Parser\Parser;
use Sugar\Core\Pass\ContextAnalysisPass;

/**
 * Orchestrates template compilation pipeline
 *
 * Pipeline: Parser → ContextAnalysisPass → CodeGenerator
 */
final class Compiler implements CompilerInterface
{
    /**
     * Constructor
     *
     * @param \Sugar\Core\Parser\Parser $parser Template parser
     * @param \Sugar\Core\Pass\ContextAnalysisPass $contextPass Context analysis pass
     * @param \Sugar\Core\CodeGen\CodeGenerator $generator Code generator
     */
    public function __construct(
        private readonly Parser $parser,
        private readonly ContextAnalysisPass $contextPass,
        private readonly CodeGenerator $generator,
    ) {
    }

    /**
     * Compile template source to executable PHP code
     *
     * @param string $source Template source code
     * @return string Compiled PHP code
     */
    public function compile(string $source): string
    {
        // Step 1: Parse template source into AST
        $ast = $this->parser->parse($source);

        // Step 2: Analyze context and update OutputNode contexts
        $analyzedAst = $this->contextPass->analyze($ast);

        // Step 3: Generate executable PHP code with inline escaping
        return $this->generator->generate($analyzedAst);
    }
}
