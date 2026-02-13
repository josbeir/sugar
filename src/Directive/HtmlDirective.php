<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Sugar\Enum\OutputContext;

/**
 * Compiler for the s:html directive.
 *
 * This directive renders trusted raw HTML output without escaping.
 */
readonly class HtmlDirective extends ContentDirective
{
    /**
     * Configure s:html output to bypass escaping in raw context.
     */
    public function __construct()
    {
        parent::__construct(escape: false, context: OutputContext::RAW);
    }
}
