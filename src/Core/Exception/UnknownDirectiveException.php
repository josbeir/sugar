<?php
declare(strict_types=1);

namespace Sugar\Core\Exception;

/**
 * Exception thrown when an unknown directive is encountered
 */
class UnknownDirectiveException extends CompilationException
{
    /**
     * Create an exception for an unknown directive name.
     *
     * @param string $directiveName The unknown directive name
     * @param string|null $suggestion Optional suggestion for correct directive
     */
    public static function create(string $directiveName, ?string $suggestion = null): self
    {
        $message = sprintf('Unknown directive "%s"', $directiveName);

        if ($suggestion !== null) {
            $message .= sprintf('. Did you mean "%s"?', $suggestion);
        }

        return new self($message);
    }
}
