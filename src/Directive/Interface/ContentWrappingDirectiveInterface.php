<?php
declare(strict_types=1);

namespace Sugar\Directive\Interface;

/**
 * Interface for directives that influence content wrapping behavior.
 *
 * Implementations can signal whether content directives should wrap
 * the original element or render without the wrapper.
 */
interface ContentWrappingDirectiveInterface extends DirectiveInterface
{
    /**
     * Whether content directives should wrap the original element.
     */
    public function shouldWrapContentElement(): bool;
}
