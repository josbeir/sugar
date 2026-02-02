<?php
declare(strict_types=1);

namespace Sugar;

use Sugar\CodeGen\CodeGenerator;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\Pass\DirectivePass;

/**
 * Orchestrates template compilation pipeline
 *
 * Pipeline: Parser → DirectivePass → ContextAnalysisPass → CodeGenerator
 */
final class Compiler implements CompilerInterface
{
    /**
     * Constructor
     *
     * @param \Sugar\Parser\Parser $parser Template parser
     * @param \Sugar\Pass\DirectivePass $directivePass Directive transformation pass
     * @param \Sugar\Pass\ContextAnalysisPass $contextPass Context analysis pass
     * @param \Sugar\CodeGen\CodeGenerator $generator Code generator
     */
    public function __construct(
        private readonly Parser $parser,
        private readonly DirectivePass $directivePass,
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

        // Step 2: Transform directives (s:if, s:foreach, etc.) into PHP control structures
        $transformedAst = $this->directivePass->transform($ast);

        // Step 3: Analyze context and update OutputNode contexts
        $analyzedAst = $this->contextPass->analyze($transformedAst);

        // Step 4: Generate executable PHP code with inline escaping
        return $this->generator->generate($analyzedAst);
    }
}
