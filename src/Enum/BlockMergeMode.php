<?php
declare(strict_types=1);

namespace Sugar\Enum;

/**
 * Block merge mode used during template inheritance.
 */
enum BlockMergeMode
{
    case REPLACE;
    case APPEND;
    case PREPEND;
}
