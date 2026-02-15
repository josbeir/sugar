<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler\Pipeline;

/**
 * Priority values for compiler passes.
 */
final class CompilerPassPriority
{
    public const TEMPLATE_INHERITANCE = 0;

    public const DIRECTIVE_EXTRACTION = 10;

    public const DIRECTIVE_PAIRING = 20;

    public const DIRECTIVE_COMPILATION = 30;

    public const CONTEXT_ANALYSIS = 50;
}
