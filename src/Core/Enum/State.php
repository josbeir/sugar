<?php
declare(strict_types=1);

namespace Sugar\Core\Enum;

/**
 * Parser states for context detection
 */
enum State: string
{
    case HTML_CONTENT = 'html_content';
    case IN_TAG = 'in_tag';
    case IN_ATTRIBUTE = 'in_attribute';
    case IN_ATTRIBUTE_VALUE = 'in_attribute_value';
    case IN_SCRIPT_TAG = 'in_script_tag';
    case IN_STYLE_TAG = 'in_style_tag';
    case IN_STRING_SINGLE = 'in_string_single';
    case IN_STRING_DOUBLE = 'in_string_double';
}
