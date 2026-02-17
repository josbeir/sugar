<?php
declare(strict_types=1);

namespace Sugar\Core\Directive\Enum;

/**
 * Merge behavior modes for attribute directives.
 *
 * These modes allow attribute directives to opt into custom normalization
 * behavior during extraction.
 */
enum AttributeMergeMode
{
    /**
     * Merge compiled attribute output into an existing named attribute.
     */
    case MERGE_NAMED;

    /**
     * Exclude explicit named attributes from a directive source payload.
     */
    case EXCLUDE_NAMED;
}
