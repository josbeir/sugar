<?php
declare(strict_types=1);

namespace Sugar\Core\Parser;

/**
 * Enum of all token types emitted by the template lexer.
 *
 * Tokens are grouped by the lexer state that produces them:
 *  - Html*  tokens come from scanning HTML markup
 *  - Php*   tokens come from PHP open/close tag transitions
 *  - RawBody is emitted for content inside s:raw regions
 *  - Eof signals end-of-input
 */
enum TokenType: string
{
    /** Plain text between HTML tags or outside any tag */
    case Text = 'Text';

    /** The `<` that opens an HTML tag (including `</`) */
    case TagOpen = 'TagOpen';

    /** The tag name after `<` (e.g. "div", "s-button") */
    case TagName = 'TagName';

    /** A `/` immediately after `<` indicating a closing tag */
    case Slash = 'Slash';

    /** An HTML attribute name (e.g. "class", "s:if") */
    case AttributeName = 'AttributeName';

    /** The `=` between attribute name and value */
    case Equals = 'Equals';

    /** The opening quote character `"` or `'` of an attribute value */
    case QuoteOpen = 'QuoteOpen';

    /** Static text inside an attribute value (between quotes) */
    case AttributeText = 'AttributeText';

    /** The closing quote character matching the opening one */
    case QuoteClose = 'QuoteClose';

    /** An unquoted attribute value */
    case AttributeValueUnquoted = 'AttributeValueUnquoted';

    /** The `>` or `/>` that closes an HTML tag */
    case TagClose = 'TagClose';

    /** The `<?=` PHP short echo open tag */
    case PhpOutputOpen = 'PhpOutputOpen';

    /** The expression text inside a short echo block */
    case PhpExpression = 'PhpExpression';

    /** The `<?php` PHP open tag */
    case PhpBlockOpen = 'PhpBlockOpen';

    /** The code text inside a PHP block */
    case PhpCode = 'PhpCode';

    /** The PHP close tag */
    case PhpClose = 'PhpClose';

    /** Raw body content inside an s:raw region (preserved verbatim) */
    case RawBody = 'RawBody';

    /** HTML comment: <!-- ... --> */
    case Comment = 'Comment';

    /** DOCTYPE, CDATA, or processing instruction */
    case SpecialTag = 'SpecialTag';

    /** End-of-file sentinel */
    case Eof = 'Eof';
}
