<?php
declare(strict_types=1);

namespace Sugar\Core\Exception;

use ParseError;

/**
 * Exception thrown during template compilation
 */
class CompilationException extends SugarException
{
    /**
     * Create a compilation exception from a compiled template parse error.
     */
    public static function fromCompiledTemplateParseError(string $compiledPath, ParseError $parseError): self
    {
        return (new self(
            message: sprintf('Compiled template contains invalid PHP: %s', $parseError->getMessage()),
            previous: $parseError,
        ))->withLocation($compiledPath, $parseError->getLine());
    }

    /**
     * Create a compilation exception from a compiled component parse error.
     */
    public static function fromCompiledComponentParseError(string $compiledPath, ParseError $parseError): self
    {
        return (new self(
            message: sprintf('Compiled component contains invalid PHP: %s', $parseError->getMessage()),
            previous: $parseError,
        ))->withLocation($compiledPath, $parseError->getLine());
    }
}
