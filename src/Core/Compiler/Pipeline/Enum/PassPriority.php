<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler\Pipeline\Enum;

/**
 * Semantic priorities for compiler pipeline passes.
 */
enum PassPriority: int
{
    case PRE_DIRECTIVE_EXTRACTION = 5;

    case DIRECTIVE_EXTRACTION = 10;

    case DIRECTIVE_PAIRING = 20;

    case DIRECTIVE_COMPILATION = 30;

    case POST_DIRECTIVE_COMPILATION = 35;

    case CONTEXT_ANALYSIS = 50;
}
