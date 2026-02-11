<?php
declare(strict_types=1);

namespace Sugar\Exception;

/**
 * Exception thrown when an unknown directive is encountered
 */
class UnknownDirectiveException extends CompilationException
{
    /**
     * Constructor
     *
     * @param string $directiveName The unknown directive name
     * @param string|null $suggestion Optional suggestion for correct directive
     * @param string|null $templatePath Path to template file
     * @param int|null $templateLine Line number in template
     * @param int|null $templateColumn Column number in template
     */
    public function __construct(
        string $directiveName,
        ?string $suggestion = null,
        ?string $templatePath = null,
        ?int $templateLine = null,
        ?int $templateColumn = null,
    ) {
        $message = sprintf('Unknown directive "%s"', $directiveName);

        if ($suggestion !== null) {
            $message .= sprintf('. Did you mean "%s"?', $suggestion);
        }

        parent::__construct(
            message: $message,
            templatePath: $templatePath,
            templateLine: $templateLine,
            templateColumn: $templateColumn,
        );
    }
}
