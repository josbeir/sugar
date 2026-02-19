<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler\Pipeline\Enum;

/**
 * Semantic priorities for compiler pipeline passes.
 */
enum PassPriority: int
{
    case PRE_DIRECTIVE_EXTRACTION = 10;

    case DIRECTIVE_EXTRACTION = 20;

    case DIRECTIVE_PAIRING = 30;

    case DIRECTIVE_COMPILATION = 40;

    case INHERITANCE_COMPILATION = 50;

    case POST_DIRECTIVE_COMPILATION = 60;

    case PHP_NORMALIZATION = 70;

    case CONTEXT_ANALYSIS = 80;
}
