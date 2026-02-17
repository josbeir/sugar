<?php
declare(strict_types=1);

namespace Sugar\Core\Template\Enum;

/**
 * Block merge mode used during template inheritance.
 */
enum BlockMergeMode
{
    case REPLACE;
    case APPEND;
    case PREPEND;
}
