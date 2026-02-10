<?php
declare(strict_types=1);

namespace Sugar\Enum;

/**
 * Output context types for context-aware escaping
 */
enum OutputContext: string
{
    case HTML = 'html';
    case HTML_ATTRIBUTE = 'html_attr';
    case JAVASCRIPT = 'javascript';
    case JSON = 'json';
    case JSON_ATTRIBUTE = 'json_attr';
    case CSS = 'css';
    case URL = 'url';
    case RAW = 'raw';
}
